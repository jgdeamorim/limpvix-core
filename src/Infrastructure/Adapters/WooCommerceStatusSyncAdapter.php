<?php
/**
 * WooCommerceStatusSyncAdapter - Sincroniza Status LimpVix → WooCommerce
 *
 * RESPONSABILIDADE:
 * - Escutar mudanças no financial_status da Order LimpVix
 * - Atualizar WC_Order status correspondente
 * - Manter sincronização bidirecional
 *
 * MAPEAMENTO:
 * - CREATED → wc-pending
 * - PAID → wc-processing
 * - HELD → wc-processing
 * - REVIEW → wc-processing
 * - AUTHORIZED → wc-processing
 * - TRANSFERRED → wc-completed
 * - BLOCKED → wc-on-hold
 * - REFUNDED → wc-refunded
 *
 * BLOQUEADOR: BLC-002
 * - Corrige: WC Order #12152 fica em "Processando" mesmo após TRANSFERRED
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

defined('ABSPATH') || exit;

class WooCommerceStatusSyncAdapter
{
    /**
     * Mapeamento Financial Status → WC Status
     */
    private const STATUS_MAP = [
        'CREATED' => 'pending',
        'PAID' => 'processing',
        'HELD' => 'processing',
        'REVIEW' => 'processing',
        'AUTHORIZED' => 'processing',
        'TRANSFERRED' => 'completed',
        'BLOCKED' => 'on-hold',
        'REFUNDED' => 'refunded'
    ];

    /**
     * Registrar hooks
     *
     * @return void
     */
    public function register(): void
    {
        add_action('limpvix_financial_status_changed', [$this, 'handleStatusChanged'], 10, 3);
    }

    /**
     * Handler: limpvix_financial_status_changed
     *
     * @param string $orderUuid UUID da Order LimpVix
     * @param string $fromStatus Status anterior
     * @param string $toStatus Novo status
     * @return void
     */
    public function handleStatusChanged(string $orderUuid, string $fromStatus, string $toStatus): void
    {
        error_log("[WooCommerceStatusSyncAdapter] Financial status changed: {$fromStatus} → {$toStatus} (Order: {$orderUuid})");

        try {
            // 1. Buscar WC Order ID
            $wcOrderId = $this->getWcOrderId($orderUuid);

            if ($wcOrderId === null) {
                error_log("[WooCommerceStatusSyncAdapter] Nenhum WC Order vinculado ao UUID {$orderUuid}");
                return;
            }

            // 2. Mapear status
            $wcStatus = $this->mapToWcStatus($toStatus);

            if ($wcStatus === null) {
                error_log("[WooCommerceStatusSyncAdapter] Status {$toStatus} não tem mapeamento WC");
                return;
            }

            // 3. Atualizar WC Order
            $this->updateWcOrderStatus($wcOrderId, $wcStatus, $orderUuid);

        } catch (\Exception $e) {
            error_log("[WooCommerceStatusSyncAdapter] EXCEPTION: " . $e->getMessage());
            error_log("[WooCommerceStatusSyncAdapter] Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Buscar WC Order ID a partir do UUID LimpVix
     *
     * Suporta tanto HPOS (wp_wc_orders_meta) quanto sistema legado (wp_postmeta)
     *
     * @param string $orderUuid
     * @return int|null
     */
    private function getWcOrderId(string $orderUuid): ?int
    {
        global $wpdb;

        // WooCommerce 8.0+ pode usar HPOS (High-Performance Order Storage)
        $hposEnabled = get_option('woocommerce_custom_orders_table_enabled', 'no') === 'yes';

        if ($hposEnabled) {
            // HPOS: Buscar em wc_orders_meta
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wcOrderId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
                     WHERE meta_key = '_limpvix_order_uuid'
                     AND meta_value = %s
                     LIMIT 1",
                    $orderUuid
                )
            );
        } else {
            // Sistema legado: Buscar em postmeta
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wcOrderId = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT pm.post_id
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                     WHERE pm.meta_key = '_limpvix_order_uuid'
                     AND pm.meta_value = %s
                     AND p.post_type = 'shop_order'
                     LIMIT 1",
                    $orderUuid
                )
            );
        }

        return $wcOrderId ? (int) $wcOrderId : null;
    }

    /**
     * Mapear Financial Status → WC Status
     *
     * @param string $financialStatus
     * @return string|null
     */
    private function mapToWcStatus(string $financialStatus): ?string
    {
        return self::STATUS_MAP[$financialStatus] ?? null;
    }

    /**
     * Atualizar WC Order status
     *
     * @param int $wcOrderId
     * @param string $wcStatus
     * @param string $orderUuid
     * @return void
     */
    private function updateWcOrderStatus(int $wcOrderId, string $wcStatus, string $orderUuid): void
    {
        $wcOrder = wc_get_order($wcOrderId);

        if (!$wcOrder) {
            error_log("[WooCommerceStatusSyncAdapter] WC Order {$wcOrderId} não encontrado");
            return;
        }

        // Verificar se já está no status correto (evitar loop)
        $currentStatus = $wcOrder->get_status();
        if ($currentStatus === $wcStatus) {
            error_log("[WooCommerceStatusSyncAdapter] WC Order {$wcOrderId} já está em '{$wcStatus}' - pulando");
            return;
        }

        // Atualizar status
        error_log("[WooCommerceStatusSyncAdapter] Atualizando WC Order {$wcOrderId}: {$currentStatus} → {$wcStatus}");

        $wcOrder->update_status(
            $wcStatus,
            sprintf(
                'Status sincronizado com LimpVix Order %s',
                substr($orderUuid, 0, 8)
            )
        );

        error_log("[WooCommerceStatusSyncAdapter] ✅ WC Order {$wcOrderId} atualizado para '{$wcStatus}'");
    }
}
