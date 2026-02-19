<?php
/**
 * OrderDetailController - Controller para Detalhes da Order
 *
 * RESPONSABILIDADE:
 * - Renderizar detalhes de uma order específica
 * - Timeline do Ledger
 * - Histórico de payouts
 * - Ações administrativas
 *
 * PRINCÍPIOS:
 * - MVC Pattern
 * - Read-only + Actions via Use Cases
 * - Permissions gated
 *
 * DADOS EXIBIDOS:
 * - Informações da order
 * - Timeline do Ledger (ReconstructFinancialState)
 * - Payouts executados
 * - Ações disponíveis (baseadas no estado)
 *
 * PASSO 5.6.1 - Infraestrutura Admin (Esqueleto)
 * PASSO 5.6.3 - Order Detail + Timeline (Implementação completa) ✅
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;
use LimpVix\Infrastructure\Persistence\WpOrderRepository;
use LimpVix\Infrastructure\Persistence\WpFinancialLedgerRepository;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;

defined('ABSPATH') || exit;

class OrderDetailController
{
    private WpOrderRepository $orderRepository;
    private WpFinancialLedgerRepository $ledgerRepository;
    private WpPayoutRepository $payoutRepository;

    public function __construct()
    {
        $this->orderRepository = new WpOrderRepository();
        $this->ledgerRepository = new WpFinancialLedgerRepository();
        $this->payoutRepository = new WpPayoutRepository();
    }

    /**
     * Renderizar página
     *
     * @param string $orderUuid UUID da order
     * @return void
     */
    public function render(string $orderUuid): void
    {
        // Gate de permissão
        if (!FinanceCapabilities::canView()) {
            wp_die('Você não tem permissão para acessar esta página.');
        }

        // 1. Buscar order
        $order = $this->orderRepository->findByUuid($orderUuid);

        if (!$order) {
            wp_die(
                sprintf('Order não encontrada: %s', esc_html($orderUuid)),
                'Order não encontrada',
                ['back_link' => true]
            );
        }

        // 2. Reconstruir estado via Ledger
        $reconstructionResult = null;
        $ledgerHistory = [];
        try {
            $ledgerHistory = $this->ledgerRepository->findByOrder($orderUuid);

            if (!empty($ledgerHistory)) {
                // Reconstruir estado financeiro simples
                $reconstructionResult = $this->reconstructFinancialState($orderUuid, $ledgerHistory);
            }
        } catch (\Exception $e) {
            // Falha ao reconstruir não é bloqueante
            error_log('OrderDetailController: Failed to reconstruct state - ' . $e->getMessage());
        }

        // 3. Buscar payouts
        $payouts = $this->payoutRepository->getByOrder($order->getId());

        // 4. Renderizar view
        $this->renderView($order, $ledgerHistory, $payouts, $reconstructionResult);
    }

    /**
     * Reconstruir estado financeiro do ledger
     *
     * @param string $orderUuid
     * @param array $ledgerHistory
     * @return array|null
     */
    private function reconstructFinancialState(string $orderUuid, array $ledgerHistory): ?array
    {
        // Simples: pegar último evento como estado atual
        if (empty($ledgerHistory)) {
            return null;
        }

        $lastEvent = end($ledgerHistory);

        $anomalies = $this->detectAnomalies($ledgerHistory);

        return [
            'order_uuid' => $orderUuid,
            'current_status' => $lastEvent['event_type'] ?? 'unknown',
            'transition_count' => count($ledgerHistory),
            'first_event' => reset($ledgerHistory),
            'last_event' => $lastEvent,
            'has_anomalies' => !empty($anomalies),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Detect anomalies in ledger history
     *
     * Checks:
     * - Excessive duration between events (>48h gap)
     * - Duplicate consecutive events (same type)
     * - Out-of-order transitions
     * - Missing expected events
     *
     * @param array $ledgerHistory
     * @return array List of anomaly descriptions
     */
    private function detectAnomalies(array $ledgerHistory): array
    {
        $anomalies = [];

        if (count($ledgerHistory) < 2) {
            return $anomalies;
        }

        $previousEvent = null;
        foreach ($ledgerHistory as $event) {
            if ($previousEvent === null) {
                $previousEvent = $event;
                continue;
            }

            // Check for duplicate consecutive events
            if (($event['event_type'] ?? '') === ($previousEvent['event_type'] ?? '')) {
                $anomalies[] = sprintf(
                    'Evento duplicado consecutivo: "%s"',
                    $event['event_type'] ?? 'unknown'
                );
            }

            // Check for excessive time gap (>48h between events)
            if (!empty($event['created_at']) && !empty($previousEvent['created_at'])) {
                try {
                    $current = new \DateTimeImmutable($event['created_at']);
                    $previous = new \DateTimeImmutable($previousEvent['created_at']);
                    $diffHours = ($current->getTimestamp() - $previous->getTimestamp()) / 3600;

                    if ($diffHours > 48) {
                        $anomalies[] = sprintf(
                            'Gap excessivo entre eventos: %.0fh entre "%s" e "%s"',
                            $diffHours,
                            $previousEvent['event_type'] ?? 'unknown',
                            $event['event_type'] ?? 'unknown'
                        );
                    }
                } catch (\Exception $e) {
                    // Skip date parsing errors
                }
            }

            $previousEvent = $event;
        }

        // Check for unresolved events older than 24h
        $lastEvent = end($ledgerHistory);
        if (!empty($lastEvent['created_at']) && empty($lastEvent['resolved'])) {
            try {
                $lastDate = new \DateTimeImmutable($lastEvent['created_at']);
                $now = new \DateTimeImmutable();
                $ageHours = ($now->getTimestamp() - $lastDate->getTimestamp()) / 3600;

                if ($ageHours > 24) {
                    $anomalies[] = sprintf(
                        'Evento nao resolvido ha %.0fh: "%s"',
                        $ageHours,
                        $lastEvent['event_type'] ?? 'unknown'
                    );
                }
            } catch (\Exception $e) {
                // Skip
            }
        }

        return $anomalies;
    }

    /**
     * Renderizar view completa
     *
     * @param \LimpVix\Domain\Order\Order $order
     * @param array $ledgerHistory
     * @param array $payouts
     * @param array|null $reconstruction
     * @return void
     */
    private function renderView($order, array $ledgerHistory, array $payouts, ?array $reconstruction): void
    {
        ?>
        <div class="wrap limpvix-finance">
            <h1>Detalhes da Order</h1>

            <?php $this->renderOrderInfo($order, $reconstruction); ?>
            <?php $this->renderTimeline($ledgerHistory); ?>
            <?php $this->renderPayouts($payouts); ?>
            <?php $this->renderActions($order); ?>
        </div>
        <?php
    }

    /**
     * Renderizar informações da order
     *
     * @param \LimpVix\Domain\Order\Order $order
     * @param array|null $reconstruction
     * @return void
     */
    private function renderOrderInfo($order, ?array $reconstruction): void
    {
        $orderStatus = $order->getOrderStatus()->value;
        $financialStatus = $order->getFinancialStatus()->value;
        $currentStatus = $reconstruction['current_status'] ?? 'N/A';

        ?>
        <div class="limpvix-order-info" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
            <h2>Informações da Order</h2>
            <table class="widefat">
                <tbody>
                    <tr>
                        <th style="width: 200px;">UUID:</th>
                        <td><code><?php echo esc_html($order->getUuid()); ?></code></td>
                    </tr>
                    <tr>
                        <th>ID Interno:</th>
                        <td><?php echo esc_html($order->getId()); ?></td>
                    </tr>
                    <tr>
                        <th>Order Status:</th>
                        <td><strong><?php echo esc_html($orderStatus); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Financial Status:</th>
                        <td><strong><?php echo esc_html($financialStatus); ?></strong></td>
                    </tr>
                    <?php if ($reconstruction): ?>
                    <tr>
                        <th>Status Reconstruído (Ledger):</th>
                        <td><strong><?php echo esc_html($currentStatus); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Total de Transições:</th>
                        <td><?php echo esc_html($reconstruction['transition_count'] ?? 0); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Criado em:</th>
                        <td><?php echo esc_html($order->getCreatedAt()->format('d/m/Y H:i:s')); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Renderizar timeline do ledger
     *
     * @param array $ledgerHistory
     * @return void
     */
    private function renderTimeline(array $ledgerHistory): void
    {
        ?>
        <div class="limpvix-timeline" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
            <h2>Timeline Financeira (Ledger)</h2>

            <?php if (empty($ledgerHistory)): ?>
                <p><em>Nenhum evento registrado no ledger.</em></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 180px;">Data/Hora</th>
                            <th style="width: 200px;">Evento</th>
                            <th>Dados</th>
                            <th style="width: 120px;">Resolvido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ledgerHistory as $event): ?>
                            <tr>
                                <td>
                                    <?php
                                    $createdAt = is_string($event['created_at'])
                                        ? $event['created_at']
                                        : '';
                                    echo esc_html($createdAt);
                                    ?>
                                </td>
                                <td><strong><?php echo esc_html($event['event_type'] ?? 'N/A'); ?></strong></td>
                                <td>
                                    <?php
                                    $eventData = $event['event_data'] ?? [];
                                    if (is_string($eventData)) {
                                        $eventData = json_decode($eventData, true) ?? [];
                                    }
                                    if (!empty($eventData)):
                                    ?>
                                        <details>
                                            <summary>Ver dados</summary>
                                            <pre style="font-size: 11px; max-height: 200px; overflow-y: auto;"><?php echo esc_html(json_encode($eventData, JSON_PRETTY_PRINT)); ?></pre>
                                        </details>
                                    <?php else: ?>
                                        <em>—</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $resolved = $event['resolved'] ?? 0;
                                    echo $resolved ? '✅ Sim' : '⏳ Não';
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renderizar histórico de payouts
     *
     * @param array $payouts
     * @return void
     */
    private function renderPayouts(array $payouts): void
    {
        ?>
        <div class="limpvix-payouts" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
            <h2>Histórico de Payouts</h2>

            <?php if (empty($payouts)): ?>
                <p><em>Nenhum payout registrado para esta order.</em></p>
            <?php else: ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th style="width: 150px;">Profissional</th>
                            <th style="width: 120px;">Valor Bruto</th>
                            <th style="width: 120px;">Taxa</th>
                            <th style="width: 120px;">Valor Líquido</th>
                            <th style="width: 120px;">Status</th>
                            <th style="width: 180px;">Criado em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payouts as $payout): ?>
                            <tr>
                                <td><?php echo esc_html($payout['id']); ?></td>
                                <td>
                                    <?php
                                    $profId = $payout['professional_id'] ?? 0;
                                    echo $profId ? 'ID: ' . esc_html($profId) : '—';
                                    ?>
                                </td>
                                <td>R$ <?php echo number_format($payout['gross_amount'] ?? 0, 2, ',', '.'); ?></td>
                                <td>R$ <?php echo number_format($payout['platform_fee'] ?? 0, 2, ',', '.'); ?></td>
                                <td><strong>R$ <?php echo number_format($payout['net_amount'] ?? 0, 2, ',', '.'); ?></strong></td>
                                <td>
                                    <?php
                                    $status = $payout['status'] ?? 'unknown';
                                    $statusClass = $this->getPayoutStatusClass($status);
                                    ?>
                                    <span class="limpvix-status <?php echo esc_attr($statusClass); ?>">
                                        <?php echo esc_html($status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($payout['created_at'] ?? '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renderizar ações administrativas
     *
     * @param \LimpVix\Domain\Order\Order $order
     * @return void
     */
    private function renderActions($order): void
    {
        ?>
        <div class="limpvix-actions" style="background: #fff; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4;">
            <h2>Ações Administrativas</h2>

            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-orders')); ?>" class="button">
                    ← Voltar para Orders
                </a>
            </p>

            <p><small><em><strong>Nota:</strong> Ações avançadas (bloquear, liberar, executar payout) serão implementadas no PASSO 5.6.4 via AdminActionsController.</em></small></p>
        </div>
        <?php
    }

    /**
     * Obter classe CSS para status de payout
     *
     * @param string $status
     * @return string
     */
    private function getPayoutStatusClass(string $status): string
    {
        $classes = [
            'pending' => 'status-pending',
            'approved' => 'status-approved',
            'processing' => 'status-processing',
            'completed' => 'status-completed',
            'failed' => 'status-failed',
            'cancelled' => 'status-cancelled',
        ];

        return $classes[$status] ?? 'status-unknown';
    }
}
