<?php
/**
 * FeedbackBootstrap - Core Bootstrap
 *
 * RESPONSABILIDADE:
 * - Inicializar módulo de feedback estruturado
 * - Registrar repositories
 * - Registrar Use Cases
 * - Registrar Event Dispatcher
 * - Registrar Admin UI
 *
 * @package LimpVix\Core
 * @since 0.3.0
 */

namespace LimpVix\Core;

use LimpVix\Infrastructure\Persistence\WpStructuredFeedbackRepository;
use LimpVix\Infrastructure\Events\FeedbackEventDispatcher;
use LimpVix\Application\UseCases\Feedback\SubmitStructuredFeedback;
use LimpVix\Application\UseCases\Feedback\DisputeFeedback;
use LimpVix\Application\Services\Feedback\FeedbackCompletenessValidator;

defined('ABSPATH') || exit;

class FeedbackBootstrap
{
    private static $initialized = false;

    /**
     * Inicializar módulo de feedback
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        // 1. Registrar Repository
        $feedbackRepository = new WpStructuredFeedbackRepository();

        // 2. Registrar Event Dispatcher
        $eventDispatcher = new FeedbackEventDispatcher();

        // 3. Registrar Use Cases
        $submitFeedbackUseCase = new SubmitStructuredFeedback(
            $feedbackRepository,
            $eventDispatcher
        );

        $disputeFeedbackUseCase = new DisputeFeedback(
            $feedbackRepository,
            $eventDispatcher
        );

        // 4. Registrar Services
        $completenessValidator = new FeedbackCompletenessValidator();

        // 5. Registrar Admin UI (se no admin)
        if (is_admin()) {
            self::registerAdminPages($feedbackRepository, $completenessValidator);
        }

        // 6. Registrar REST API endpoints (futuro)
        // self::registerRestAPI($submitFeedbackUseCase, $disputeFeedbackUseCase);

        error_log('[LimpVix] Feedback module initialized successfully');
    }

    /**
     * Registrar páginas administrativas
     *
     * @param WpStructuredFeedbackRepository $repository Repository
     * @param FeedbackCompletenessValidator $validator Validator
     * @return void
     */
    private static function registerAdminPages(
        WpStructuredFeedbackRepository $repository,
        FeedbackCompletenessValidator $validator
    ): void {
        add_action('admin_menu', function () use ($repository, $validator) {
            // Submenu: LimpVix → Feedbacks
            add_submenu_page(
                'limpvix-orders',
                'Structured Feedbacks',
                'Feedbacks',
                'manage_options',
                'limpvix-feedbacks',
                function () use ($repository, $validator) {
                    self::renderFeedbacksPage($repository, $validator);
                }
            );
        });
    }

    /**
     * Renderizar página de Feedbacks
     *
     * @param WpStructuredFeedbackRepository $repository Repository
     * @param FeedbackCompletenessValidator $validator Validator
     * @return void
     */
    private static function renderFeedbacksPage(
        WpStructuredFeedbackRepository $repository,
        FeedbackCompletenessValidator $validator
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_structured_feedbacks';

        // Buscar últimos 100 feedbacks
        $feedbacks = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );

        ?>
        <div class="wrap">
            <h1>Structured Feedbacks</h1>
            <p>Feedbacks estruturados com checklist arbitrável</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>UUID</th>
                        <th>Order</th>
                        <th>Cliente</th>
                        <th>Categoria</th>
                        <th>Score Final</th>
                        <th>Status</th>
                        <th>Critérios</th>
                        <th>Fotos</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feedbacks)): ?>
                        <tr>
                            <td colspan="9">Nenhum feedback encontrado. Execute a migration 009.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($feedbacks as $feedback): ?>
                            <?php
                            $checklistData = json_decode($feedback['checklist_data'], true);
                            $photosData = json_decode($feedback['photos'], true);
                            $criteriaCount = count($checklistData['criteria'] ?? []);
                            $photosCount = count($photosData ?? []);
                            ?>
                            <tr>
                                <td><code style="font-size: 10px;"><?php echo esc_html(substr($feedback['uuid'], 0, 20)); ?>...</code></td>
                                <td><code style="font-size: 10px;"><?php echo esc_html(substr($feedback['order_uuid'], 0, 15)); ?>...</code></td>
                                <td><?php echo esc_html($feedback['customer_id']); ?></td>
                                <td><span class="badge"><?php echo esc_html($feedback['service_category']); ?></span></td>
                                <td>
                                    <strong style="color: <?php echo $feedback['final_score'] >= 4 ? 'green' : ($feedback['final_score'] >= 3 ? 'orange' : 'red'); ?>">
                                        <?php echo number_format($feedback['final_score'], 2); ?>/5.00
                                    </strong>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo esc_attr($feedback['status']); ?>">
                                        <?php echo esc_html(strtoupper($feedback['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($criteriaCount); ?> critérios</td>
                                <td><?php echo esc_html($photosCount); ?> fotos</td>
                                <td><?php echo esc_html($feedback['submitted_at'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <style>
            .badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: bold;
                background: #ddd;
                color: #333;
            }
            .badge-submitted { background: #4caf50; color: white; }
            .badge-validated { background: #2196f3; color: white; }
            .badge-disputed { background: #f44336; color: white; }
            .badge-draft { background: #ff9800; color: white; }
        </style>
        <?php
    }
}
