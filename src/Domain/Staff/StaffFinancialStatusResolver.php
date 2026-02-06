<?php
/**
 * StaffFinancialStatusResolver - Resolvedor de Status Financeiro do Staff
 *
 * RESPONSABILIDADE:
 * - Determinar elegibilidade financeira do profissional
 * - Calcular saldos disponíveis, pendentes, bloqueados
 * - Verificar compliance (MP, disputas, fraude)
 * - Fornecer dados para UI e Guards
 *
 * PRINCÍPIOS:
 * - Single source of truth
 * - Cálculos em tempo real (não cache)
 * - Transparência total
 *
 * @package LimpVix\Domain\Staff
 */

namespace LimpVix\Domain\Staff;

defined('ABSPATH') || exit;

final class StaffFinancialStatusResolver
{
    private int $userId;
    private ?array $cached = null;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Obter status financeiro completo
     *
     * @return array
     */
    public function getStatus(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $this->cached = [
            'user_id' => $this->userId,
            'is_eligible' => $this->isEligible(),
            'can_receive_payouts' => $this->canReceivePayouts(),
            'balances' => $this->getBalances(),
            'blockers' => $this->getBlockers(),
            'mercadopago' => $this->getMercadoPagoStatus(),
            'disputes' => $this->getDisputesInfo(),
            'last_payout' => $this->getLastPayoutInfo(),
            'calculated_at' => time(),
        ];

        return $this->cached;
    }

    /**
     * Verificar se profissional é elegível
     *
     * @return bool
     */
    private function isEligible(): bool
    {
        // Conta MP válida
        if (!$this->hasMercadoPagoAccount()) {
            return false;
        }

        // Não está bloqueado
        if ($this->isBlocked()) {
            return false;
        }

        // Não tem disputa ativa
        if ($this->hasActiveDisputes()) {
            return false;
        }

        // Não está suspenso por fraude
        if ($this->isSuspendedForFraud()) {
            return false;
        }

        return true;
    }

    /**
     * Verificar se pode receber payouts
     *
     * @return bool
     */
    private function canReceivePayouts(): bool
    {
        return $this->isEligible() && $this->getBalances()['available'] > 0;
    }

