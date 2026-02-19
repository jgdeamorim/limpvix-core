<?php
/**
 * CommunicationCenterPage
 *
 * Hub/Dashboard do sistema de comunicação
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.3
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Infrastructure\Communication\Repositories\MessageRepository;

class CommunicationCenterPage
{
    private MessageRepository $messageRepository;

    public function __construct()
    {
        $this->messageRepository = new MessageRepository();
    }

    /**
     * Renderizar página
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        // Buscar métricas
        $stats = $this->getStats();
        $fluxos = $this->getFluxosStatus();
        $providers = $this->getProvidersStatus();
        $recent_messages = $this->getRecentMessages(10);

        ?>
        <div class="wrap">
            <!-- Status de Providers -->
            <h2>Status dos Providers</h2>
            <div class="limpvix-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin: 20px 0;">
                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid <?php echo $providers['twilio']['connected'] ? '#10b981' : '#ef4444'; ?>; padding: 16px; border-radius: 4px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="font-size: 32px;">📱</div>
                        <div style="flex: 1;">
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Twilio SMS</div>
                            <div style="font-size: 18px; font-weight: bold; color: #1f2937;">
                                <?php echo $providers['twilio']['connected'] ? '✅ Conectado' : '❌ Não configurado'; ?>
                            </div>
                            <?php if ($providers['twilio']['connected']): ?>
                                <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                    <?php echo esc_html($providers['twilio']['from_number']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #9ca3af; padding: 16px; border-radius: 4px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="font-size: 32px;">💬</div>
                        <div style="flex: 1;">
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">WhatsApp</div>
                            <div style="font-size: 18px; font-weight: bold; color: #1f2937;">
                                ⏳ Pendente (Twilio WhatsApp futuro)
                            </div>
                            <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                Fallback ativo via SMS
                            </div>
                        </div>
                    </div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid <?php echo $providers['system_active'] ? '#3b82f6' : '#9ca3af'; ?>; padding: 16px; border-radius: 4px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="font-size: 32px;">⚙️</div>
                        <div style="flex: 1;">
                            <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Sistema</div>
                            <div style="font-size: 18px; font-weight: bold; color: #1f2937;">
                                <?php echo $providers['system_active'] ? '✅ Ativo' : '⏸️ Pausado'; ?>
                            </div>
                            <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                <?php echo $providers['staff_notifications'] ? 'Notificações Staff: ON' : 'Notificações Staff: OFF'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Métricas Gerais -->
            <h2>Métricas (Últimos 30 dias)</h2>
            <div class="limpvix-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin: 20px 0;">
                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #3b82f6; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Total Enviadas</div>
                    <div style="font-size: 32px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_sent']; ?></div>
                    <div style="color: #6b7280; font-size: 14px; margin-top: 4px;">
                        📱 SMS: <?php echo $stats['total_sms']; ?> | 💬 WhatsApp: <?php echo $stats['total_whatsapp']; ?>
                    </div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #10b981; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Sucesso</div>
                    <div style="font-size: 32px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_success']; ?></div>
                    <div style="color: #10b981; font-size: 14px; margin-top: 4px;">
                        Taxa: <?php echo $stats['success_rate']; ?>%
                    </div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #ef4444; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Falhas</div>
                    <div style="font-size: 32px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_failed']; ?></div>
                    <div style="color: #ef4444; font-size: 14px; margin-top: 4px;">
                        <?php if ($stats['total_failed'] > 0): ?>
                            <a href="<?php echo admin_url('admin.php?page=limpvix-communication-center&tab=failures'); ?>">
                                Ver detalhes →
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="limpvix-stat-card" style="background: #fff; border-left: 4px solid #f59e0b; padding: 16px; border-radius: 4px;">
                    <div style="color: #6b7280; font-size: 12px; text-transform: uppercase;">Pendentes</div>
                    <div style="font-size: 32px; font-weight: bold; color: #1f2937;"><?php echo $stats['total_pending']; ?></div>
                </div>
            </div>

            <!-- Status dos Fluxos -->
            <h2>Status dos Fluxos Automáticos</h2>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th style="width: 80px;">Fluxo</th>
                        <th>Descrição</th>
                        <th style="width: 100px;">Canal</th>
                        <th style="width: 120px;">Público</th>
                        <th style="width: 100px;">Status</th>
                        <th style="width: 120px;">Enviadas (30d)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fluxos as $fluxo): ?>
                        <tr>
                            <td><strong><?php echo esc_html($fluxo['id']); ?></strong></td>
                            <td><?php echo esc_html($fluxo['description']); ?></td>
                            <td><?php echo $this->renderChannelBadge($fluxo['channel']); ?></td>
                            <td><?php echo $this->renderAudienceBadge($fluxo['audience']); ?></td>
                            <td><?php echo $this->renderStatusBadge($fluxo['active'], $fluxo['locked']); ?></td>
                            <td><?php echo $fluxo['sent_count']; ?> mensagens</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 20px;">
                <a href="<?php echo admin_url('admin.php?page=limpvix-message-flows'); ?>" class="button button-primary">
                    Gerenciar Fluxos
                </a>
                <a href="<?php echo admin_url('admin.php?page=limpvix-message-templates'); ?>" class="button">
                    Gerenciar Templates
                </a>
                <a href="<?php echo admin_url('admin.php?page=limpvix-settings'); ?>" class="button">
                    Configurar Providers
                </a>
            </p>

            <!-- Mensagens Recentes -->
            <h2>Mensagens Recentes (Últimas 10)</h2>
            <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th style="width: 140px;">Data/Hora</th>
                        <th>Destinatário</th>
                        <th style="width: 100px;">Canal</th>
                        <th style="width: 100px;">Template</th>
                        <th style="width: 100px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_messages)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px;">
                                <p style="color: #6b7280;">Nenhuma mensagem enviada ainda.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_messages as $msg): ?>
                            <tr>
                                <td>#<?php echo $msg['id']; ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($msg['created_at'])); ?></td>
                                <td>
                                    <div style="font-size: 12px;">
                                        <div><?php echo $this->maskPhone($msg['recipient_phone']); ?></div>
                                        <div style="color: #6b7280;"><?php echo ucfirst($msg['recipient_type']); ?></div>
                                    </div>
                                </td>
                                <td><?php echo $this->renderChannelBadge($msg['channel']); ?></td>
                                <td style="font-size: 11px; font-family: monospace;"><?php echo esc_html(substr($msg['template_id'], 0, 15)); ?></td>
                                <td><?php echo $this->renderMessageStatusBadge($msg['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Buscar estatísticas
     */
    private function getStats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_messages_log';
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

        $total_sent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE created_at >= %s",
            $thirty_days_ago
        ));

        $total_success = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = 'sent' AND created_at >= %s",
            $thirty_days_ago
        ));

        $total_failed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = 'failed' AND created_at >= %s",
            $thirty_days_ago
        ));

        $total_pending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = 'pending' AND created_at >= %s",
            $thirty_days_ago
        ));

        $total_sms = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE channel = 'sms' AND created_at >= %s",
            $thirty_days_ago
        ));

        $total_whatsapp = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE channel = 'whatsapp' AND created_at >= %s",
            $thirty_days_ago
        ));

        $success_rate = $total_sent > 0 ? round(($total_success / $total_sent) * 100, 1) : 0;

        return [
            'total_sent' => $total_sent,
            'total_success' => $total_success,
            'total_failed' => $total_failed,
            'total_pending' => $total_pending,
            'total_sms' => $total_sms,
            'total_whatsapp' => $total_whatsapp,
            'success_rate' => $success_rate,
        ];
    }

    /**
     * Buscar status dos fluxos
     */
    private function getFluxosStatus(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_messages_log';
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));

        $active_flows = get_option('limpvix_active_flows', [
            'C1' => true,
            'C2' => true,
            'C3' => true,
            'P1' => true,
            'P2' => true,
            'P3' => true,
        ]);

        $fluxos = [
            ['id' => 'C1', 'description' => 'Feedback D+1/D+3/D+7', 'channel' => 'whatsapp', 'audience' => 'cliente', 'active' => $active_flows['C1'] ?? true, 'locked' => false, 'flow_id' => 'client_feedback_%'],
            ['id' => 'C2', 'description' => 'Feedback ≤3⭐ (Bloqueado)', 'channel' => 'none', 'audience' => 'cliente', 'active' => true, 'locked' => true, 'flow_id' => 'client_feedback_negative'],
            ['id' => 'C3', 'description' => 'Google Review (5⭐)', 'channel' => 'whatsapp', 'audience' => 'cliente', 'active' => $active_flows['C3'] ?? true, 'locked' => false, 'flow_id' => 'google_review'],
            ['id' => 'P1', 'description' => 'Serviço Concluído', 'channel' => 'sms', 'audience' => 'staff', 'active' => $active_flows['P1'] ?? true, 'locked' => false, 'flow_id' => 'staff_completed'],
            ['id' => 'P2', 'description' => 'Pagamento Autorizado', 'channel' => 'sms', 'audience' => 'staff', 'active' => $active_flows['P2'] ?? true, 'locked' => false, 'flow_id' => 'staff_authorized'],
            ['id' => 'P3', 'description' => 'Pagamento em Análise', 'channel' => 'sms', 'audience' => 'staff', 'active' => $active_flows['P3'] ?? true, 'locked' => false, 'flow_id' => 'staff_review'],
        ];

        foreach ($fluxos as &$fluxo) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE flow_id LIKE %s AND created_at >= %s",
                $fluxo['flow_id'],
                $thirty_days_ago
            ));
            $fluxo['sent_count'] = $count;
        }

        return $fluxos;
    }

    /**
     * Buscar status dos providers
     */
    private function getProvidersStatus(): array
    {
        $twilio_settings = get_option('limpvix_twilio_settings', []);
        return [
            'twilio' => [
                'connected' => !empty($twilio_settings['account_sid']) && !empty($twilio_settings['auth_token']),
                'from_number' => $twilio_settings['from_number'] ?? '',
            ],
            'system_active' => (bool) get_option('limpvix_comm_active', true),
            'staff_notifications' => (bool) get_option('limpvix_notify_staff_enabled', true),
        ];
    }

    /**
     * Buscar mensagens recentes
     */
    private function getRecentMessages(int $limit): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_messages_log';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);
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

    /**
     * Renderizar badge de público
     */
    private function renderAudienceBadge(string $audience): string
    {
        $badges = [
            'cliente' => '<span style="background: #8b5cf6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">👤 Cliente</span>',
            'staff' => '<span style="background: #f59e0b; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">👨‍💼 Staff</span>',
        ];

        return $badges[$audience] ?? '<span style="color: #6b7280;">—</span>';
    }

    /**
     * Renderizar badge de status
     */
    private function renderStatusBadge(bool $active, bool $locked): string
    {
        if ($locked) {
            return '<span style="background: #ef4444; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">🔒 Bloqueado</span>';
        }

        if ($active) {
            return '<span style="background: #10b981; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">✓ Ativo</span>';
        }

        return '<span style="background: #6b7280; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">⏸ Pausado</span>';
    }

    /**
     * Renderizar badge de status da mensagem
     */
    private function renderMessageStatusBadge(string $status): string
    {
        $badges = [
            'pending' => '<span style="background: #f59e0b; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">⏳ Pendente</span>',
            'sent' => '<span style="background: #10b981; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">✓ Enviada</span>',
            'delivered' => '<span style="background: #3b82f6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">✓✓ Entregue</span>',
            'failed' => '<span style="background: #ef4444; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">✗ Falhou</span>',
        ];

        return $badges[$status] ?? '<span style="color: #6b7280;">' . esc_html($status) . '</span>';
    }

    /**
     * Mascarar telefone
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) > 4) {
            return substr($phone, 0, 4) . '****' . substr($phone, -2);
        }
        return $phone;
    }
}
