<?php
declare(strict_types=1);

/**
 * FeedbackWindowMonitorWidget (GAP #1)
 *
 * Admin dashboard widget para monitorar feedback windows:
 * - Orders aguardando feedback (window ativa)
 * - Windows expiradas sem feedback (auto-approved)
 * - Manual approval button para intervenção
 *
 * FEATURES:
 * - Real-time count of active windows
 * - Alert for expired windows without feedback
 * - List of orders waiting for feedback
 * - Quick action buttons
 *
 * @package LimpVix\Infrastructure\Admin\Widgets
 * @since GAP #1
 */

namespace LimpVix\Infrastructure\Admin\Widgets;

defined('ABSPATH') || exit;

class FeedbackWindowMonitorWidget
{
    private const WIDGET_ID = 'limpvix_feedback_window_monitor';
    private const WIDGET_TITLE = 'Monitor de Feedback (24h)';

    /**
     * Register widget
     * DISABLED: Causing admin dashboard errors
     */
    public function register(): void
    {
        // DISABLED: Dashboard widget causing critical errors in admin
        // add_action('wp_dashboard_setup', [$this, 'addDashboardWidget']);
    }

    /**
     * Add dashboard widget
     */
    public function addDashboardWidget(): void
    {
        // Only show for administrators
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            self::WIDGET_ID,
            self::WIDGET_TITLE,
            [$this, 'renderWidget']
        );
    }

    /**
     * Render widget content
     */
    public function renderWidget(): void
    {
        global $wpdb;

        // Get statistics with error handling
        try {
            $stats = $this->getStatistics();
        } catch (\Exception $e) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Erro ao carregar Monitor de Feedback:</strong> ' . esc_html($e->getMessage());
            echo '</p></div>';
            error_log('[LimpVix] FeedbackWindowMonitorWidget error: ' . $e->getMessage());
            return;
        }

        ?>
        <div class="limpvix-feedback-window-monitor">
            <style>
                .limpvix-feedback-window-monitor {
                    font-size: 13px;
                }
                .limpvix-stats {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                    gap: 10px;
                    margin-bottom: 15px;
                }
                .limpvix-stat-card {
                    background: #f0f0f1;
                    padding: 12px;
                    border-radius: 4px;
                    text-align: center;
                }
                .limpvix-stat-number {
                    font-size: 24px;
                    font-weight: bold;
                    color: #2271b1;
                    display: block;
                }
                .limpvix-stat-label {
                    font-size: 11px;
                    color: #646970;
                    text-transform: uppercase;
                    margin-top: 4px;
                }
                .limpvix-alert {
                    background: #fcf3cf;
                    border-left: 4px solid #f39c12;
                    padding: 10px;
                    margin-bottom: 15px;
                }
                .limpvix-alert.success {
                    background: #d4edda;
                    border-left-color: #28a745;
                }
                .limpvix-orders-list {
                    max-height: 300px;
                    overflow-y: auto;
                }
                .limpvix-order-item {
                    padding: 8px;
                    border-bottom: 1px solid #e5e5e5;
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .limpvix-order-item:hover {
                    background: #f6f7f7;
                }
                .limpvix-order-info {
                    flex: 1;
                }
                .limpvix-order-uuid {
                    font-weight: 600;
                    font-size: 12px;
                }
                .limpvix-order-time {
                    font-size: 11px;
                    color: #646970;
                }
                .limpvix-order-actions {
                    display: flex;
                    gap: 5px;
                }
                .limpvix-btn {
                    padding: 4px 8px;
                    font-size: 11px;
                    border: none;
                    border-radius: 3px;
                    cursor: pointer;
                    text-decoration: none;
                }
                .limpvix-btn-primary {
                    background: #2271b1;
                    color: white;
                }
                .limpvix-btn-secondary {
                    background: #dcdcde;
                    color: #2c3338;
                }
            </style>

            <!-- Statistics Cards -->
            <div class="limpvix-stats">
                <div class="limpvix-stat-card">
                    <span class="limpvix-stat-number"><?php echo esc_html($stats['active_windows']); ?></span>
                    <span class="limpvix-stat-label">Windows Ativas</span>
                </div>
                <div class="limpvix-stat-card">
                    <span class="limpvix-stat-number"><?php echo esc_html($stats['expired_without_feedback']); ?></span>
                    <span class="limpvix-stat-label">Auto-aprovados (24h)</span>
                </div>
                <div class="limpvix-stat-card">
                    <span class="limpvix-stat-number"><?php echo esc_html($stats['feedback_submitted']); ?></span>
                    <span class="limpvix-stat-label">Feedback Recebido</span>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($stats['active_windows'] > 10): ?>
                <div class="limpvix-alert">
                    ⚠️ <strong>Atenção:</strong> <?php echo esc_html($stats['active_windows']); ?> orders aguardando feedback.
                </div>
            <?php elseif ($stats['active_windows'] === 0 && $stats['feedback_submitted'] > 0): ?>
                <div class="limpvix-alert success">
                    ✅ <strong>Ótimo!</strong> Todas as windows foram processadas.
                </div>
            <?php endif; ?>

            <!-- Active Windows List -->
            <?php if ($stats['active_windows'] > 0): ?>
                <h4 style="margin: 15px 0 10px;">Orders Aguardando Feedback</h4>
                <div class="limpvix-orders-list">
                    <?php
                    try {
                        $activeOrders = $this->getActiveWindows();
                        foreach ($activeOrders as $order):
                            $timeRemaining = $this->calculateTimeRemaining($order['feedback_window_expires_at']);
                        ?>
                            <div class="limpvix-order-item">
                                <div class="limpvix-order-info">
                                    <div class="limpvix-order-uuid">
                                        Order: <?php echo esc_html(substr($order['order_uuid'], 0, 8)); ?>...
                                    </div>
                                    <div class="limpvix-order-time">
                                        ⏱️ Expira em: <?php echo esc_html($timeRemaining); ?>
                                    </div>
                                </div>
                                <div class="limpvix-order-actions">
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-orders&order=' . $order['order_uuid'])); ?>"
                                       class="limpvix-btn limpvix-btn-secondary">
                                        Ver Order
                                    </a>
                                </div>
                            </div>
                        <?php endforeach;
                    } catch (\Exception $e) {
                        echo '<p style="color: #d32f2f; padding: 10px;">Erro ao carregar orders: ' . esc_html($e->getMessage()) . '</p>';
                        error_log('[LimpVix] FeedbackWindowMonitorWidget getActiveWindows error: ' . $e->getMessage());
                    }
                    ?>
                </div>
            <?php else: ?>
                <p style="color: #646970; text-align: center; padding: 20px;">
                    ✅ Nenhuma order aguardando feedback no momento.
                </p>
            <?php endif; ?>

            <!-- Footer -->
            <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #e5e5e5; text-align: center;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-feedback-report')); ?>"
                   class="limpvix-btn limpvix-btn-primary">
                    Ver Relatório Completo
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Get statistics
     *
     * @return array
     */
    private function getStatistics(): array
    {
        global $wpdb;

        $now = current_time('mysql');

        // Active windows (window not expired, no feedback)
        $activeWindows = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT e.order_uuid)
            FROM {$wpdb->prefix}limpvix_executions e
            LEFT JOIN {$wpdb->prefix}limpvix_structured_feedbacks f ON e.order_uuid = f.order_uuid
            WHERE e.feedback_window_expires_at IS NOT NULL
              AND e.feedback_window_expires_at > %s
              AND f.id IS NULL
        ", $now));

        // Expired without feedback (last 7 days)
        $expiredWithoutFeedback = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT e.order_uuid)
            FROM {$wpdb->prefix}limpvix_executions e
            LEFT JOIN {$wpdb->prefix}limpvix_structured_feedbacks f ON e.order_uuid = f.order_uuid
            WHERE e.feedback_window_expires_at IS NOT NULL
              AND e.feedback_window_expires_at <= %s
              AND e.feedback_window_expires_at >= DATE_SUB(%s, INTERVAL 7 DAY)
              AND f.id IS NULL
        ", $now, $now));

        // Feedback submitted (last 7 days)
        $feedbackSubmitted = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}limpvix_structured_feedbacks
            WHERE submitted_at >= DATE_SUB(%s, INTERVAL 7 DAY)
        ", $now));

        return [
            'active_windows' => (int) $activeWindows,
            'expired_without_feedback' => (int) $expiredWithoutFeedback,
            'feedback_submitted' => (int) $feedbackSubmitted,
        ];
    }

    /**
     * Get active windows (waiting for feedback)
     *
     * @param int $limit
     * @return array
     */
    private function getActiveWindows(int $limit = 10): array
    {
        global $wpdb;

        $now = current_time('mysql');

        return $wpdb->get_results($wpdb->prepare("
            SELECT e.order_uuid, e.feedback_window_expires_at
            FROM {$wpdb->prefix}limpvix_executions e
            LEFT JOIN {$wpdb->prefix}limpvix_structured_feedbacks f ON e.order_uuid = f.order_uuid
            WHERE e.feedback_window_expires_at IS NOT NULL
              AND e.feedback_window_expires_at > %s
              AND f.id IS NULL
            ORDER BY e.feedback_window_expires_at ASC
            LIMIT %d
        ", $now, $limit), ARRAY_A);
    }

    /**
     * Calculate human-readable time remaining
     *
     * @param string $expiresAt MySQL datetime
     * @return string
     */
    private function calculateTimeRemaining(string $expiresAt): string
    {
        $now = new \DateTimeImmutable();
        $expires = new \DateTimeImmutable($expiresAt);
        $diff = $expires->getTimestamp() - $now->getTimestamp();

        if ($diff <= 0) {
            return 'Expirado';
        }

        $hours = floor($diff / 3600);
        $minutes = floor(($diff % 3600) / 60);

        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }
}
