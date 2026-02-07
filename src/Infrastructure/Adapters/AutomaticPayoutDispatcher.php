<?php
/**
 * AutomaticPayoutDispatcher - Disparador automático de repasses
 *
 * RESPONSABILIDADE:
 * - Escutar transição para AUTHORIZED
 * - Disparar repasse automaticamente ao profissional
 * - Usar ExecuteTransfer com valor líquido
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
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\ExecuteTransfer;
use LimpVix\Infrastructure\Persistence\WpOrderRepository;

defined('ABSPATH') || exit;

class AutomaticPayoutDispatcher
{
    /**
     * Use Case de execução de transferência
     *
     * @var ExecuteTransfer
     */
    private $executeTransfer;

    /**
     * Repository de orders
     *
     * @var WpOrderRepository
     */
    private $orderRepository;

    /**
     * Construtor
     *
     * @param ExecuteTransfer $executeTransfer
     * @param WpOrderRepository $orderRepository
     */
    public function __construct(
        ExecuteTransfer $executeTransfer,
        WpOrderRepository $orderRepository
    ) {
        $this->executeTransfer = $executeTransfer;
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

            // 2. Buscar dados do profissional (professional_id e MP User ID)
            $professionalData = $this->getProfessionalData($orderUuid);

            if (!$professionalData) {
                error_log("[AutomaticPayoutDispatcher] ❌ Dados do profissional não encontrados para Order {$orderUuid}");
                return;
            }

            if (empty($professionalData['mp_user_id'])) {
                error_log("[AutomaticPayoutDispatcher] ⚠️  Profissional {$professionalData['professional_id']} não tem MP User ID configurado");
                return;
            }

            // 3. Executar repasse
            $result = $this->executeTransfer->execute(
                $orderUuid,
                $professionalData['mp_user_id'],
                sprintf(
                    'Repasse LimpVix - Order %s',
                    substr($orderUuid, 0, 8)
                )
            );

            if ($result->isSuccess()) {
                error_log(sprintf(
                    "[AutomaticPayoutDispatcher] ✅ Repasse executado com sucesso! Payout ID: %s | MP Transfer ID: %s | Valor: R$%.2f",
                    $result->getPayoutId(),
                    $result->getMpTransferId(),
                    $order->getProfessionalNetAmount()
                ));
            } else {
                error_log(sprintf(
                    "[AutomaticPayoutDispatcher] ❌ Repasse falhou: %s",
                    $result->getReason()
                ));
            }

        } catch (\Exception $e) {
            error_log("[AutomaticPayoutDispatcher] ❌ EXCEPTION: " . $e->getMessage());
            error_log("[AutomaticPayoutDispatcher] Stack trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Buscar dados do profissional vinculado à Order
     *
     * @param string $orderUuid
     * @return array|null ['professional_id' => int, 'mp_user_id' => string]
     */
    private function getProfessionalData(string $orderUuid): ?array
    {
        global $wpdb;

        // Buscar professional_id da order
        $professionalId = $wpdb->get_var($wpdb->prepare(
            "SELECT professional_id FROM {$wpdb->prefix}limpvix_orders WHERE uuid = %s",
            $orderUuid
        ));

        if (!$professionalId) {
            return null;
        }

        // Buscar MP User ID do profissional
        // TODO: Ajustar quando houver tabela de profissionais
        // Por ora, usar user meta do WordPress
        $mpUserId = get_user_meta($professionalId, '_limpvix_mp_user_id', true);

        return [
            'professional_id' => (int) $professionalId,
            'mp_user_id' => $mpUserId ?: null
        ];
    }
}