    /**
     * Obter saldos
     *
     * @return array
     */
    private function getBalances(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_financial_ledger';

        // Disponível (AUTHORIZED, payout não solicitado)
        $available = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$table} 
             WHERE professional_id = %d 
             AND event_type = 'authorized' 
             AND payout_requested = 0",
            $this->userId
        )) ?: 0.0;

        // Pendente (payout solicitado, não transferido)
        $pending = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$table} 
             WHERE professional_id = %d 
             AND event_type = 'payout_requested' 
             AND transferred = 0",
            $this->userId
        )) ?: 0.0;

        // Bloqueado (em disputa ou análise)
        $blocked = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$table} 
             WHERE professional_id = %d 
             AND event_type = 'blocked'",
            $this->userId
        )) ?: 0.0;

        // Total recebido (histórico)
        $total_received = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM {$table} 
             WHERE professional_id = %d 
             AND event_type = 'transferred'",
            $this->userId
        )) ?: 0.0;

        return [
            'available' => $available,
            'pending' => $pending,
            'blocked' => $blocked,
            'total_received' => $total_received,
        ];
    }

    /**
     * Obter bloqueadores
     *
     * @return array
     */
    private function getBlockers(): array
    {
        $blockers = [];

        if (!$this->hasMercadoPagoAccount()) {
            $blockers[] = [
                'type' => 'no_mercadopago_account',
                'severity' => 'high',
                'message' => 'Conta Mercado Pago não conectada',
                'action_url' => admin_url('admin.php?page=limpvix-conectar-mp'),
            ];
        }

        if ($this->isBlocked()) {
            $reason = get_user_meta($this->userId, 'limpvix_staff_blocked_reason', true);
            $blockers[] = [
                'type' => 'manually_blocked',
                'severity' => 'critical',
                'message' => $reason ?: 'Bloqueado pela administração',
                'action_url' => null,
            ];
        }

        if ($this->hasActiveDisputes()) {
            $count = $this->getActiveDisputeCount();
            $blockers[] = [
                'type' => 'active_disputes',
                'severity' => 'high',
                'message' => sprintf('%d disputa(s) ativa(s)', $count),
                'action_url' => admin_url('admin.php?page=limpvix-disputas'),
            ];
        }

        if ($this->isSuspendedForFraud()) {
            $blockers[] = [
                'type' => 'fraud_suspension',
                'severity' => 'critical',
                'message' => 'Conta suspensa por suspeita de fraude',
                'action_url' => null,
            ];
        }

        return $blockers;
    }

    /**
     * Obter status Mercado Pago
     *
     * @return array
     */
    private function getMercadoPagoStatus(): array
    {
        $accessToken = get_user_meta($this->userId, 'limpvix_mp_access_token', true);
        $publicKey = get_user_meta($this->userId, 'limpvix_mp_public_key', true);
        $validUntil = get_user_meta($this->userId, 'limpvix_mp_token_valid_until', true);

        return [
            'connected' => !empty($accessToken),
            'has_public_key' => !empty($publicKey),
            'token_valid' => $validUntil && time() < $validUntil,
            'valid_until' => $validUntil ?: null,
        ];
    }

    /**
     * Obter informações de disputas
     *
     * @return array
     */
    private function getDisputesInfo(): array
    {
        global $wpdb;

        $active = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'dispute_opened' AND resolved = 0",
            $this->userId
        ));

        $resolved = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'dispute_opened' AND resolved = 1",
            $this->userId
        ));

        return [
            'active_count' => $active,
            'resolved_count' => $resolved,
            'has_active' => $active > 0,
        ];
    }

    /**
     * Obter informações do último payout
     *
     * @return array|null
     */
    private function getLastPayoutInfo(): ?array
    {
        global $wpdb;

        $lastPayout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'transferred' 
             ORDER BY id DESC LIMIT 1",
            $this->userId
        ), ARRAY_A);

        if (!$lastPayout) {
            return null;
        }

        return [
            'amount' => (float)$lastPayout['amount'],
            'order_uuid' => $lastPayout['order_uuid'],
            'transferred_at' => $lastPayout['created_at'],
        ];
    }

    /**
     * Verificar conta MP
     *
     * @return bool
     */
    private function hasMercadoPagoAccount(): bool
    {
        return !empty(get_user_meta($this->userId, 'limpvix_mp_access_token', true));
    }

    /**
     * Verificar bloqueio manual
     *
     * @return bool
     */
    private function isBlocked(): bool
    {
        return (bool)get_user_meta($this->userId, 'limpvix_staff_blocked', true);
    }

    /**
     * Verificar disputas ativas
     *
     * @return bool
     */
    private function hasActiveDisputes(): bool
    {
        return $this->getActiveDisputeCount() > 0;
    }

    /**
     * Obter número de disputas ativas
     *
     * @return int
     */
    private function getActiveDisputeCount(): int
    {
        global $wpdb;
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_financial_ledger 
             WHERE professional_id = %d AND event_type = 'dispute_opened' AND resolved = 0",
            $this->userId
        ));
    }

    /**
     * Verificar suspensão por fraude
     *
     * @return bool
     */
    private function isSuspendedForFraud(): bool
    {
        return (bool)get_user_meta($this->userId, 'limpvix_suspended_fraud', true);
    }

    /**
     * Obter resumo em texto
     *
     * @return string
     */
    public function getSummary(): string
    {
        $status = $this->getStatus();

        if ($status['is_eligible']) {
            return sprintf(
                '✅ Elegível | Disponível: R$ %.2f',
                $status['balances']['available']
            );
        }

        $blockers = $status['blockers'];
        if (!empty($blockers)) {
            return '❌ ' . $blockers[0]['message'];
        }

        return '⚠️ Status desconhecido';
    }
}
