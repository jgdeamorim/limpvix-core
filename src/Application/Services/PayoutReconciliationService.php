<?php
/**
 * PayoutReconciliationService
 *
 * Serviço de reconciliação entre Ledger e Payouts
 *
 * Responsável por:
 * - Criar payouts a partir de eventos do ledger
 * - Sincronizar status: FSM → Payout → MP
 * - Garantir consistência financeira
 *
 * @package LimpVix\Application\Services
 * @since 0.1.3
 */

namespace LimpVix\Application\Services;

use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPayoutProvider;

class PayoutReconciliationService
{
    private WpPayoutRepository $payoutRepository;
    private MercadoPagoPayoutProvider $mpProvider;
    private \wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->payoutRepository = new WpPayoutRepository();
        $this->mpProvider = new MercadoPagoPayoutProvider();
    }

    /**
     * Criar payout a partir de evento do ledger
     *
     * Chamado quando FSM transita para status que libera pagamento
     *
     * @param int $ledger_event_id ID do evento no ledger
     * @param int $order_id
     * @param int $professional_id
     * @param float $gross_amount Valor bruto
     * @param float $platform_fee Taxa da plataforma (% ou fixo)
     * @param array $recipient Dados do destinatário
     * @return int|false ID do payout ou false se erro
     */
    public function createPayoutFromLedger(
        int $ledger_event_id,
        int $order_id,
        int $professional_id,
        float $gross_amount,
        float $platform_fee,
        array $recipient
    ) {
        // Validar se já existe payout para este evento
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->wpdb->prefix}limpvix_payouts WHERE ledger_event_id = %d",
            $ledger_event_id
        ));

        if ($existing) {
            error_log("[LimpVix] Payout já existe para ledger_event #{$ledger_event_id}");
            return (int) $existing;
        }

        // Calcular valor líquido
        $net_amount = $gross_amount - $platform_fee;

        if ($net_amount <= 0) {
            error_log("[LimpVix] Valor líquido inválido: {$net_amount}");
            return false;
        }

        // Validar recipient
        $required = ['type', 'key', 'name', 'document'];
        foreach ($required as $field) {
            if (empty($recipient[$field])) {
                error_log("[LimpVix] Recipient inválido: campo {$field} ausente");
                return false;
            }
        }

        // Criar payout
        $payout_id = $this->payoutRepository->create([
            'order_id' => $order_id,
            'professional_id' => $professional_id,
            'ledger_event_id' => $ledger_event_id,
            'gross_amount' => $gross_amount,
            'platform_fee' => $platform_fee,
            'net_amount' => $net_amount,
            'status' => 'pending', // Aguarda aprovação
            'gateway' => 'mercadopago',
            'recipient_type' => $recipient['type'], // pix, bank_account, mp_account
            'recipient_key' => $recipient['key'],
            'recipient_name' => $recipient['name'],
            'recipient_document' => $recipient['document'],
        ]);

        if ($payout_id) {
            error_log("[LimpVix] Payout #{$payout_id} criado para order #{$order_id}");
        }

        return $payout_id;
    }

    /**
     * Aprovar payout automaticamente conforme regras da FSM
     *
     * Chamado quando avaliação do cliente é processada
     *
     * @param int $order_id
     * @param int $rating Avaliação (1-5)
     * @return bool
     */
    public function approvePayout(int $order_id, int $rating): bool
    {
        // Buscar payout pending desta order
        $payouts = $this->payoutRepository->getByOrder($order_id);

        $pending_payout = null;
        foreach ($payouts as $payout) {
            if ($payout['status'] === 'pending') {
                $pending_payout = $payout;
                break;
            }
        }

        if (!$pending_payout) {
            error_log("[LimpVix] Nenhum payout pendente encontrado para order #{$order_id}");
            return false;
        }

        // Regras canônicas da FSM:
        // 5 estrelas → libera imediatamente (approved)
        // 4 estrelas → libera após 24h (implementar com WP-Cron)
        // ≤3 estrelas → retém para análise (on_hold)

        if ($rating === 5) {
            // Aprovação imediata
            $this->payoutRepository->updateStatus($pending_payout['id'], 'approved');
            error_log("[LimpVix] Payout #{$pending_payout['id']} aprovado (5 estrelas)");
            return true;

        } elseif ($rating === 4) {
            // Agendar aprovação para 24h
            $this->scheduleDelayedApproval($pending_payout['id'], '+24 hours');
            error_log("[LimpVix] Payout #{$pending_payout['id']} agendado para 24h (4 estrelas)");
            return true;

        } else {
            // Reter para análise
            $this->payoutRepository->updateStatus($pending_payout['id'], 'on_hold');
            error_log("[LimpVix] Payout #{$pending_payout['id']} retido (≤3 estrelas)");
            return true;
        }
    }

    /**
     * Agendar aprovação atrasada (4 estrelas)
     *
     * @param int $payout_id
     * @param string $delay Exemplo: '+24 hours'
     */
    private function scheduleDelayedApproval(int $payout_id, string $delay): void
    {
        $timestamp = strtotime($delay);

        wp_schedule_single_event($timestamp, 'limpvix_approve_payout', [$payout_id]);
    }

    /**
     * Hook para aprovar payout agendado
     *
     * @param int $payout_id
     */
    public function processScheduledApproval(int $payout_id): void
    {
        $payout = $this->payoutRepository->getById($payout_id);

        if (!$payout) {
            return;
        }

        // Verificar se ainda está pending
        if ($payout['status'] === 'pending') {
            $this->payoutRepository->updateStatus($payout_id, 'approved');
            error_log("[LimpVix] Payout #{$payout_id} aprovado (agendamento 24h)");
        }
    }

    /**
     * Processar payouts aprovados em lote
     *
     * Chamado via WP-Cron (ex: a cada hora)
     *
     * @param int $limit Máximo de payouts a processar
     * @return int Número de payouts processados
     */
    public function processBatch(int $limit = 10): int
    {
        if (!$this->mpProvider->isAvailable()) {
            error_log('[LimpVix] Mercado Pago não disponível para batch');
            return 0;
        }

        $approved_payouts = $this->payoutRepository->getPendingPayouts();
        $processed = 0;

        foreach (array_slice($approved_payouts, 0, $limit) as $payout) {
            if ($payout['status'] !== 'approved') {
                continue;
            }

            $success = $this->mpProvider->createPayout($payout['id']);

            if ($success) {
                $processed++;
            }

            // Rate limit (evitar throttling do MP)
            sleep(2);
        }

        error_log("[LimpVix] Batch processado: {$processed} payouts");

        return $processed;
    }

    /**
     * Sincronizar status de payouts em processamento
     *
     * Chamado via WP-Cron (ex: a cada 15 minutos)
     *
     * @return int Número de payouts sincronizados
     */
    public function syncProcessingPayouts(): int
    {
        return $this->mpProvider->syncProcessingPayouts();
    }

    /**
     * Retry de payouts falhados
     *
     * @return int Número de retries processados
     */
    public function retryFailedPayouts(): int
    {
        $retriable = $this->payoutRepository->getRetriablePayouts();
        $retried = 0;

        foreach ($retriable as $payout) {
            // Atualizar status para approved (para que possa ser reprocessado)
            $this->payoutRepository->updateStatus($payout['id'], 'approved');

            // Processar novamente
            $success = $this->mpProvider->createPayout($payout['id']);

            if ($success) {
                $retried++;
            }

            // Rate limit
            sleep(2);
        }

        error_log("[LimpVix] Retry batch: {$retried} payouts retentados");

        return $retried;
    }

    /**
     * Registrar hooks de WP-Cron
     */
    public static function registerCronHooks(): void
    {
        $service = new self();

        // Hook: Aprovar payout agendado (4 estrelas)
        add_action('limpvix_approve_payout', [$service, 'processScheduledApproval']);

        // Hook: Processar batch de payouts aprovados
        add_action('limpvix_process_payout_batch', [$service, 'processBatch']);

        // Hook: Sincronizar status com MP
        add_action('limpvix_sync_payouts', [$service, 'syncProcessingPayouts']);

        // Hook: Retry de falhas
        add_action('limpvix_retry_failed_payouts', [$service, 'retryFailedPayouts']);
    }

    /**
     * Agendar cron jobs (ao ativar plugin)
     */
    public static function scheduleCronJobs(): void
    {
        // Processar payouts aprovados (a cada hora)
        if (!wp_next_scheduled('limpvix_process_payout_batch')) {
            wp_schedule_event(time(), 'hourly', 'limpvix_process_payout_batch');
        }

        // Sincronizar status (a cada 15 minutos)
        if (!wp_next_scheduled('limpvix_sync_payouts')) {
            wp_schedule_event(time(), 'every_15_minutes', 'limpvix_sync_payouts');
        }

        // Retry de falhas (a cada 6 horas)
        if (!wp_next_scheduled('limpvix_retry_failed_payouts')) {
            wp_schedule_event(time(), 'twicedaily', 'limpvix_retry_failed_payouts');
        }
    }

    /**
     * Remover cron jobs (ao desativar plugin)
     */
    public static function clearCronJobs(): void
    {
        wp_clear_scheduled_hook('limpvix_process_payout_batch');
        wp_clear_scheduled_hook('limpvix_sync_payouts');
        wp_clear_scheduled_hook('limpvix_retry_failed_payouts');
    }
}
