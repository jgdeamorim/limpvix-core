<?php

declare(strict_types=1);

namespace LimpVix\Core;

use LimpVix\Infrastructure\Admin\Pages\ScheduleManagementPage;
use LimpVix\Infrastructure\Admin\Pages\ProfessionalAvailabilityPage;
use LimpVix\Infrastructure\Admin\Settings\SchedulingSettings;
use LimpVix\Infrastructure\Integration\FinanceSchedulingListener;
use LimpVix\Infrastructure\Integration\BriefingSchedulingListener;
use LimpVix\Infrastructure\Integration\FeedbackSchedulingListener;

/**
 * Bootstrap: Scheduling Module
 *
 * Inicializa módulo de Scheduling:
 * - Admin Pages
 * - Settings
 * - Event Listeners
 * - Integration Hooks
 */
final class SchedulingBootstrap
{
    private static bool $initialized = false;

    /**
     * Inicializa módulo de Scheduling
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // 1. Registrar Admin Pages
        self::registerAdminPages();

        // 2. Registrar Settings
        self::registerSettings();

        // 3. Registrar Integration Listeners
        self::registerListeners();

        // 4. Registrar WordPress Hooks
        self::registerHooks();

        self::$initialized = true;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix] Scheduling Module initialized');
        }
    }

    /**
     * Registra Admin Pages
     */
    private static function registerAdminPages(): void
    {
        if (is_admin()) {
            ScheduleManagementPage::init();
            ProfessionalAvailabilityPage::init();
        }
    }

    /**
     * Registra Settings
     */
    private static function registerSettings(): void
    {
        if (is_admin()) {
            SchedulingSettings::init();
        }
    }

    /**
     * Registra Integration Listeners
     */
    private static function registerListeners(): void
    {
        FinanceSchedulingListener::init();
        BriefingSchedulingListener::init();
        FeedbackSchedulingListener::init();
    }

    /**
     * Registra WordPress Hooks
     */
    private static function registerHooks(): void
    {
        // Hook: Exibir schedules no admin bar (quick info)
        add_action('admin_bar_menu', [__CLASS__, 'addAdminBarInfo'], 999);

        // Hook: Dashboard widget
        add_action('wp_dashboard_setup', [__CLASS__, 'addDashboardWidget']);
    }

    /**
     * Adiciona info de schedules no admin bar
     *
     * @param \WP_Admin_Bar $adminBar
     */
    public static function addAdminBarInfo(\WP_Admin_Bar $adminBar): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_schedules';

        $inProgress = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'in_progress'"
        );

        $slaViolations = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE sla_violation IS NOT NULL AND status != 'completed'"
        );

        $adminBar->add_node([
            'id' => 'limpvix-scheduling',
            'title' => sprintf(
                '⏱️ Schedules: %d em progresso%s',
                $inProgress,
                $slaViolations > 0 ? " | <span style='color:#f00'>$slaViolations SLA</span>" : ''
            ),
            'href' => admin_url('admin.php?page=limpvix-schedules'),
        ]);
    }

    /**
     * Adiciona widget no dashboard
     */
    public static function addDashboardWidget(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'limpvix_scheduling_widget',
            'LimpVix - Agendamentos',
            [__CLASS__, 'renderDashboardWidget']
        );
    }

    /**
     * Renderiza widget do dashboard
     */
    public static function renderDashboardWidget(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_schedules';

        $stats = [
            'draft' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'draft'"),
            'allocated' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'allocated'"),
            'in_progress' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'in_progress'"),
            'completed_today' => (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = 'completed' AND DATE(updated_at) = %s",
                    current_time('Y-m-d')
                )
            ),
            'sla_violations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE sla_violation IS NOT NULL AND status != 'completed'"),
        ];

        ?>
        <div class="limpvix-scheduling-widget">
            <div class="widget-stats">
                <div class="stat-item">
                    <span class="stat-label">Rascunhos:</span>
                    <span class="stat-value"><?php echo esc_html($stats['draft']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Alocados:</span>
                    <span class="stat-value"><?php echo esc_html($stats['allocated']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Em Progresso:</span>
                    <span class="stat-value"><?php echo esc_html($stats['in_progress']); ?></span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Concluídos Hoje:</span>
                    <span class="stat-value"><?php echo esc_html($stats['completed_today']); ?></span>
                </div>
                <?php if ($stats['sla_violations'] > 0): ?>
                    <div class="stat-item stat-danger">
                        <span class="stat-label">⚠️ Violações SLA:</span>
                        <span class="stat-value"><?php echo esc_html($stats['sla_violations']); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <p style="margin-top: 15px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-schedules')); ?>" class="button button-primary">
                    Ver Todos os Agendamentos
                </a>
            </p>
        </div>

        <style>
            .limpvix-scheduling-widget .widget-stats {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .limpvix-scheduling-widget .stat-item {
                display: flex;
                justify-content: space-between;
                padding: 8px;
                background: #f5f5f5;
                border-radius: 4px;
            }
            .limpvix-scheduling-widget .stat-item.stat-danger {
                background: #ffebee;
                border-left: 3px solid #d32f2f;
            }
            .limpvix-scheduling-widget .stat-label {
                font-weight: 500;
            }
            .limpvix-scheduling-widget .stat-value {
                font-weight: 700;
                color: #0073aa;
            }
            .limpvix-scheduling-widget .stat-item.stat-danger .stat-value {
                color: #d32f2f;
            }
        </style>
        <?php
    }
}
