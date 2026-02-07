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
        error_log("[WooCommercePaymentAdapter] Hook recebido! WC Order ID: {$orderId}");

        try {
            // 1. Obter UUID da order
            $orderUuid = $this->getOrderUuid($orderId);

            if ($orderUuid === null) {
                error_log("[WooCommercePaymentAdapter] ERRO: Order {$orderId} não tem UUID mapeado");
                $this->logWarning("Order {$orderId} não tem UUID mapeado");
                return;
            }

            error_log("[WooCommercePaymentAdapter] UUID encontrado: {$orderUuid}");

            // 2. Obter customer ID
            $customerId = $this->getCustomerId($orderId);
            error_log("[WooCommercePaymentAdapter] Customer ID: " . ($customerId ?? 'NULL'));

            // 3. Executar Use Case
            error_log("[WooCommercePaymentAdapter] Executando ProcessPaymentConfirmed...");
            $result = $this->useCase->execute($orderUuid, $customerId);
            error_log("[WooCommercePaymentAdapter] Use Case retornou: " . ($result->isSuccess() ? 'SUCCESS' : 'FAILED'));

            // 4. Log do resultado
            if ($result->isSuccess()) {
                error_log("[WooCommercePaymentAdapter] Transição realizada com sucesso!");
                $this->logSuccess($orderId, $orderUuid, $result);
            } else {
                error_log("[WooCommercePaymentAdapter] Transição rejeitada: " . $result->getRejectReason());
                $this->logRejection($orderId, $orderUuid, $result);
            }

        } catch (\Exception $e) {
            error_log("[WooCommercePaymentAdapter] EXCEPTION: " . $e->getMessage());
            error_log("[WooCommercePaymentAdapter] Stack trace: " . $e->getTraceAsString());
            $this->logError($orderId, $e);
        }
    }

    /**
     * Obter UUID da order a partir do WC order ID
     *
     * CORRIGIDO: Busca UUID via meta do WC_Order ao invés de query SQL
     * (tabela limpvix_orders não tem coluna order_id)
     *
     * @param int $orderId WooCommerce Order ID
     * @return string|null UUID da Order LimpVix
     */
    private function getOrderUuid(int $orderId): ?string
    {
        // Buscar WC Order
        $wc_order = wc_get_order($orderId);

        if (!$wc_order) {
            $this->logWarning("WC Order {$orderId} não encontrado");
            return null;
        }

        // Buscar UUID no meta
        $uuid = $wc_order->get_meta('_limpvix_order_uuid');

        if (empty($uuid)) {
            $this->logWarning("WC Order {$orderId} não tem meta _limpvix_order_uuid");
            return null;
        }

        return $uuid;
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
