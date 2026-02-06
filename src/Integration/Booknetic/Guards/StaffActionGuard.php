<?php
/**
 * StaffActionGuard - Guard de Ações do Staff
 *
 * RESPONSABILIDADE:
 * - Controlar ações específicas do staff no painel
 * - Bloquear ações financeiramente sensíveis
 * - Validar contexto antes de permitir execução
 *
 * @package LimpVix\Integration\Booknetic\Guards
 */

namespace LimpVix\Integration\Booknetic\Guards;

defined('ABSPATH') || exit;

final class StaffActionGuard
{
    /**
     * Ações que exigem verificação financeira
     */
    private const FINANCIAL_ACTIONS = [
        'complete_appointment',
        'cancel_appointment',
        'request_payout',
        'edit_service_price',
    ];

    /**
     * Verificar se staff pode executar uma ação
     *
     * Hook: bkntc_staff_can_execute_action
     *
     * @param bool $allowed
     * @param string $action
     * @param int $staffId
     * @return bool
     */
    public static function canExecute(bool $allowed, string $action, int $staffId): bool
    {
        if (!$allowed) {
            return false;
        }

        // Verificar se ação exige validação financeira
        if (!in_array($action, self::FINANCIAL_ACTIONS, true)) {
            return true;
        }

        // Validar contexto financeiro
        return self::validateFinancialContext($action, $staffId);
    }

    /**
     * Validar contexto financeiro para ação
     *
     * @param string $action
     * @param int $staffId
     * @return bool
     */
    private static function validateFinancialContext(string $action, int $staffId): bool
    {
        $userId = self::getWordPressUserId($staffId);

        if (!$userId) {
            return false;
        }

        switch ($action) {
            case 'complete_appointment':
                // Profissional deve estar financeiramente válido
                return !self::isBlocked($userId);

            case 'request_payout':
                // Profissional deve ter conta MP válida + sem disputas
                return self::canRequestPayout($userId);

            case 'edit_service_price':
                // Bloqueado se tiver disputas ou fraude
                return !self::hasSensitiveIssues($userId);

            default:
                return true;
        }
    }

    /**
     * Verificar se pode solicitar payout
     *
     * @param int $userId
     * @return bool
     */
    private static function canRequestPayout(int $userId): bool
    {
        // Conta MP válida
        $hasValidMP = (bool)get_user_meta($userId, 'limpvix_mp_access_token', true);

        // Sem disputas
        global $wpdb;
        $hasDisputes = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'dispute_opened' AND resolved = 0",
            $userId
        )) > 0;

        return $hasValidMP && !$hasDisputes;
    }

    /**
     * Verificar se tem problemas sensíveis (disputa/fraude)
     *
     * @param int $userId
     * @return bool
     */
    private static function hasSensitiveIssues(int $userId): bool
    {
        global $wpdb;

        $issues = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d 
             AND event_type IN ('dispute_opened', 'fraud_detected') 
             AND resolved = 0",
            $userId
        ));

        return $issues > 0;
    }

    /**
     * Verificar se está bloqueado
     *
     * @param int $userId
     * @return bool
     */
    private static function isBlocked(int $userId): bool
    {
        return (bool)get_user_meta($userId, 'limpvix_staff_blocked', true);
    }

    /**
     * Obter user_id do WordPress
     *
     * @param int $staffId
     * @return int|null
     */
    private static function getWordPressUserId(int $staffId): ?int
    {
        global $wpdb;
        $userId = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->prefix}bkntc_staff WHERE id = %d",
            $staffId
        ));
        return $userId ? (int)$userId : null;
    }
}
