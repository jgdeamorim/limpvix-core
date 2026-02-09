<?php
/**
 * BriefingContractListener
 *
 * Escuta eventos de briefing e cria contratos automaticamente
 * quando briefing é locked e tem recorrência solicitada.
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\UseCases\Contract\CreateContractFromBriefing;

defined('ABSPATH') || exit;

class BriefingContractListener
{
    public static function register(): void
    {
        add_action('limpvix_briefing_locked', [__CLASS__, 'onBriefingLocked'], 10, 2);
        add_action('limpvix_briefing_confirmed', [__CLASS__, 'onBriefingConfirmed'], 10, 2);
    }

    public static function onBriefingLocked(int $briefingId, array $briefingData): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[LimpVix] BriefingContractListener: Briefing #{$briefingId} locked");
        }

        if (empty($briefingData['is_recurrent']) || !$briefingData['is_recurrent']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[LimpVix] Briefing #{$briefingId} não é recorrente, ignorando");
            }
            return;
        }

        $useCase = new CreateContractFromBriefing();
        $result = $useCase->execute($briefingData);

        if (is_wp_error($result)) {
            error_log(sprintf(
                '[LimpVix] Erro ao criar contrato do Briefing #%d: %s',
                $briefingId,
                $result->get_error_message()
            ));

            do_action('limpvix_contract_creation_failed', [
                'briefing_id' => $briefingId,
                'error_code' => $result->get_error_code(),
                'error_message' => $result->get_error_message(),
            ]);

            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix] ✅ Contrato #%d criado com sucesso do Briefing #%d',
                $result['contract_id'],
                $briefingId
            ));
        }

        self::notifyAdminContractCreated($briefingId, $result['contract_id'], $briefingData);
    }

    public static function onBriefingConfirmed(int $briefingId, array $briefingData): void
    {
        global $wpdb;
        $existingContract = $wpdb->get_var($wpdb->prepare(
            "SELECT contract_id FROM {$wpdb->prefix}limpvix_briefings WHERE id = %d",
            $briefingId
        ));

        if ($existingContract) {
            return;
        }

        if (!empty($briefingData['is_recurrent']) && $briefingData['is_recurrent']) {
            self::onBriefingLocked($briefingId, $briefingData);
        }
    }

    private static function notifyAdminContractCreated(int $briefingId, int $contractId, array $briefingData): void
    {
        $adminEmail = get_option('admin_email');

        global $wpdb;
        $contract = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_contracts WHERE id = %d",
            $contractId
        ), ARRAY_A);

        if (!$contract) return;

        $user = get_user_by('id', $contract['client_user_id']);
        $clientName = $user ? $user->display_name : 'Cliente';

        $subject = "📄 Novo Contrato Recorrente - {$contract['contract_number']}";
        $message = "Novo contrato criado automaticamente do Briefing #{$briefingId}\n\n";
        $message .= "Contrato: {$contract['contract_number']}\n";
        $message .= "Cliente: {$clientName}\n";
        $message .= "Valor: R$ " . number_format($contract['monthly_value'], 2, ',', '.') . "\n";
        $message .= "\n" . admin_url("admin.php?page=limpvix-contracts&contract_id={$contractId}");

        wp_mail($adminEmail, $subject, $message);

        do_action('limpvix_admin_notification', [
            'type' => 'contract_created',
            'title' => 'Novo Contrato Recorrente',
            'message' => "Contrato {$contract['contract_number']} criado",
            'contract_id' => $contractId,
            'briefing_id' => $briefingId,
        ]);
    }
}
