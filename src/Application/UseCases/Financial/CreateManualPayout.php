<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Financial;

use LimpVix\Domain\Finance\PayoutRepositoryInterface;
use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

/**
 * Create Manual Payout Use Case (GAP C)
 *
 * Permite admin criar payouts manuais para:
 * - Bonificações
 * - Correções de valores
 * - Ajustes excepcionais
 * - Pagamentos ad-hoc
 *
 * BUSINESS RULES:
 * - Admin precisa fornecer motivo obrigatório
 * - Amount deve ser > 0
 * - Professional deve existir e estar ativo
 * - Status inicial: 'manual_pending' (aguarda aprovação 4-eyes)
 * - Criador ≠ Aprovador (4-eyes policy)
 *
 * AUDIT TRAIL:
 * - Registra quem criou, quando, por quê
 * - Salva em wp_limpvix_payout_audit_trail
 *
 * @package LimpVix\Application\UseCases\Financial
 * @since 0.9.0 (GAP C)
 */
final class CreateManualPayout
{
    public function __construct(
        private PayoutRepositoryInterface $payoutRepository,
        private WpMarketplaceProfessionalRepository $professionalRepository
    ) {}

    /**
     * Execute manual payout creation
     *
     * @param CreateManualPayoutCommand $command
     * @return array{success: bool, payout_id?: int, error?: string}
     */
    public function execute(CreateManualPayoutCommand $command): array
    {
        // 1. Validate command
        $validation = $this->validateCommand($command);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error'],
            ];
        }

        // 2. Validate professional exists and is active
        $professional = $this->professionalRepository->findById($command->professional_id);

        if (!$professional) {
            return [
                'success' => false,
                'error' => 'Profissional não encontrado (ID: ' . $command->professional_id . ')',
            ];
        }

        if (!$professional['is_active']) {
            return [
                'success' => false,
                'error' => 'Profissional está inativo. Não é possível criar payout.',
            ];
        }

        // 3. Get professional recipient info
        $recipient = $this->getRecipientInfo($professional);

        if (!$recipient['valid']) {
            return [
                'success' => false,
                'error' => 'Profissional não tem dados de pagamento configurados (PIX ou conta bancária)',
            ];
        }

        // 4. Calculate amounts
        $gross_amount = $command->amount;
        $platform_fee = $command->deduct_fee ? $this->calculatePlatformFee($gross_amount) : 0.0;
        $net_amount = $gross_amount - $platform_fee;

        // 5. Create payout record
        $payout_data = [
            'professional_id' => $command->professional_id,
            'order_id' => null, // Manual payouts não são vinculados a order
            'gross_amount' => $gross_amount,
            'platform_fee' => $platform_fee,
            'net_amount' => $net_amount,
            'status' => 'manual_pending', // Aguarda aprovação 4-eyes
            'gateway' => 'mercadopago',
            'recipient_type' => $recipient['type'],
            'recipient_key' => $recipient['key'],
            'recipient_name' => $recipient['name'],
            'recipient_document' => $recipient['document'],
            'is_manual' => true,
            'manual_reason' => $command->reason,
            'created_by' => $command->created_by,
            'requires_approval' => $this->requiresFourEyesApproval($gross_amount),
            'created_at' => current_time('mysql'),
        ];

        $payout_id = $this->payoutRepository->create($payout_data);

        if (!$payout_id) {
            return [
                'success' => false,
                'error' => 'Erro ao criar registro de payout no banco de dados',
            ];
        }

        // 6. Create audit trail entry
        $this->createAuditTrail($payout_id, 'created', $command->created_by, $command->reason);

        // 7. Send notification to approver (if requires approval)
        if ($payout_data['requires_approval']) {
            $this->notifyApprover($payout_id, $command->created_by);
        }

        return [
            'success' => true,
            'payout_id' => $payout_id,
            'requires_approval' => $payout_data['requires_approval'],
            'net_amount' => $net_amount,
        ];
    }

    /**
     * Validate command
     */
    private function validateCommand(CreateManualPayoutCommand $command): array
    {
        // Amount must be positive
        if ($command->amount <= 0) {
            return ['valid' => false, 'error' => 'Valor deve ser maior que zero'];
        }

        // Reason is mandatory
        if (empty(trim($command->reason))) {
            return ['valid' => false, 'error' => 'Motivo é obrigatório para payouts manuais'];
        }

        // Reason must be descriptive (min 10 chars)
        if (strlen(trim($command->reason)) < 10) {
            return ['valid' => false, 'error' => 'Motivo deve ter pelo menos 10 caracteres'];
        }

        // Professional ID required
        if ($command->professional_id <= 0) {
            return ['valid' => false, 'error' => 'ID do profissional inválido'];
        }

        // Created by (admin) required
        if ($command->created_by <= 0) {
            return ['valid' => false, 'error' => 'ID do admin criador inválido'];
        }

        return ['valid' => true];
    }

    /**
     * Get recipient info from professional
     */
    private function getRecipientInfo(array $professional): array
    {
        // Priority 1: PIX key
        if (!empty($professional['pix_key'])) {
            return [
                'valid' => true,
                'type' => 'pix',
                'key' => $professional['pix_key'],
                'name' => $professional['full_name'],
                'document' => $professional['cpf'] ?? '',
            ];
        }

        // Priority 2: Bank account
        if (!empty($professional['bank_account'])) {
            $bank_data = is_string($professional['bank_account'])
                ? json_decode($professional['bank_account'], true)
                : $professional['bank_account'];

            if (!empty($bank_data['account_number'])) {
                return [
                    'valid' => true,
                    'type' => 'bank_account',
                    'key' => json_encode($bank_data),
                    'name' => $professional['full_name'],
                    'document' => $professional['cpf'] ?? '',
                ];
            }
        }

        return ['valid' => false];
    }

    /**
     * Calculate platform fee (10% default)
     */
    private function calculatePlatformFee(float $gross_amount): float
    {
        $fee_percentage = (float) get_option('limpvix_platform_fee_percentage', 10.0);
        return round($gross_amount * ($fee_percentage / 100), 2);
    }

    /**
     * Check if requires 4-eyes approval
     *
     * POLICY:
     * - Valores > R$ 500: Requer aprovação
     * - Valores ≤ R$ 500: Auto-aprovado (move to 'approved' immediately)
     */
    private function requiresFourEyesApproval(float $amount): bool
    {
        $threshold = (float) get_option('limpvix_manual_payout_approval_threshold', 500.0);
        return $amount > $threshold;
    }

    /**
     * Create audit trail entry
     */
    private function createAuditTrail(
        int $payout_id,
        string $action,
        int $performed_by,
        ?string $reason = null
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_payout_audit_trail';

        $wpdb->insert(
            $table,
            [
                'payout_id' => $payout_id,
                'action' => $action,
                'performed_by' => $performed_by,
                'reason' => $reason,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Notify approver about pending manual payout
     */
    private function notifyApprover(int $payout_id, int $created_by): void
    {
        // Get admin emails (exclude creator)
        $admin_users = get_users(['role' => 'administrator']);

        $creator = get_userdata($created_by);
        $payout = $this->payoutRepository->getById($payout_id);

        $subject = '[LimpVix] Payout Manual Aguardando Aprovação';
        $message = sprintf(
            "Um payout manual foi criado e aguarda aprovação:\n\n" .
            "ID: #%d\n" .
            "Valor: R$ %.2f\n" .
            "Profissional ID: %d\n" .
            "Criado por: %s\n" .
            "Motivo: %s\n\n" .
            "Acesse o painel para aprovar ou rejeitar:\n%s",
            $payout_id,
            $payout['net_amount'],
            $payout['professional_id'],
            $creator->display_name,
            $payout['manual_reason'],
            admin_url('admin.php?page=limpvix-payouts&filter=manual_pending')
        );

        foreach ($admin_users as $admin) {
            if ($admin->ID === $created_by) {
                continue; // Skip creator
            }

            wp_mail($admin->user_email, $subject, $message);
        }
    }
}

/**
 * Command DTO for CreateManualPayout
 */
final class CreateManualPayoutCommand
{
    public function __construct(
        public readonly int $professional_id,
        public readonly float $amount,
        public readonly string $reason,
        public readonly int $created_by,
        public readonly bool $deduct_fee = true
    ) {}
}
