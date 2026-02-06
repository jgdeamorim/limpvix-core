<?php
/**
 * WooCommercePaymentAdapter - Adaptador para Eventos de Pagamento
 *
 * RESPONSABILIDADE:
 * - Capturar hook woocommerce_payment_complete
 * - Extrair dados necessários
 * - Traduzir para Command interno
 * - Chamar Use Case apropriado
 *
 * PRINCÍPIOS:
 * - Adapter Pattern
 * - Zero regras de negócio
 * - Zero decisões financeiras
 * - Apenas tradução: evento externo → comando interno
 *
 * IMPORTANTE:
 * - NÃO decide se pode transicionar (Policy decide)
 * - NÃO valida regras (Use Case + Policy validam)
 * - NÃO acessa ledger diretamente
 * - Apenas: hook → dados → use case
 *
 * HOOK:
 * - woocommerce_payment_complete
 * - Disparado quando pagamento é confirmado
 *
 * PASSO 5.4 - Adaptadores de Eventos
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\ProcessPaymentConfirmed;
use LimpVix\Infrastructure\Persistence\WpOrderRepository;

defined('ABSPATH') || exit;

class WooCommercePaymentAdapter
{
    /**
     * Use Case
     *
     * @var ProcessPaymentConfirmed
     */
    private $useCase;

    /**
     * Order Repository
     *
     * @var WpOrderRepository
     */
    private $orderRepository;

    /**
     * Construtor
     *
     * @param ProcessPaymentConfirmed $useCase
     * @param WpOrderRepository $orderRepository
     */
    public function __construct(
        ProcessPaymentConfirmed $useCase,
        WpOrderRepository $orderRepository
    ) {
        $this->useCase = $useCase;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Registrar hooks
     *
     * @return void
     */
    public function register(): void
    {
        add_action('woocommerce_payment_complete', [$this, 'handlePaymentComplete'], 10, 1);
    }

    /**
     * Handler: woocommerce_payment_complete
     *
     * @param int $orderId WooCommerce order ID
     * @return void
     */
    public function handlePaymentComplete(int $orderId): void
    {
        try {
            // 1. Obter UUID da order
            $orderUuid = $this->getOrderUuid($orderId);

            if ($orderUuid === null) {
                $this->logWarning("Order {$orderId} não tem UUID mapeado");
                return;
            }

            // 2. Obter customer ID
            $customerId = $this->getCustomerId($orderId);

            // 3. Executar Use Case
            $result = $this->useCase->execute($orderUuid, $customerId);

            // 4. Log do resultado
            if ($result->isSuccess()) {
                $this->logSuccess($orderId, $orderUuid, $result);
            } else {
                $this->logRejection($orderId, $orderUuid, $result);
            }

        } catch (\Exception $e) {
            $this->logError($orderId, $e);
        }
    }

    /**
     * Obter UUID da order a partir do WC order ID
     *
     * @param int $orderId
     * @return string|null
     */
    private function getOrderUuid(int $orderId): ?string
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_orders';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $uuid = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT uuid FROM {$table} WHERE order_id = %d LIMIT 1",
                $orderId
            )
        );

        return $uuid ?: null;
    }

    /**
     * Obter customer ID da order
     *
     * @param int $orderId
     * @return int|null
     */
    private function getCustomerId(int $orderId): ?int
    {
        $order = wc_get_order($orderId);

        if (!$order) {
            return null;
        }

        $customerId = $order->get_customer_id();

        return $customerId > 0 ? $customerId : null;
    }

    /**
     * Log de sucesso
     *
     * @param int $orderId
     * @param string $orderUuid
     * @param \LimpVix\Application\Results\TransitionFinancialStatusResult $result
     * @return void
     */
    private function logSuccess(int $orderId, string $orderUuid, $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_wc_payment_processed', [
            'wc_order_id' => $orderId,
            'order_uuid' => $orderUuid,
            'from_status' => $result->getFromStatus()->getValue(),
            'to_status' => $result->getToStatus()->getValue(),
            'ledger_uuid' => $result->getLedgerUuid()
        ]);
    }

    /**
     * Log de rejeição
     *
     * @param int $orderId
     * @param string $orderUuid
     * @param \LimpVix\Application\Results\TransitionFinancialStatusResult $result
     * @return void
     */
    private function logRejection(int $orderId, string $orderUuid, $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_wc_payment_rejected', [
            'wc_order_id' => $orderId,
            'order_uuid' => $orderUuid,
            'reason' => $result->getRejectReason()
        ]);
    }

    /**
     * Log de warning
     *
     * @param string $message
     * @return void
     */
    private function logWarning(string $message): void
    {
        if (function_exists('do_action')) {
            do_action('limpvix_adapter_warning', [
                'adapter' => 'WooCommercePaymentAdapter',
                'message' => $message
            ]);
        }
    }

    /**
     * Log de erro
     *
     * @param int $orderId
     * @param \Exception $exception
     * @return void
     */
    private function logError(int $orderId, \Exception $exception): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_adapter_error', [
            'adapter' => 'WooCommercePaymentAdapter',
            'wc_order_id' => $orderId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
