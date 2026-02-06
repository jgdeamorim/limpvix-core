<?php
/**
 * StaffAccessGuard - Guard de Acesso ao Painel do Staff
 *
 * RESPONSABILIDADE:
 * - Bloquear acesso de profissionais financeiramente inválidos
 * - Verificar elegibilidade antes de permitir login no painel
 * - Aplicar regras de compliance (disputa, conta MP inválida, etc.)
 *
 * PRINCÍPIOS:
 * - Fail-fast (rejeitar cedo)
 * - Transparência (mostrar motivo do bloqueio)
 * - Sem exceções (somente admin pode forçar)
 *
 * @package LimpVix\Integration\Booknetic\Guards
 */

namespace LimpVix\Integration\Booknetic\Guards;

defined('ABSPATH') || exit;

final class StaffAccessGuard
{
    /**
     * Verificar se staff pode acessar o painel
     *
     * Hook: bkntc_staff_can_access
     *
     * @param bool $allowed Permissão padrão do Booknetic
     * @param int $staffId ID do staff
     * @return bool
     */
    public static function canAccess(bool $allowed, int $staffId): bool
    {
        // Se Booknetic já bloqueou, respeitar
        if (!$allowed) {
            return false;
        }

        // Verificar status financeiro do profissional
        $financialStatus = self::getStaffFinancialStatus($staffId);

        // Bloqueios absolutos
        if ($financialStatus['blocked']) {
            self::logAccessDenied($staffId, $financialStatus['reason']);
            return false;
        }

        // Permitir acesso
        return true;
    }

    /**
     * Obter status financeiro do staff
     *
     * @param int $staffId
     * @return array ['blocked' => bool, 'reason' => string]
     */
    private static function getStaffFinancialStatus(int $staffId): array
    {
        // Obter user_id do WordPress vinculado ao staff
        $userId = self::getWordPressUserId($staffId);

        if (!$userId) {
            return [
                'blocked' => true,
                'reason' => 'staff_without_wp_user',
            ];
        }

        // Verificar conta Mercado Pago
        if (!self::hasValidMercadoPagoAccount($userId)) {
            return [
                'blocked' => true,
                'reason' => 'invalid_mercadopago_account',
            ];
        }

        // Verificar bloqueio manual do admin
        if (self::isManuallyBlocked($userId)) {
            return [
                'blocked' => true,
                'reason' => 'manually_blocked_by_admin',
            ];
        }

        // Verificar se tem disputas ativas
        if (self::hasActiveDisputes($userId)) {
            return [
                'blocked' => true,
                'reason' => 'active_disputes',
            ];
        }

        // Verificar se conta está suspensa por fraude
        if (self::isSuspendedForFraud($userId)) {
            return [
                'blocked' => true,
                'reason' => 'suspended_for_fraud',
            ];
        }

        // Profissional OK
        return [
            'blocked' => false,
            'reason' => '',
        ];
    }

    /**
     * Obter user_id do WordPress vinculado ao staff Booknetic
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

    /**
     * Verificar se staff tem conta Mercado Pago válida
     *
     * @param int $userId
     * @return bool
     */
    private static function hasValidMercadoPagoAccount(int $userId): bool
    {
        // Verificar se tem access_token salvo
        $accessToken = get_user_meta($userId, 'limpvix_mp_access_token', true);

        if (empty($accessToken)) {
            return false;
        }

        // Verificar se token é válido (não expirado)
        $tokenValidUntil = get_user_meta($userId, 'limpvix_mp_token_valid_until', true);

        if ($tokenValidUntil && time() > $tokenValidUntil) {
            return false;
        }

        return true;
    }

    /**
     * Verificar se staff está bloqueado manualmente
     *
     * @param int $userId
     * @return bool
     */
    private static function isManuallyBlocked(int $userId): bool
    {
        return (bool)get_user_meta($userId, 'limpvix_staff_blocked', true);
    }

    /**
     * Verificar se staff tem disputas ativas
     *
     * @param int $userId
     * @return bool
     */
    private static function hasActiveDisputes(int $userId): bool
    {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
             FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d 
             AND event_type = 'dispute_opened' 
             AND resolved = 0",
            $userId
        ));

        return $count > 0;
    }

    /**
     * Verificar se staff está suspenso por fraude
     *
     * @param int $userId
     * @return bool
     */
    private static function isSuspendedForFraud(int $userId): bool
    {
        return (bool)get_user_meta($userId, 'limpvix_suspended_fraud', true);
    }

    /**
     * Registrar tentativa de acesso negado (auditoria)
     *
     * @param int $staffId
     * @param string $reason
     */
    private static function logAccessDenied(int $staffId, string $reason): void
    {
        do_action('limpvix_log_event', 'staff_access_denied', [
            'staff_id' => $staffId,
            'reason' => $reason,
            'timestamp' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    }

    /**
     * Obter mensagem humanizada do motivo do bloqueio
     *
     * @param string $reason
     * @return string
     */
    public static function getBlockReasonMessage(string $reason): string
    {
        $messages = [
            'staff_without_wp_user' => 'Conta não vinculada ao WordPress.',
            'invalid_mercadopago_account' => 'Conta Mercado Pago inválida ou não conectada. Acesse Perfil > Dados Bancários.',
            'manually_blocked_by_admin' => 'Acesso bloqueado pela administração. Entre em contato com o suporte.',
            'active_disputes' => 'Você possui disputas ativas. Acesso temporariamente bloqueado.',
            'suspended_for_fraud' => 'Conta suspensa por suspeita de fraude. Contate o suporte imediatamente.',
        ];

        return $messages[$reason] ?? 'Acesso bloqueado. Contate o suporte.';
    }
}
