<?php
/**
 * CommunicationBootstrap - Core Bootstrap
 *
 * RESPONSABILIDADE:
 * - Inicializar módulo de comunicação event-driven
 * - Registrar repositories
 * - Registrar Use Cases
 * - Registrar Event Listeners
 * - Conectar WP Cron hooks
 *
 * @package LimpVix\Core
 * @since 0.3.0
 */

namespace LimpVix\Core;

use LimpVix\Infrastructure\Persistence\WpMessageTemplateRepository;
use LimpVix\Infrastructure\Persistence\WpMessageLogRepository;
use LimpVix\Infrastructure\Persistence\WpMessageQueueRepository;
use LimpVix\Infrastructure\Events\CommunicationEventDispatcher;
use LimpVix\Infrastructure\Integration\OrderCommunicationListener;
use LimpVix\Infrastructure\Integration\MessageQueueCronListener;
use LimpVix\Application\Services\Communication\MessageQueueService;
use LimpVix\Application\UseCases\Communication\SendTemplatedMessage;

defined('ABSPATH') || exit;

class CommunicationBootstrap
{
    private static $initialized = false;

    /**
     * Inicializar módulo de comunicação
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        // 1. Registrar repositories
        $templateRepository = new WpMessageTemplateRepository();
        $logRepository = new WpMessageLogRepository();
        $queueRepository = new WpMessageQueueRepository();

        // 2. Registrar Application Services
        $queueService = new MessageQueueService($queueRepository);

        // 3. Registrar Event Dispatcher
        $eventDispatcher = new CommunicationEventDispatcher();

        // 4. Registrar Use Cases
        // IMPORTANTE: communicationProvider será injetado quando criar os providers
        // Por enquanto, usar null (será corrigido na próxima fase)
        $communicationProvider = null; // TODO: Implementar WhatsApp360DialogProvider

        $sendTemplatedMessageUseCase = new SendTemplatedMessage(
            $templateRepository,
            $logRepository,
            $queueService,
            $communicationProvider,
            $eventDispatcher
        );

        // 5. Registrar Event Listeners
        $orderCommunicationListener = new OrderCommunicationListener($sendTemplatedMessageUseCase);
        $orderCommunicationListener->register();

        $queueCronListener = new MessageQueueCronListener($queueService, $sendTemplatedMessageUseCase);
        $queueCronListener->register();

        // 6. Registrar Admin UI (se no admin)
        if (is_admin()) {
            self::registerAdminPages($logRepository, $templateRepository);
        }

        // Log de inicialização
        error_log('[LimpVix] Communication module initialized successfully');
    }

    /**
     * Registrar páginas administrativas
     *
     * @param WpMessageLogRepository $logRepository Log repository
     * @param WpMessageTemplateRepository $templateRepository Template repository
     * @return void
     */
    private static function registerAdminPages(
        WpMessageLogRepository $logRepository,
        WpMessageTemplateRepository $templateRepository
    ): void {
        // Hook para registrar menu admin
        add_action('admin_menu', function () use ($logRepository, $templateRepository) {
            // Submenu: LimpVix → Message Log
            add_submenu_page(
                'limpvix-orders',
                'Message Log',
                'Message Log',
                'manage_options',
                'limpvix-message-log',
                function () use ($logRepository) {
                    self::renderMessageLogPage($logRepository);
                }
            );

            // Submenu: LimpVix → Templates
            add_submenu_page(
                'limpvix-orders',
                'Message Templates',
                'Templates',
                'manage_options',
                'limpvix-message-templates',
                function () use ($templateRepository) {
                    self::renderTemplatesPage($templateRepository);
                }
            );
        });
    }

    /**
     * Renderizar página Message Log
     *
     * @param WpMessageLogRepository $logRepository Log repository
     * @return void
     */
    private static function renderMessageLogPage(WpMessageLogRepository $logRepository): void
    {
        // Buscar últimas 100 mensagens
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_message_log';
        $logs = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100", ARRAY_A);

        ?>
        <div class="wrap">
            <h1>Message Log</h1>
            <p>Histórico de todas as mensagens enviadas (append-only)</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Message ID</th>
                        <th>Template</th>
                        <th>Recipient</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Retry</th>
                        <th>Sent At</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8">Nenhuma mensagem encontrada.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><code><?php echo esc_html($log['message_id']); ?></code></td>
                                <td><?php echo esc_html($log['template_id'] . ' v' . $log['template_version']); ?></td>
                                <td><?php echo esc_html($log['recipient']); ?></td>
                                <td><span class="badge"><?php echo esc_html($log['channel']); ?></span></td>
                                <td>
                                    <span class="badge badge-<?php echo esc_attr($log['status']); ?>">
                                        <?php echo esc_html(strtoupper($log['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log['retry_count']); ?></td>
                                <td><?php echo esc_html($log['sent_at'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($log['error_message']): ?>
                                        <abbr title="<?php echo esc_attr($log['error_message']); ?>">
                                            <?php echo esc_html($log['error_type']); ?>
                                        </abbr>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
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
            }
            .badge-sent { background: #4caf50; color: white; }
            .badge-delivered { background: #2196f3; color: white; }
            .badge-read { background: #9c27b0; color: white; }
            .badge-failed { background: #f44336; color: white; }
            .badge-pending { background: #ff9800; color: white; }
        </style>
        <?php
    }

    /**
     * Renderizar página Templates
     *
     * @param WpMessageTemplateRepository $templateRepository Template repository
     * @return void
     */
    private static function renderTemplatesPage(WpMessageTemplateRepository $templateRepository): void
    {
        // Buscar todos templates
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_message_templates';
        $templates = $wpdb->get_results("SELECT * FROM {$table} ORDER BY template_id, version DESC", ARRAY_A);

        ?>
        <div class="wrap">
            <h1>Message Templates</h1>
            <p>Templates versionados para comunicação event-driven</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Template ID</th>
                        <th>Version</th>
                        <th>Channel</th>
                        <th>Content</th>
                        <th>Required Variables</th>
                        <th>Active</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($templates)): ?>
                        <tr>
                            <td colspan="7">Nenhum template encontrado. Execute a migration 008.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($templates as $tpl): ?>
                            <tr>
                                <td><strong><?php echo esc_html($tpl['template_id']); ?></strong></td>
                                <td><code><?php echo esc_html($tpl['version']); ?></code></td>
                                <td><span class="badge"><?php echo esc_html($tpl['channel']); ?></span></td>
                                <td>
                                    <code style="font-size: 11px;">
                                        <?php echo esc_html(substr($tpl['content'], 0, 80)) . '...'; ?>
                                    </code>
                                </td>
                                <td>
                                    <code style="font-size: 10px;">
                                        <?php
                                        $vars = json_decode($tpl['required_variables'], true);
                                        echo esc_html(implode(', ', $vars));
                                        ?>
                                    </code>
                                </td>
                                <td>
                                    <?php if ($tpl['is_active']): ?>
                                        <span class="badge" style="background: #4caf50; color: white;">✓</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #ccc; color: #666;">✗</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($tpl['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
