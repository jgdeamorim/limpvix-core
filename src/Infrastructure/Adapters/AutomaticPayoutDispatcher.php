<?php
/**
 * AutomaticPayoutDispatcher - Disparador automático de repasses
 *
 * RESPONSABILIDADE:
 * - Escutar transição para AUTHORIZED
 * - Disparar repasse automaticamente ao profissional
 * - Usar ExecutePayout com validação Golden Rule
 *
 * PRINCÍPIOS:
 * - Event-driven (escuta limpvix_financial_status_changed)
 * - Fail-safe (não quebra se repasse falhar)
 * - Auditável (logs detalhados)
 *
 * TRIGGER:
 * - Hook: limpvix_financial_status_changed
 * - Quando: toStatus = 'AUTHORIZED'
 * - Ação: Executar repasse ao profissional
 *
 * CRITICAL FIX (Finance Core Stabilization Sprint):
 * - Fixed broken class reference: ExecuteTransfer → ExecutePayout
 * - Previous: Referenced non-existent ExecuteTransfer class (compile error)
 * - Current: Uses actual ExecutePayout use case
 * - Added getPayoutIdForOrder() helper method
 * - Now follows Golden Rule enforcement via ExecutePayout
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\Financial\ExecutePayout;
use LimpVix\Infrastructure\Persistence\WpOrderRepository;

defined('ABSPATH') || exit;

class AutomaticPayoutDispatcher
{
    /**
     * Use Case de execução de payout
     *
     * ✅ FIXED: Changed from ExecuteTransfer to ExecutePayout
     *
     * @var ExecutePayout
     */
    private $executePayout;

    /**
     * Repository de orders
     *
     * @var WpOrderRepository
     */
    private $orderRepository;

    /**
     * Construtor
     *
     * ✅ FIXED: Updated parameter type from ExecuteTransfer to ExecutePayout
     *
     * @param ExecutePayout $executePayout
     * @param WpOrderRepository $orderRepository
     */
    public function __construct(
        ExecutePayout $executePayout,
        WpOrderRepository $orderRepository
    ) {
        $this->executePayout = $executePayout;
        $this->orderRepository = $orderRepository;
    }

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
     * ✅ REFACTORED: Now calls ExecutePayout with payout ID
     *
     * @param string $orderUuid
     * @param string $fromStatus
     * @param string $toStatus
     * @return void
     */
    public function handleStatusChanged(string $orderUuid, string $fromStatus, string $toStatus): void
    {
        // Disparar repasse apenas quando transiciona para AUTHORIZED
        if ($toStatus !== 'AUTHORIZED') {
            return;
        }

        error_log("[AutomaticPayoutDispatcher] Order {$orderUuid} agora está AUTHORIZED - disparando repasse automático");

        try {
            // 1. Buscar dados da Order
            $order = $this->orderRepository->findByUuid($orderUuid);

            if (!$order) {
                error_log("[AutomaticPayoutDispatcher] ❌ Order {$orderUuid} não encontrada");
                return;
            }

            // 2. Buscar payout ID correspondente à order
            $payoutId = $this->getPayoutIdForOrder($orderUuid);

            if (!$payoutId) {
                error_log("[AutomaticPayoutDispatcher] ❌ Payout não encontrado para Order {$orderUuid}");
                return;
            }

            // 3. Executar payout via use case (Golden Rule enforcement)
            // CRITICAL: ExecutePayout validates Execution::VALIDATED before processing
            $result = $this->executePayout->execute($payoutId);

            if ($result->isSuccess()) {
                $data = $result->getValue();
                error_log(sprintf(
                    "[AutomaticPayoutDispatcher] ✅ Payout executado com sucesso! Payout ID: %d | Execution Status: %s | Status: %s",
                    $data['payout_id'],
                    $data['execution_status'],
                    $data['status']
                ));
            } else {
                error_log(sprintf(
                    "[AutomaticPayoutDispatcher] ❌ Payout falhou: %s",
                    $result->getError()
                ));
            }

        } catch (\Exception $e) {
            error_log("[AutomaticPayoutDispatcher] ❌ EXCEPTION: " . $e->getMessage());
            error_log("[AutomaticPayoutDispatcher] Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Buscar payout ID vinculado à Order
     *
     * ✅ NEW: Helper method added (was missing in original)
     *
     * @param string $orderUuid
     * @return int|null Payout ID ou null se não encontrado
     */
    private function getPayoutIdForOrder(string $orderUuid): ?int
    {
        global $wpdb;

        $payoutId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}limpvix_payouts WHERE order_uuid = %s ORDER BY created_at DESC LIMIT 1",
            $orderUuid
        ));

        return $payoutId ? (int) $payoutId : null;
    }
}
