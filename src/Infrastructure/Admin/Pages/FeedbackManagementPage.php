<?php
/**
 * FeedbackManagementPage
 *
 * Gerenciamento de feedbacks negativos (C2 - ≤3⭐)
 * - Listagem de casos que requerem atenção manual
 * - Envio de respostas personalizadas
 * - Resolução de casos
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.3
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Domain\Support\FeedbackCaseStatus;
use LimpVix\Infrastructure\Persistence\WpFeedbackCaseRepository;

class FeedbackManagementPage
{
    /**
     * @var WpFeedbackCaseRepository
     */
    private static $repository;

    /**
     * Obter instância do repository
     *
     * @return WpFeedbackCaseRepository
     */
    private static function getRepository(): WpFeedbackCaseRepository
    {
        if (self::$repository === null) {
            self::$repository = new WpFeedbackCaseRepository();
        }
        return self::$repository;
    }

    /**
     * Registrar hooks
     */
    public static function register(): void
    {
        add_action('admin_post_limpvix_send_manual_response', [__CLASS__, 'handleSendManualResponse']);
        add_action('admin_post_limpvix_resolve_feedback', [__CLASS__, 'handleResolveFeedback']);
        add_action('wp_ajax_limpvix_get_feedback_details', [__CLASS__, 'handleGetFeedbackDetails']);
    }

    /**
     * Renderizar página
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $cases = $this->getNegativeFeedbackCases();
        $stats = $this->getStats($cases);

        $message = $_GET['message'] ?? '';
        $filter = $_GET['filter'] ?? 'pending';

        ?>
        <div class="wrap">
            <h1>⚠️ Feedback Negativo (C2)</h1>
            <p class="description">Gerenciar feedbacks ≤3⭐ que requerem atendimento manual</p>

            <?php if ($message === 'sent'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Resposta enviada com sucesso!</strong></p>
                </div>
            <?php elseif ($message === 'resolved'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Caso marcado como resolvido.</strong></p>
                </div>
            <?php elseif ($message === 'error'): ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>❌ Erro ao processar a ação. Tente novamente.</strong></p>
                </div>
            <?php endif; ?>

            <!-- Estatísticas -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 24px 0;">
                <div style="background: #fee2e2; border-left: 4px solid #ef4444; padding: 16px; border-radius: 4px;">
                    <div style="font-size: 12px; color: #991b1b; font-weight: 600; text-transform: uppercase;">Casos Pendentes</div>
                    <div style="font-size: 32px; font-weight: 700; color: #991b1b; margin-top: 8px;"><?php echo $stats['pending']; ?></div>
                </div>
                <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px; border-radius: 4px;">
                    <div style="font-size: 12px; color: #92400e; font-weight: 600; text-transform: uppercase;">Em Atendimento</div>
                    <div style="font-size: 32px; font-weight: 700; color: #92400e; margin-top: 8px;"><?php echo $stats['in_progress']; ?></div>
                </div>
                <div style="background: #d1fae5; border-left: 4px solid #10b981; padding: 16px; border-radius: 4px;">
                    <div style="font-size: 12px; color: #065f46; font-weight: 600; text-transform: uppercase;">Resolvidos (30d)</div>
                    <div style="font-size: 32px; font-weight: 700; color: #065f46; margin-top: 8px;"><?php echo $stats['resolved_30d']; ?></div>
                </div>
                <div style="background: #e0e7ff; border-left: 4px solid #6366f1; padding: 16px; border-radius: 4px;">
                    <div style="font-size: 12px; color: #3730a3; font-weight: 600; text-transform: uppercase;">Taxa de Resolução</div>
                    <div style="font-size: 32px; font-weight: 700; color: #3730a3; margin-top: 8px;"><?php echo $stats['resolution_rate']; ?>%</div>
                </div>
            </div>

            <!-- Informação sobre Fluxo C2 -->
            <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; margin: 24px 0; border-radius: 4px;">
                <h3 style="margin: 0 0 12px 0; color: #856404;">🔒 Por que o Fluxo C2 está bloqueado?</h3>
                <p style="margin: 0; color: #856404;">
                    Feedbacks negativos (≤3⭐) <strong>requerem atenção humana personalizada</strong>.
                    Mensagens automáticas podem agravar a situação ou parecer insensíveis.
                    Esta página permite que você revise cada caso e envie respostas apropriadas.
                </p>
            </div>

            <!-- Filtros -->
            <div style="margin: 24px 0;">
                <a href="<?php echo add_query_arg(['page' => 'limpvix-feedback-management', 'filter' => 'pending'], admin_url('admin.php')); ?>"
                   class="button <?php echo $filter === 'pending' ? 'button-primary' : ''; ?>">
                    ⏳ Pendentes (<?php echo $stats['pending']; ?>)
                </a>
                <a href="<?php echo add_query_arg(['page' => 'limpvix-feedback-management', 'filter' => 'in_progress'], admin_url('admin.php')); ?>"
                   class="button <?php echo $filter === 'in_progress' ? 'button-primary' : ''; ?>">
                    🔄 Em Atendimento (<?php echo $stats['in_progress']; ?>)
                </a>
                <a href="<?php echo add_query_arg(['page' => 'limpvix-feedback-management', 'filter' => 'resolved'], admin_url('admin.php')); ?>"
                   class="button <?php echo $filter === 'resolved' ? 'button-primary' : ''; ?>">
                    ✅ Resolvidos
                </a>
                <a href="<?php echo add_query_arg(['page' => 'limpvix-feedback-management', 'filter' => 'all'], admin_url('admin.php')); ?>"
                   class="button <?php echo $filter === 'all' ? 'button-primary' : ''; ?>">
                    📋 Todos
                </a>
            </div>

            <!-- Listagem de Casos -->
            <?php if (empty($cases)): ?>
                <div style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <p><strong>✨ Nenhum caso encontrado!</strong></p>
                    <p>Não há feedbacks negativos pendentes no momento.</p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Order</th>
                            <th>Cliente / Serviço</th>
                            <th style="width: 80px;">Rating</th>
                            <th>Comentário</th>
                            <th style="width: 120px;">Data</th>
                            <th style="width: 120px;">Status</th>
                            <th style="width: 200px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $case): ?>
                            <?php
                            if ($filter !== 'all' && $case['case_status'] !== $filter) {
                                continue;
                            }
                            ?>
                            <tr>
                                <td><code>#<?php echo esc_html($case['order_id']); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html($case['customer_name']); ?></strong>
                                    <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                        <?php echo esc_html($case['service_name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size: 20px; color: #ef4444;">
                                        <?php echo str_repeat('⭐', $case['rating']); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($case['comment'])): ?>
                                        <div style="font-size: 13px; color: #374151; max-width: 300px;">
                                            "<?php echo esc_html(wp_trim_words($case['comment'], 15)); ?>"
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #9ca3af;">Sem comentário</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($case['feedback_date']); ?></td>
                                <td><?php echo $this->renderStatusBadge($case['case_status']); ?></td>
                                <td>
                                    <button type="button" class="button button-primary button-small" onclick="openResponseModal(<?php echo esc_attr($case['order_id']); ?>)">
                                        💬 Responder
                                    </button>
                                    <?php if ($case['case_status'] !== 'resolved'): ?>
                                        <button type="button" class="button button-small" onclick="markAsResolved(<?php echo esc_attr($case['order_id']); ?>)">
                                            ✅ Resolver
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Modal de Resposta -->
        <div id="response-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; justify-content: center; align-items: center;">
            <div style="background: white; padding: 24px; border-radius: 8px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h2 style="margin: 0;">💬 Enviar Resposta Manual</h2>
                    <button onclick="closeResponseModal()" class="button" style="font-size: 20px; padding: 0 12px;">✕</button>
                </div>

                <div id="case-details" style="background: #f9fafb; padding: 16px; border-radius: 4px; margin-bottom: 16px;">
                    Carregando detalhes...
                </div>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="response-form">
                    <input type="hidden" name="action" value="limpvix_send_manual_response">
                    <input type="hidden" name="order_id" id="response-order-id">
                    <?php wp_nonce_field('limpvix_send_manual_response'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="response_channel">Canal</label></th>
                            <td>
                                <select id="response_channel" name="response_channel" required>
                                    <option value="whatsapp">💬 WhatsApp</option>
                                    <option value="sms">📱 SMS</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="response_message">Mensagem</label></th>
                            <td>
                                <textarea id="response_message" name="response_message" rows="8" class="large-text" required placeholder="Digite sua mensagem personalizada aqui..."></textarea>
                                <p class="description">
                                    <strong>Dicas:</strong><br>
                                    • Seja empático e agradeça o feedback<br>
                                    • Reconheça o problema específico mencionado<br>
                                    • Ofereça uma solução ou compensação<br>
                                    • Mantenha tom profissional e humano
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mark_resolved">Marcar como resolvido?</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="mark_resolved" name="mark_resolved" value="1">
                                    Marcar este caso como resolvido após enviar
                                </label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit" style="margin: 0; padding-top: 16px;">
                        <button type="submit" class="button button-primary">📤 Enviar Resposta</button>
                        <button type="button" onclick="closeResponseModal()" class="button">Cancelar</button>
                    </p>
                </form>
            </div>
        </div>

        <script>
        function openResponseModal(orderId) {
            const modal = document.getElementById('response-modal');
            const details = document.getElementById('case-details');
            const orderInput = document.getElementById('response-order-id');

            modal.style.display = 'flex';
            orderInput.value = orderId;
            details.innerHTML = 'Carregando detalhes do caso...';

            // AJAX para buscar detalhes
            jQuery.post(ajaxurl, {
                action: 'limpvix_get_feedback_details',
                order_id: orderId,
                nonce: '<?php echo wp_create_nonce('limpvix_get_feedback_details'); ?>'
            }, function(response) {
                if (response.success) {
                    details.innerHTML = response.data.html;
                } else {
                    details.innerHTML = '<p style="color: #ef4444;">❌ Erro ao carregar detalhes.</p>';
                }
            });
        }

        function closeResponseModal() {
            document.getElementById('response-modal').style.display = 'none';
            document.getElementById('response-form').reset();
        }

        function markAsResolved(orderId) {
            if (!confirm('Tem certeza que deseja marcar este caso como resolvido?')) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo admin_url('admin-post.php'); ?>';

            const fields = {
                action: 'limpvix_resolve_feedback',
                order_id: orderId,
                _wpnonce: '<?php echo wp_create_nonce('limpvix_resolve_feedback'); ?>'
            };

            for (const [key, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
        }

        // Close modal on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeResponseModal();
            }
        });
        </script>
        <?php
    }

    /**
     * Buscar casos de feedback negativo
     */
    /**
     * Get negative feedback cases from database
     *
     * FASE 1.2 - ADMIN UI REFACTORING:
     * - REMOVED mock data
     * - Implemented real database query
     * - Joins with users and executions tables for complete data
     *
     * @return array Array of negative feedback cases (score <= 3)
     */
    private function getNegativeFeedbackCases(): array
    {
        global $wpdb;

        $feedbackTable = $wpdb->prefix . 'limpvix_feedback';
        $usersTable = $wpdb->users;
        $executionsTable = $wpdb->prefix . 'limpvix_contract_executions';
        $professionalsTable = $wpdb->prefix . 'limpvix_professionals';

        // Query real data from limpvix_feedback table
        $results = $wpdb->get_results(
            "SELECT
                f.id,
                f.execution_id as order_id,
                u.display_name as customer_name,
                CONCAT('Execução #', f.execution_id) as service_name,
                f.score as rating,
                f.comment,
                f.created_at as feedback_date,
                CASE
                    WHEN f.admin_response IS NULL THEN 'pending'
                    WHEN f.admin_response IS NOT NULL AND f.resolved_at IS NULL THEN 'in_progress'
                    WHEN f.resolved_at IS NOT NULL THEN 'resolved'
                    ELSE 'pending'
                END as case_status,
                p.full_name as professional_name,
                f.professional_id
            FROM {$feedbackTable} f
            LEFT JOIN {$usersTable} u ON f.client_user_id = u.ID
            LEFT JOIN {$executionsTable} e ON f.execution_id = e.id
            LEFT JOIN {$professionalsTable} p ON f.professional_id = p.id
            WHERE f.score <= 3
            ORDER BY f.created_at DESC
            LIMIT 50",
            ARRAY_A
        );

        // Return empty array if no results (instead of mock data)
        if (empty($results)) {
            return [];
        }

        return $results;
    }

    /**
     * Calcular estatísticas
     */
    private function getStats(array $cases): array
    {
        $pending = 0;
        $in_progress = 0;
        $resolved_30d = 0;
        $total = count($cases);

        foreach ($cases as $case) {
            switch ($case['case_status']) {
                case 'pending':
                    $pending++;
                    break;
                case 'in_progress':
                    $in_progress++;
                    break;
                case 'resolved':
                    $resolved_30d++;
                    break;
            }
        }

        $resolution_rate = $total > 0 ? round(($resolved_30d / $total) * 100) : 0;

        return [
            'pending' => $pending,
            'in_progress' => $in_progress,
            'resolved_30d' => $resolved_30d,
            'resolution_rate' => $resolution_rate,
        ];
    }

    /**
     * Renderizar badge de status
     */
    private function renderStatusBadge(string $status): string
    {
        $badges = [
            'pending' => '<span style="background: #fee2e2; color: #991b1b; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">⏳ Pendente</span>',
            'in_progress' => '<span style="background: #fef3c7; color: #92400e; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">🔄 Em Atendimento</span>',
            'resolved' => '<span style="background: #d1fae5; color: #065f46; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">✅ Resolvido</span>',
        ];

        return $badges[$status] ?? '<span style="color: #6b7280;">—</span>';
    }

    /**
     * Handler: Enviar resposta manual
     */
    public static function handleSendManualResponse(): void
    {
        check_admin_referer('limpvix_send_manual_response');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $orderId = absint($_POST['order_id']);
        $channel = sanitize_text_field($_POST['response_channel']);
        $message = sanitize_textarea_field($_POST['response_message']);
        $markResolved = !empty($_POST['mark_resolved']);

        // TODO: Implementar envio real via MessageDispatcher
        // Por enquanto, apenas simular
        $sent = true;

        if ($sent && $markResolved) {
            // Atualizar status do caso usando repository
            $repository = self::getRepository();
            $repository->saveStatus($orderId, FeedbackCaseStatus::resolved());
        }

        wp_redirect(add_query_arg([
            'page' => 'limpvix-feedback-management',
            'message' => $sent ? 'sent' : 'error'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handler: Marcar como resolvido
     */
    public static function handleResolveFeedback(): void
    {
        check_admin_referer('limpvix_resolve_feedback');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $orderId = absint($_POST['order_id']);

        // Atualizar status usando repository
        $repository = self::getRepository();
        $repository->saveStatus($orderId, FeedbackCaseStatus::resolved());

        wp_redirect(add_query_arg([
            'page' => 'limpvix-feedback-management',
            'message' => 'resolved'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handler AJAX: Buscar detalhes do feedback
     */
    public static function handleGetFeedbackDetails(): void
    {
        check_ajax_referer('limpvix_get_feedback_details', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão']);
        }

        $orderId = absint($_POST['order_id']);

        // TODO: Buscar dados reais do banco
        // Por enquanto, retornar mock
        $html = '
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
            <div>
                <strong style="color: #6b7280; font-size: 12px;">Order</strong>
                <div style="font-size: 16px; font-weight: 600;">#' . $orderId . '</div>
            </div>
            <div>
                <strong style="color: #6b7280; font-size: 12px;">Cliente</strong>
                <div style="font-size: 16px; font-weight: 600;">Ana Paula Silva</div>
            </div>
            <div>
                <strong style="color: #6b7280; font-size: 12px;">Serviço</strong>
                <div style="font-size: 14px;">Limpeza Residencial Básica</div>
            </div>
            <div>
                <strong style="color: #6b7280; font-size: 12px;">Rating</strong>
                <div style="font-size: 20px; color: #ef4444;">⭐⭐</div>
            </div>
            <div style="grid-column: 1 / -1;">
                <strong style="color: #6b7280; font-size: 12px;">Comentário</strong>
                <div style="background: #fff; border: 1px solid #e5e7eb; padding: 12px; border-radius: 4px; margin-top: 4px; font-size: 14px;">
                    "A profissional chegou com 40 minutos de atraso e esqueceu de limpar os banheiros."
                </div>
            </div>
        </div>
        ';

        wp_send_json_success(['html' => $html]);
    }
}
