<?php
/**
 * AdminNotificationService
 *
 * Centraliza notificações para admin sobre eventos críticos do sistema.
 *
 * RESPONSABILIDADES:
 * - Notificar sobre score baixo de profissionais
 * - Alertar sobre no-shows
 * - Recomendar realocações
 * - Alertar quando não há alternativas disponíveis
 *
 * CANAIS:
 * - WordPress do_action (para dashboard badges/alerts)
 * - Email (wp_mail para admin)
 * - Log (error_log para auditoria)
 *
 * @package LimpVix\Application\Services
 * @since 0.9.0
 */

namespace LimpVix\Application\Services;

use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;

defined('ABSPATH') || exit;

final class AdminNotificationService
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private ProfessionalRepositoryInterface $professionalRepository
    ) {}

    /**
     * Notifica admin sobre score baixo de profissional
     *
     * @param int $professionalId Professional ID
     * @param float $newScore New score value
     * @param string $reason Reason for score drop
     * @return void
     */
    public function notifyLowScore(
        int $professionalId,
        float $newScore,
        string $reason
    ): void {
        try {
            $professional = $this->professionalRepository->findById($professionalId);

            if (!$professional) {
                error_log("[LimpVix] AdminNotification: Professional #{$professionalId} not found");
                return;
            }

            // Dados para notificação
            $data = [
                'type' => 'professional_low_score',
                'title' => 'Profissional com Score Crítico',
                'message' => sprintf(
                    'Profissional %s (#%d) está com score %.2f (crítico). Motivo: %s',
                    $professional->getName(),
                    $professionalId,
                    $newScore,
                    $this->getReasonLabel($reason)
                ),
                'professional_id' => $professionalId,
                'professional_name' => $professional->getName(),
                'score' => $newScore,
                'reason' => $reason,
                'priority' => 'high',
                'action_url' => admin_url("admin.php?page=limpvix-professionals&action=view&id={$professionalId}"),
                'timestamp' => current_time('mysql'),
            ];

            // Disparar hook WordPress (para dashboard badges)
            do_action('limpvix_admin_notification', $data);

            // Email para admin
            $this->sendAdminEmail($data);

            error_log("[LimpVix] Admin notified: Low score for professional #{$professionalId} (score: {$newScore})");

        } catch (\Exception $e) {
            error_log('[LimpVix] AdminNotificationService::notifyLowScore error: ' . $e->getMessage());
        }
    }

    /**
     * Recomenda realocação de contrato
     *
     * @param int $contractId Contract ID
     * @param int $professionalId Current professional ID
     * @param string $reason Reallocation reason
     * @param array $context Additional context data
     * @return void
     */
    public function recommendReallocation(
        int $contractId,
        int $professionalId,
        string $reason,
        array $context = []
    ): void {
        try {
            $contract = $this->contractRepository->findById($contractId);
            $professional = $this->professionalRepository->findById($professionalId);

            if (!$contract || !$professional) {
                error_log("[LimpVix] AdminNotification: Contract #{$contractId} or Professional #{$professionalId} not found");
                return;
            }

            $data = [
                'type' => 'reallocation_recommended',
                'title' => 'Realocação Recomendada',
                'message' => sprintf(
                    'Contrato %s necessita realocação. Profissional atual: %s. Motivo: %s',
                    $contract->getContractNumber(),
                    $professional->getName(),
                    $this->getReasonLabel($reason)
                ),
                'contract_id' => $contractId,
                'contract_number' => $contract->getContractNumber(),
                'professional_id' => $professionalId,
                'professional_name' => $professional->getName(),
                'reason' => $reason,
                'context' => $context,
                'priority' => $this->getPriority($reason),
                'action_url' => admin_url("admin.php?page=limpvix-contracts&action=reallocate&id={$contractId}"),
                'timestamp' => current_time('mysql'),
            ];

            do_action('limpvix_admin_notification', $data);
            $this->sendAdminEmail($data);

            error_log(sprintf(
                '[LimpVix] Admin notified: Reallocation recommended for Contract %s (reason: %s)',
                $contract->getContractNumber(),
                $reason
            ));

        } catch (\Exception $e) {
            error_log('[LimpVix] AdminNotificationService::recommendReallocation error: ' . $e->getMessage());
        }
    }

    /**
     * Alerta sobre no-show
     *
     * @param int $executionId Execution ID
     * @param int $contractId Contract ID
     * @param int $professionalId Professional ID
     * @return void
     */
    public function alertNoShow(
        int $executionId,
        int $contractId,
        int $professionalId
    ): void {
        try {
            $contract = $this->contractRepository->findById($contractId);
            $professional = $this->professionalRepository->findById($professionalId);

            if (!$contract || !$professional) {
                return;
            }

            $data = [
                'type' => 'execution_no_show',
                'title' => 'No-Show Detectado',
                'message' => sprintf(
                    'Profissional %s não compareceu à execução #%d do contrato %s',
                    $professional->getName(),
                    $executionId,
                    $contract->getContractNumber()
                ),
                'execution_id' => $executionId,
                'contract_id' => $contractId,
                'contract_number' => $contract->getContractNumber(),
                'professional_id' => $professionalId,
                'professional_name' => $professional->getName(),
                'priority' => 'high',
                'action_url' => admin_url("admin.php?page=limpvix-executions&action=view&id={$executionId}"),
                'timestamp' => current_time('mysql'),
            ];

            do_action('limpvix_admin_notification', $data);
            $this->sendAdminEmail($data);

            error_log("[LimpVix] Admin notified: No-show for execution #{$executionId}");

        } catch (\Exception $e) {
            error_log('[LimpVix] AdminNotificationService::alertNoShow error: ' . $e->getMessage());
        }
    }

    /**
     * Alerta quando não há profissionais alternativos disponíveis
     *
     * @param int $contractId Contract ID
     * @param int $professionalId Current professional ID
     * @param string $reason Reason for needing alternatives
     * @return void
     */
    public function alertNoAlternatives(
        int $contractId,
        int $professionalId,
        string $reason
    ): void {
        try {
            $contract = $this->contractRepository->findById($contractId);
            $professional = $this->professionalRepository->findById($professionalId);

            if (!$contract || !$professional) {
                return;
            }

            $data = [
                'type' => 'no_alternatives_available',
                'title' => 'Sem Profissionais Alternativos',
                'message' => sprintf(
                    'Contrato %s necessita realocação mas não há profissionais alternativos disponíveis. Motivo: %s',
                    $contract->getContractNumber(),
                    $this->getReasonLabel($reason)
                ),
                'contract_id' => $contractId,
                'contract_number' => $contract->getContractNumber(),
                'professional_id' => $professionalId,
                'professional_name' => $professional->getName(),
                'reason' => $reason,
                'priority' => 'critical',
                'action_url' => admin_url("admin.php?page=limpvix-contracts&action=view&id={$contractId}"),
                'timestamp' => current_time('mysql'),
            ];

            do_action('limpvix_admin_notification', $data);
            $this->sendAdminEmail($data);

            error_log(sprintf(
                '[LimpVix] Admin alerted: No alternatives for Contract %s (reason: %s)',
                $contract->getContractNumber(),
                $reason
            ));

        } catch (\Exception $e) {
            error_log('[LimpVix] AdminNotificationService::alertNoAlternatives error: ' . $e->getMessage());
        }
    }

    /**
     * Recomenda renovação de contrato com profissional diferente
     *
     * @param int $contractId Contract ID
     * @param int $professionalId Current professional ID
     * @param array $context Context data (score, alternatives, etc.)
     * @return void
     */
    public function recommendRenewalWithDifferentProfessional(
        int $contractId,
        int $professionalId,
        array $context = []
    ): void {
        try {
            $contract = $this->contractRepository->findById($contractId);
            $professional = $this->professionalRepository->findById($professionalId);

            if (!$contract || !$professional) {
                return;
            }

            $data = [
                'type' => 'renewal_different_professional',
                'title' => 'Renovação: Considerar Profissional Diferente',
                'message' => sprintf(
                    'Contrato %s expira em breve. Performance do profissional atual (%s) está abaixo da média (score: %.2f). Considere renovar com outro profissional.',
                    $contract->getContractNumber(),
                    $professional->getName(),
                    $context['current_score'] ?? 0
                ),
                'contract_id' => $contractId,
                'contract_number' => $contract->getContractNumber(),
                'professional_id' => $professionalId,
                'professional_name' => $professional->getName(),
                'context' => $context,
                'priority' => 'medium',
                'action_url' => admin_url("admin.php?page=limpvix-contracts&action=renew&id={$contractId}"),
                'timestamp' => current_time('mysql'),
            ];

            do_action('limpvix_admin_notification', $data);
            $this->sendAdminEmail($data);

            error_log(sprintf(
                '[LimpVix] Admin notified: Renewal recommendation for Contract %s',
                $contract->getContractNumber()
            ));

        } catch (\Exception $e) {
            error_log('[LimpVix] AdminNotificationService::recommendRenewalWithDifferentProfessional error: ' . $e->getMessage());
        }
    }

    /**
     * Envia email para admin
     *
     * @param array $data Notification data
     * @return void
     */
    private function sendAdminEmail(array $data): void
    {
        $adminEmail = get_option('admin_email');

        if (!$adminEmail) {
            error_log('[LimpVix] AdminNotificationService: Admin email not configured');
            return;
        }

        $subject = '[LimpVix] ' . $data['title'];

        $message = $data['message'] . "\n\n";
        $message .= "Prioridade: " . strtoupper($data['priority']) . "\n";
        $message .= "Data: " . $data['timestamp'] . "\n\n";
        $message .= "Ação necessária: " . $data['action_url'] . "\n";

        // Additional context if available
        if (!empty($data['context'])) {
            $message .= "\nDetalhes adicionais:\n";
            foreach ($data['context'] as $key => $value) {
                $message .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
            }
        }

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        wp_mail($adminEmail, $subject, $message, $headers);
    }

    /**
     * Obtém label legível para o motivo
     *
     * @param string $reason Reason code
     * @return string Human-readable label
     */
    private function getReasonLabel(string $reason): string
    {
        $labels = [
            'no_show' => 'No-show do profissional',
            'low_score' => 'Score abaixo do limite',
            'critical_low_score' => 'Score crítico',
            'score_threshold' => 'Score atingiu limite crítico',
            'poor_feedback' => 'Feedback negativo',
            'consecutive_poor_feedback' => 'Feedbacks negativos consecutivos',
            'professional_suspended' => 'Profissional suspenso',
            'suspension' => 'Suspensão',
            'client_request' => 'Solicitação do cliente',
            'performance_issue' => 'Problema de desempenho',
            'unavailable' => 'Profissional indisponível',
        ];

        return $labels[$reason] ?? ucfirst(str_replace('_', ' ', $reason));
    }

    /**
     * Determina prioridade baseada no motivo
     *
     * @param string $reason Reason code
     * @return string Priority level (critical|high|medium|low)
     */
    private function getPriority(string $reason): string
    {
        $highPriority = [
            'no_show',
            'critical_low_score',
            'professional_suspended',
            'consecutive_poor_feedback',
        ];

        $mediumPriority = [
            'low_score',
            'score_threshold',
            'poor_feedback',
        ];

        if (in_array($reason, $highPriority, true)) {
            return 'high';
        }

        if (in_array($reason, $mediumPriority, true)) {
            return 'medium';
        }

        return 'low';
    }
}
