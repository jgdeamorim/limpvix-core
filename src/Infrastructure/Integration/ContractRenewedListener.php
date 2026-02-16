<?php
/**
 * ContractRenewedListener - Event Listener for Contract Auto-Renewal
 *
 * RESPONSABILIDADE:
 * - Escutar evento limpvix_contract_renewed
 * - Enviar email de confirmação para cliente
 * - Enviar notificação para profissional (opcional)
 * - Registrar log de auditoria
 *
 * TRIGGER:
 * - Contract::renewWithPayment() dispara ContractRenewed event
 * - Event é convertido em WordPress action hook
 * - Este listener responde ao hook
 *
 * EMAIL TEMPLATE:
 * - Subject: "Contrato Renovado com Sucesso - LimpVix"
 * - Body: Confirmação de renovação com detalhes do pagamento
 * - Inclui: novo end_date, valor pago, próxima cobrança
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Domain\Contract\Events\ContractRenewed;

defined('ABSPATH') || exit;

final class ContractRenewedListener
{
    /**
     * Handle ContractRenewed event
     *
     * @param ContractRenewed $event
     * @return void
     */
    public static function handle(ContractRenewed $event): void
    {
        try {
            // Log renewal
            self::logRenewal($event);

            // Send confirmation email to customer
            self::sendCustomerConfirmation($event);

            // Send notification to professional (optional)
            self::notifyProfessional($event);

        } catch (\Exception $e) {
            error_log(sprintf(
                '[LimpVix] ContractRenewedListener error: %s',
                $e->getMessage()
            ));
        }
    }

    /**
     * Log contract renewal for audit trail
     *
     * @param ContractRenewed $event
     * @return void
     */
    private static function logRenewal(ContractRenewed $event): void
    {
        $contract = $event->getContract();
        $payment = $event->getPayment();

        error_log(sprintf(
            '[LimpVix] CONTRACT RENEWED: Contract #%d renewed via payment %s. ' .
            'Billing cycle: %d, Amount: R$ %.2f, New end_date: %s',
            $contract->getId()->toInt(),
            $payment->getPaymentUuid(),
            $payment->getBillingCycleNumber(),
            $payment->getAmount(),
            $event->getNewEndDate()->format('Y-m-d')
        ));
    }

    /**
     * Send renewal confirmation email to customer
     *
     * @param ContractRenewed $event
     * @return void
     */
    private static function sendCustomerConfirmation(ContractRenewed $event): void
    {
        $contract = $event->getContract();
        $payment = $event->getPayment();

        $customer = get_userdata($contract->getClientUserId());

        if (!$customer || empty($customer->user_email)) {
            error_log(sprintf(
                '[LimpVix] Cannot send renewal confirmation: customer email not found for contract %d',
                $contract->getId()->toInt()
            ));
            return;
        }

        $subject = '[LimpVix] Contrato Renovado com Sucesso';

        $message = self::buildCustomerEmailBody($contract, $payment, $event, $customer);

        $sent = wp_mail($customer->user_email, $subject, $message);

        if ($sent) {
            error_log(sprintf(
                '[LimpVix] Renewal confirmation email sent to %s for contract %d',
                $customer->user_email,
                $contract->getId()->toInt()
            ));
        } else {
            error_log(sprintf(
                '[LimpVix] Failed to send renewal confirmation email to %s',
                $customer->user_email
            ));
        }
    }

    /**
     * Build customer confirmation email body
     *
     * @param \LimpVix\Domain\Contract\Contract $contract
     * @param \LimpVix\Domain\Finance\RecurringPayment $payment
     * @param ContractRenewed $event
     * @param \WP_User $customer
     * @return string
     */
    private static function buildCustomerEmailBody($contract, $payment, $event, $customer): string
    {
        $contractTypeName = self::getContractTypeName($contract->getContractType());
        $nextChargeDate = self::calculateNextChargeDate($event->getNewEndDate(), $contract->getContractType());

        return sprintf(
            "Olá %s,\n\n" .
            "Seu contrato foi renovado automaticamente com sucesso!\n\n" .
            "=== DETALHES DA RENOVAÇÃO ===\n\n" .
            "Contrato: #%d\n" .
            "Tipo: %s\n" .
            "Valor Pago: R$ %.2f\n" .
            "Ciclo de Cobrança: %d\n" .
            "Data do Pagamento: %s\n\n" .
            "=== VIGÊNCIA ATUALIZADA ===\n\n" .
            "Novo Vencimento: %s\n" .
            "Próxima Cobrança: %s\n\n" .
            "Seu serviço de limpeza continuará normalmente conforme agendado.\n\n" .
            "Dúvidas? Entre em contato conosco.\n\n" .
            "Atenciosamente,\n" .
            "Equipe LimpVix",
            $customer->display_name ?? 'Cliente',
            $contract->getId()->toInt(),
            $contractTypeName,
            $payment->getAmount(),
            $payment->getBillingCycleNumber(),
            $payment->getPaidAt() ? $payment->getPaidAt()->format('d/m/Y H:i') : 'Agora',
            $event->getNewEndDate()->format('d/m/Y'),
            $nextChargeDate->format('d/m/Y')
        );
    }

    /**
     * Notify professional about contract renewal
     *
     * @param ContractRenewed $event
     * @return void
     */
    private static function notifyProfessional(ContractRenewed $event): void
    {
        $contract = $event->getContract();
        $professionalId = $contract->getAllocatedProfessionalId();

        if (!$professionalId) {
            // No professional allocated yet
            return;
        }

        $professional = get_userdata($professionalId);

        if (!$professional || empty($professional->user_email)) {
            return;
        }

        $subject = sprintf(
            '[LimpVix] Contrato #%d Renovado - Cliente Ativo',
            $contract->getId()->toInt()
        );

        $message = sprintf(
            "Olá %s,\n\n" .
            "O contrato #%d foi renovado automaticamente.\n\n" .
            "Detalhes:\n" .
            "- Cliente: %s\n" .
            "- Tipo: %s\n" .
            "- Vigência até: %s\n\n" .
            "Continue prestando um excelente serviço!\n\n" .
            "Equipe LimpVix",
            $professional->display_name ?? 'Profissional',
            $contract->getId()->toInt(),
            get_userdata($contract->getClientUserId())->display_name ?? 'N/A',
            self::getContractTypeName($contract->getContractType()),
            $event->getNewEndDate()->format('d/m/Y')
        );

        wp_mail($professional->user_email, $subject, $message);

        error_log(sprintf(
            '[LimpVix] Professional notification sent to %s for contract %d renewal',
            $professional->user_email,
            $contract->getId()->toInt()
        ));
    }

    /**
     * Get human-readable contract type name
     *
     * @param string $contractType
     * @return string
     */
    private static function getContractTypeName(string $contractType): string
    {
        $types = [
            'weekly' => 'Semanal',
            'biweekly' => 'Quinzenal',
            'monthly' => 'Mensal',
        ];

        return $types[$contractType] ?? ucfirst($contractType);
    }

    /**
     * Calculate next charge date based on contract type
     *
     * @param \DateTimeImmutable $currentEndDate
     * @param string $contractType
     * @return \DateTimeImmutable
     */
    private static function calculateNextChargeDate(
        \DateTimeImmutable $currentEndDate,
        string $contractType
    ): \DateTimeImmutable {
        switch ($contractType) {
            case 'weekly':
                return $currentEndDate->modify('+1 week');

            case 'biweekly':
                return $currentEndDate->modify('+2 weeks');

            case 'monthly':
            default:
                return $currentEndDate->modify('+1 month');
        }
    }
}
