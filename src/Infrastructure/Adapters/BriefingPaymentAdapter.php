<?php
/**
 * BriefingPaymentAdapter - Adaptador para Captura de Pagamento
 *
 * RESPONSABILIDADE:
 * - Escutar webhook woocommerce_payment_complete
 * - Identificar Order vinculada a um Briefing
 * - Transicionar Briefing: AWAITING_PAYMENT → PAID → LOCKED
 * - Disparar evento briefing.locked
 * - Iniciar processo de criação de contrato (se necessário)
 *
 * FLUXO:
 * 1. Cliente finaliza pagamento no WooCommerce
 * 2. WooCommerce dispara hook 'woocommerce_payment_complete'
 * 3. Este adapter captura o evento
 * 4. Busca Briefing pelo product meta
 * 5. Transiciona AWAITING_PAYMENT → PAID
 * 6. Chama LockBriefing use case
 * 7. Dispara evento limpvix_briefing_locked
 *
 * HOOKS:
 * - Escuta: woocommerce_payment_complete (prioridade 10)
 * - Escuta: woocommerce_order_status_completed (fallback)
 * - Dispara: limpvix_briefing_paid
 * - Dispara: limpvix_briefing_locked
 *
 * @package LimpVix\Infrastructure\Adapters
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Application\UseCases\Briefing\LockBriefing;

defined('ABSPATH') || exit;

class BriefingPaymentAdapter
{
    /**
     * @var BriefingRepositoryInterface
     */
    private $briefingRepository;

    /**
     * @var LockBriefing
     */
    private $lockBriefingUseCase;

    /**
     * Construtor
     *
     * @param BriefingRepositoryInterface $briefingRepository
     * @param LockBriefing $lockBriefingUseCase
     */
    public function __construct(
        BriefingRepositoryInterface $briefingRepository,
        LockBriefing $lockBriefingUseCase
    ) {
        $this->briefingRepository = $briefingRepository;
        $this->lockBriefingUseCase = $lockBriefingUseCase;
    }

    /**
     * Registrar hooks do WooCommerce
     *
     * Deve ser chamado no bootstrap do plugin.
     *
     * @return void
     */
    public function register(): void
    {
        // Hook principal: pagamento confirmado
        add_action('woocommerce_payment_complete', [$this, 'onPaymentComplete'], 10, 1);

        // Hook secundário: status mudou para completed (fallback)
        add_action('woocommerce_order_status_completed', [$this, 'onOrderCompleted'], 10, 1);

        // Hook terciário: status mudou para processing (pré-captura)
        add_action('woocommerce_order_status_processing', [$this, 'onOrderProcessing'], 10, 1);
    }

    /**
     * Handler: woocommerce_payment_complete
     *
     * Dispara quando pagamento é confirmado pelo gateway.
     *
     * @param int $orderId WooCommerce Order ID
     * @return void
     */
    public function onPaymentComplete(int $orderId): void
    {
        $this->processPayment($orderId, 'payment_complete');
    }

    /**
     * Handler: woocommerce_order_status_completed
     *
     * Fallback quando order muda para status 'completed'.
     *
     * @param int $orderId WooCommerce Order ID
     * @return void
     */
    public function onOrderCompleted(int $orderId): void
    {
        $this->processPayment($orderId, 'order_completed');
    }

    /**
     * Handler: woocommerce_order_status_processing
     *
     * Pré-captura quando order está em processing (pagamento pendente).
     * Transiciona Briefing para PAID mas NÃO faz lock ainda.
     *
     * @param int $orderId WooCommerce Order ID
     * @return void
     */
    public function onOrderProcessing(int $orderId): void
    {
        try {
            // 1. Buscar Briefing UUID
            $briefingUuid = WooCommerceBriefingAdapter::getBriefingUuidFromOrder($orderId);

            if ($briefingUuid === null) {
                return; // Não é uma order de Briefing
            }

            // 2. Buscar Briefing
            $briefing = $this->briefingRepository->findByUuid($briefingUuid);

            if ($briefing === null) {
                $this->logError("Briefing não encontrado: {$briefingUuid}");
                return;
            }

            // 3. Verificar estado
            if (!$briefing->getStatus()->isAwaitingPayment()) {
                return; // Já processado
            }

            // 4. Transicionar para PAID (sem lock ainda)
            $briefing->transitionTo(BriefingStatus::paid());
            $this->briefingRepository->save($briefing);

            // 5. Log
            $this->logInfo("Briefing marcado como PAID (processing): {$briefingUuid}, Order: {$orderId}");

            // 6. Disparar evento
            do_action('limpvix_briefing_paid', [
                'briefing_uuid' => $briefingUuid,
                'order_id' => $orderId,
                'status' => 'processing'
            ]);

        } catch (\Exception $e) {
            $this->logError('Erro ao processar order processing: ' . $e->getMessage());
        }
    }

    /**
     * Processar pagamento confirmado
     *
     * Lógica principal executada por onPaymentComplete e onOrderCompleted.
     *
     * @param int $orderId WooCommerce Order ID
     * @param string $source Hook de origem (debug)
     * @return void
     */
    private function processPayment(int $orderId, string $source): void
    {
        try {
            // 1. Buscar Briefing UUID pelo produto da order
            $briefingUuid = WooCommerceBriefingAdapter::getBriefingUuidFromOrder($orderId);

            if ($briefingUuid === null) {
                // Não é uma order de Briefing, ignorar
                return;
            }

            // 2. Buscar Briefing
            $briefing = $this->briefingRepository->findByUuid($briefingUuid);

            if ($briefing === null) {
                $this->logError("Briefing não encontrado: {$briefingUuid}");
                return;
            }

            // 3. Verificar se já está locked (evitar reprocessamento)
            if ($briefing->isLocked()) {
                $this->logInfo("Briefing já locked, ignorando webhook: {$briefingUuid}");
                return;
            }

            // 4. Transicionar para PAID (se ainda não estiver)
            if ($briefing->getStatus()->isAwaitingPayment()) {
                $briefing->transitionTo(BriefingStatus::paid());
                $this->briefingRepository->save($briefing);

                $this->logInfo("Briefing transicionado para PAID: {$briefingUuid}");

                // Disparar evento
                do_action('limpvix_briefing_paid', [
                    'briefing_uuid' => $briefingUuid,
                    'order_id' => $orderId,
                    'source' => $source
                ]);
            }

            // 5. Fazer lock (transição final)
            $result = $this->lockBriefingUseCase->execute($briefingUuid, $orderId);

            if ($result->isSuccess()) {
                $this->logInfo("Briefing locked com sucesso: {$briefingUuid}, Order: {$orderId}");

                // Disparar evento locked
                do_action('limpvix_briefing_locked', [
                    'briefing_uuid' => $briefingUuid,
                    'order_id' => $orderId,
                    'requires_contract' => $briefing->requiresContract(),
                    'source' => $source
                ]);
            } else {
                $this->logError("Falha ao fazer lock do Briefing: " . $result->getErrorMessage());
            }

        } catch (\DomainException $e) {
            // Violação de regra de negócio (esperado em alguns casos)
            $this->logWarning("Regra de negócio violada: " . $e->getMessage());

        } catch (\Exception $e) {
            // Erro inesperado
            $this->logError("Erro ao processar pagamento: " . $e->getMessage());
        }
    }

    /**
     * Verificar status de pagamento de uma Order
     *
     * Útil para diagnóstico e reprocessamento manual.
     *
     * @param int $orderId WooCommerce Order ID
     * @return array{paid: bool, status: string, briefing_uuid: ?string}
     */
    public function checkOrderPaymentStatus(int $orderId): array
    {
        $order = wc_get_order($orderId);

        if (!$order) {
            return [
                'paid' => false,
                'status' => 'not_found',
                'briefing_uuid' => null
            ];
        }

        $briefingUuid = WooCommerceBriefingAdapter::getBriefingUuidFromOrder($orderId);

        return [
            'paid' => $order->is_paid(),
            'status' => $order->get_status(),
            'briefing_uuid' => $briefingUuid,
            'order_total' => $order->get_total(),
            'payment_method' => $order->get_payment_method(),
            'date_paid' => $order->get_date_paid() ? $order->get_date_paid()->format('Y-m-d H:i:s') : null
        ];
    }

    /**
     * Reprocessar pagamento manualmente
     *
     * Útil para corrigir falhas de webhook ou processamento.
     *
     * @param int $orderId WooCommerce Order ID
     * @return array{success: bool, message: string}
     */
    public function reprocessPayment(int $orderId): array
    {
        try {
            $orderStatus = $this->checkOrderPaymentStatus($orderId);

            if (!$orderStatus['paid']) {
                return [
                    'success' => false,
                    'message' => 'Order não está paga. Status: ' . $orderStatus['status']
                ];
            }

            if ($orderStatus['briefing_uuid'] === null) {
                return [
                    'success' => false,
                    'message' => 'Order não está vinculada a um Briefing'
                ];
            }

            // Forçar reprocessamento
            $this->processPayment($orderId, 'manual_reprocess');

            return [
                'success' => true,
                'message' => "Pagamento reprocessado para Briefing: {$orderStatus['briefing_uuid']}"
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao reprocessar: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Log de informação
     *
     * @param string $message
     * @return void
     */
    private function logInfo(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix][BriefingPayment][INFO] ' . $message);
        }
    }

    /**
     * Log de warning
     *
     * @param string $message
     * @return void
     */
    private function logWarning(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix][BriefingPayment][WARNING] ' . $message);
        }
    }

    /**
     * Log de erro
     *
     * @param string $message
     * @return void
     */
    private function logError(string $message): void
    {
        error_log('[LimpVix][BriefingPayment][ERROR] ' . $message);
    }

    /**
     * Buscar todas Orders pendentes de processamento
     *
     * Útil para cron job de reprocessamento.
     *
     * @return array<int> Array de Order IDs
     */
    public function findPendingOrders(): array
    {
        global $wpdb;

        // Buscar orders com status 'processing' ou 'completed' que tenham produto de Briefing
        $sql = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} AS p
            INNER JOIN {$wpdb->postmeta} AS pm ON p.ID = pm.post_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_items AS oi ON p.ID = oi.order_id
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS oim ON oi.order_item_id = oim.order_item_id
            INNER JOIN {$wpdb->postmeta} AS pm2 ON oim.meta_value = pm2.post_id
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-completed')
            AND pm2.meta_key = '_limpvix_briefing_uuid'
            AND pm2.meta_value IS NOT NULL
            AND pm2.meta_value != ''
            LIMIT 100
        ";

        $results = $wpdb->get_col($sql);

        return array_map('intval', $results);
    }
}
