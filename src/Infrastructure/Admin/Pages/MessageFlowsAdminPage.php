<?php
/**
 * MessageFlowsAdminPage
 *
 * Gerenciamento de fluxos automáticos de comunicação
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.3
 */

namespace LimpVix\Infrastructure\Admin\Pages;

class MessageFlowsAdminPage
{
    /**
     * Registrar hooks
     */
    public static function register(): void
    {
        add_action('admin_post_limpvix_update_flows', [__CLASS__, 'handleUpdateFlows']);
    }

    /**
     * Renderizar página
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        // Buscar configurações atuais
        $active_flows = get_option('limpvix_active_flows', [
            'C1' => true,
            'C2' => true, // sempre true (forçado)
            'C3' => true,
            'P1' => true,
            'P2' => true,
            'P3' => true,
        ]);

        $timing_config = get_option('limpvix_feedback_timing', [
            'C1_attempt1' => 24,   // D+1
            'C1_attempt2' => 72,   // D+3
            'C1_attempt3' => 168,  // D+7
        ]);

        // Definir fluxos
        $fluxos = $this->getFluxosDefinition();

        // Mensagens de feedback
        $message = $_GET['message'] ?? '';

        ?>
        <div class="wrap">
            <h1>🔄 Gerenciar Fluxos Automáticos</h1>
            <p class="description">Configure quais fluxos estão ativos e seus timings</p>

            <?php if ($message === 'flows_updated'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Fluxos atualizados com sucesso!</strong></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="limpvix_update_flows">
                <?php wp_nonce_field('limpvix_update_flows'); ?>

                <!-- Fluxos para Clientes -->
                <h2>Fluxos para Clientes</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Fluxo</th>
                            <th>Descrição</th>
                            <th style="width: 100px;">Canal</th>
                            <th style="width: 150px;">Timing</th>
                            <th style="width: 120px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fluxos['clients'] as $fluxo): ?>
                            <tr>
                                <td><strong><?php echo esc_html($fluxo['id']); ?></strong></td>
                                <td>
                                    <div><strong><?php echo esc_html($fluxo['name']); ?></strong></div>
                                    <div style="color: #6b7280; font-size: 12px;"><?php echo esc_html($fluxo['description']); ?></div>
                                    <?php if (!empty($fluxo['note'])): ?>
                                        <div style="color: #ef4444; font-size: 12px; margin-top: 4px;">
                                            ⚠️ <?php echo esc_html($fluxo['note']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $this->renderChannelBadge($fluxo['channel']); ?></td>
                                <td>
                                    <?php if ($fluxo['id'] === 'C1'): ?>
                                        <div style="font-size: 12px;">
                                            <div>1ª: <input type="number" name="C1_attempt1" value="<?php echo $timing_config['C1_attempt1']; ?>" style="width: 50px;"> horas</div>
                                            <div>2ª: <input type="number" name="C1_attempt2" value="<?php echo $timing_config['C1_attempt2']; ?>" style="width: 50px;"> horas</div>
                                            <div>3ª: <input type="number" name="C1_attempt3" value="<?php echo $timing_config['C1_attempt3']; ?>" style="width: 50px;"> horas</div>
                                        </div>
                                    <?php elseif ($fluxo['timing']): ?>
                                        <span style="color: #6b7280; font-size: 12px;"><?php echo esc_html($fluxo['timing']); ?></span>
                                    <?php else: ?>
                                        <span style="color: #6b7280;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($fluxo['locked']): ?>
                                        <label style="display: flex; align-items: center; gap: 8px; cursor: not-allowed;">
                                            <input type="checkbox" name="flow_<?php echo $fluxo['id']; ?>" value="1" checked disabled style="cursor: not-allowed;">
                                            <span style="color: #ef4444; font-weight: 600;">🔒 Bloqueado</span>
                                        </label>
                                    <?php else: ?>
                                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                            <input type="checkbox" name="flow_<?php echo $fluxo['id']; ?>" value="1" <?php checked($active_flows[$fluxo['id']] ?? false); ?>>
                                            <span><?php echo ($active_flows[$fluxo['id']] ?? false) ? '✅ Ativo' : '⏸ Pausado'; ?></span>
                                        </label>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Fluxos para Staff -->
                <h2 style="margin-top: 40px;">Fluxos para Staff (Profissionais)</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;">Fluxo</th>
                            <th>Descrição</th>
                            <th style="width: 100px;">Canal</th>
                            <th style="width: 150px;">Timing</th>
                            <th style="width: 120px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fluxos['staff'] as $fluxo): ?>
                            <tr>
                                <td><strong><?php echo esc_html($fluxo['id']); ?></strong></td>
                                <td>
                                    <div><strong><?php echo esc_html($fluxo['name']); ?></strong></div>
                                    <div style="color: #6b7280; font-size: 12px;"><?php echo esc_html($fluxo['description']); ?></div>
                                </td>
                                <td><?php echo $this->renderChannelBadge($fluxo['channel']); ?></td>
                                <td>
                                    <span style="color: #6b7280; font-size: 12px;"><?php echo esc_html($fluxo['timing']); ?></span>
                                </td>
                                <td>
                                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                        <input type="checkbox" name="flow_<?php echo $fluxo['id']; ?>" value="1" <?php checked($active_flows[$fluxo['id']] ?? false); ?>>
                                        <span><?php echo ($active_flows[$fluxo['id']] ?? false) ? '✅ Ativo' : '⏸ Pausado'; ?></span>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Informações Importantes -->
                <div style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 16px; margin: 30px 0; border-radius: 4px;">
                    <h3 style="margin: 0 0 12px 0; color: #1e40af;">ℹ️ Informações Importantes</h3>
                    <ul style="margin: 0; padding-left: 20px; color: #1f2937;">
                        <li><strong>Fluxo C2 (Feedback ≤3⭐):</strong> Bloqueado intencionalmente. Feedback negativo requer contato humano.</li>
                        <li><strong>Timing C1:</strong> Configurado em horas após a conclusão do serviço. Recomendado: 24h, 72h, 168h (7 dias).</li>
                        <li><strong>Fluxos Staff:</strong> Notificam profissionais sobre status de serviços e pagamentos.</li>
                        <li><strong>Governança:</strong> Mesmo com fluxos ativos, mensagens podem ser bloqueadas por: opt-out, disputa, estorno, etc.</li>
                    </ul>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">Salvar Configurações</button>
                    <a href="<?php echo admin_url('admin.php?page=limpvix-communication-center'); ?>" class="button">Voltar ao Hub</a>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Handler: Atualizar fluxos
     */
    public static function handleUpdateFlows(): void
    {
        check_admin_referer('limpvix_update_flows');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        // Atualizar fluxos ativos
        $active_flows = [
            'C1' => !empty($_POST['flow_C1']),
            'C2' => true, // sempre true (bloqueio intencional)
            'C3' => !empty($_POST['flow_C3']),
            'P1' => !empty($_POST['flow_P1']),
            'P2' => !empty($_POST['flow_P2']),
            'P3' => !empty($_POST['flow_P3']),
        ];

        update_option('limpvix_active_flows', $active_flows);

        // Atualizar timing C1
        $timing_config = [
            'C1_attempt1' => absint($_POST['C1_attempt1'] ?? 24),
            'C1_attempt2' => absint($_POST['C1_attempt2'] ?? 72),
            'C1_attempt3' => absint($_POST['C1_attempt3'] ?? 168),
        ];

        update_option('limpvix_feedback_timing', $timing_config);

        // Redirect
        wp_redirect(add_query_arg([
            'page' => 'limpvix-message-flows',
            'message' => 'flows_updated'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Definição dos fluxos
     */
    private function getFluxosDefinition(): array
    {
        return [
            'clients' => [
                [
                    'id' => 'C1',
                    'name' => 'Solicitação de Feedback',
                    'description' => 'Envia até 3 lembretes para o cliente avaliar o serviço (D+1, D+3, D+7)',
                    'channel' => 'whatsapp',
                    'timing' => null, // configurável
                    'locked' => false,
                    'note' => '',
                ],
                [
                    'id' => 'C2',
                    'name' => 'Feedback Negativo (≤3⭐)',
                    'description' => 'Bloqueio intencional. Requer contato humano para resolução.',
                    'channel' => 'none',
                    'timing' => 'Bloqueado',
                    'locked' => true,
                    'note' => 'Este fluxo está bloqueado por design. Feedbacks ≤3⭐ requerem atendimento manual.',
                ],
                [
                    'id' => 'C3',
                    'name' => 'Convite Google Review',
                    'description' => 'Envia convite para avaliar no Google após feedback 5⭐',
                    'channel' => 'whatsapp',
                    'timing' => 'Imediato',
                    'locked' => false,
                    'note' => '',
                ],
            ],
            'staff' => [
                [
                    'id' => 'P1',
                    'name' => 'Serviço Concluído',
                    'description' => 'Notifica profissional sobre conclusão do serviço',
                    'channel' => 'sms',
                    'timing' => 'Imediato',
                    'locked' => false,
                ],
                [
                    'id' => 'P2',
                    'name' => 'Pagamento Autorizado',
                    'description' => 'Notifica profissional sobre autorização de pagamento',
                    'channel' => 'sms',
                    'timing' => 'Imediato',
                    'locked' => false,
                ],
                [
                    'id' => 'P3',
                    'name' => 'Pagamento em Análise',
                    'description' => 'Notifica profissional sobre pagamento retido para análise',
                    'channel' => 'sms',
                    'timing' => 'Imediato',
                    'locked' => false,
                ],
            ],
        ];
    }

    /**
     * Renderizar badge de canal
     */
    private function renderChannelBadge(string $channel): string
    {
        $badges = [
            'sms' => '<span style="background: #3b82f6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">📱 SMS</span>',
            'whatsapp' => '<span style="background: #10b981; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">💬 WhatsApp</span>',
            'none' => '<span style="background: #6b7280; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">🔒 Nenhum</span>',
        ];

        return $badges[$channel] ?? '<span style="color: #6b7280;">—</span>';
    }
}
