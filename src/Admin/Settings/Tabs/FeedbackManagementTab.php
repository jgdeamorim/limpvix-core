<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class FeedbackManagementTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'feedback-management'; }
    public function getLabel(): string { return 'Feedback'; }
    public function getIcon(): string { return '&#x2B50;'; }

    public function handleSave(): void
    {
        if (!isset($_POST['limpvix_save_feedback_settings'])) {
            return;
        }
        if (!check_admin_referer('limpvix_feedback_settings')) {
            return;
        }

        // Toggles — string '1'/'0' (WP update_option with boolean false doesn't persist reliably)
        update_option('limpvix_feedback_enabled', isset($_POST['feedback_enabled']) ? '1' : '0');
        update_option('limpvix_feedback_reminder_12h_enabled', isset($_POST['feedback_reminder_12h_enabled']) ? '1' : '0');
        update_option('limpvix_feedback_reminder_final_enabled', isset($_POST['feedback_reminder_final_enabled']) ? '1' : '0');

        // Numbers
        update_option('limpvix_feedback_window_hours', max(1, min(168, intval($_POST['feedback_window_hours'] ?? 24))));
        update_option('limpvix_feedback_min_rating_for_evidence', max(1, min(5, intval($_POST['feedback_min_rating_for_evidence'] ?? 3))));

        wp_redirect(add_query_arg(['page' => 'limpvix-settings', 'tab' => 'feedback-management', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        // Load settings
        $feedbackEnabled = filter_var(get_option('limpvix_feedback_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
        $windowHours     = (int) get_option('limpvix_feedback_window_hours', 24);
        $minRatingEvidence = (int) get_option('limpvix_feedback_min_rating_for_evidence', 3);
        $reminder12h     = filter_var(get_option('limpvix_feedback_reminder_12h_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
        $reminderFinal   = filter_var(get_option('limpvix_feedback_reminder_final_enabled', '1'), FILTER_VALIDATE_BOOLEAN);

        // Stats
        $fbStats = $this->getFeedbackStats();
        $windowStats = $this->getFeedbackWindowStats();
        ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible" style="margin-bottom: 15px;">
                <p><strong>&#x2705; Configura&ccedil;&otilde;es de Feedback salvas com sucesso!</strong></p>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: Hero Dashboard -->
        <div class="limpvix-card" style="background: linear-gradient(135deg, #f59e0b 0%, #dc2626 100%); color: white; margin-bottom: 20px; border: none; box-shadow: 0 10px 40px rgba(245, 158, 11, 0.3);">
            <div style="padding: 30px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div>
                        <h1 style="color: white; margin: 0 0 10px 0; font-size: 28px; font-weight: 600;">
                            <span class="dashicons dashicons-star-half" style="font-size: 28px; width: 28px; height: 28px; margin-right: 8px;"></span>
                            Feedback &amp; Qualidade
                        </h1>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 15px;">
                            Checkout &#x2192; Window (<?php echo esc_html($windowHours); ?>h) &#x2192; Avalia&ccedil;&atilde;o &#x2192; Modera&ccedil;&atilde;o &#x2192; Score &#x2192; Payout
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); padding: 15px 25px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.3);">
                            <div style="font-size: 32px; font-weight: 700; color: white; line-height: 1;">
                                <?php echo $fbStats['avg_rating'] > 0 ? number_format($fbStats['avg_rating'], 1) : '—'; ?>
                            </div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8); margin-top: 5px; font-weight: 500;">
                                Rating M&eacute;dio
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 25px;">
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo (int) $fbStats['total']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Total (30d)</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo (int) $fbStats['approved']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Aprovados</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo (int) $fbStats['pending']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Pendentes</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo (int) $fbStats['c2_blocking']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">C2 Bloqueando</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION 2: Feedback Window Monitor -->
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header" style="border-left: 4px solid #f59e0b;">
                <h3>
                    <span class="dashicons dashicons-clock"></span>
                    Monitor de Feedback Windows
                </h3>
                <p>Janelas ativas, expiradas e feedback recebido (7 dias)</p>
            </div>
            <div class="limpvix-card-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;">
                    <div style="padding: 16px; background: <?php echo $windowStats['active_windows'] > 0 ? '#fef3c7' : '#f0fdf4'; ?>; border: 1px solid <?php echo $windowStats['active_windows'] > 0 ? '#fde68a' : '#bbf7d0'; ?>; border-radius: 8px; text-align: center;">
                        <span class="dashicons dashicons-clock" style="color: <?php echo $windowStats['active_windows'] > 0 ? '#d97706' : '#16a34a'; ?>; font-size: 24px; width: 24px; height: 24px;"></span>
                        <div style="font-size: 28px; font-weight: 800; margin: 8px 0 4px;"><?php echo (int) $windowStats['active_windows']; ?></div>
                        <div style="font-size: 12px; font-weight: 600; color: #6b7280;">Windows Ativas</div>
                        <div style="font-size: 11px; color: #9ca3af;">Aguardando feedback</div>
                    </div>
                    <div style="padding: 16px; background: <?php echo $windowStats['expired_without_feedback'] > 0 ? '#fef2f2' : '#f0fdf4'; ?>; border: 1px solid <?php echo $windowStats['expired_without_feedback'] > 0 ? '#fecaca' : '#bbf7d0'; ?>; border-radius: 8px; text-align: center;">
                        <span class="dashicons dashicons-dismiss" style="color: <?php echo $windowStats['expired_without_feedback'] > 0 ? '#dc2626' : '#16a34a'; ?>; font-size: 24px; width: 24px; height: 24px;"></span>
                        <div style="font-size: 28px; font-weight: 800; margin: 8px 0 4px;"><?php echo (int) $windowStats['expired_without_feedback']; ?></div>
                        <div style="font-size: 12px; font-weight: 600; color: #6b7280;">Expiradas s/ Feedback</div>
                        <div style="font-size: 11px; color: #9ca3af;">Auto-aprovadas</div>
                    </div>
                    <div style="padding: 16px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; text-align: center;">
                        <span class="dashicons dashicons-yes-alt" style="color: #3b82f6; font-size: 24px; width: 24px; height: 24px;"></span>
                        <div style="font-size: 28px; font-weight: 800; margin: 8px 0 4px;"><?php echo (int) $windowStats['feedback_submitted']; ?></div>
                        <div style="font-size: 12px; font-weight: 600; color: #6b7280;">Feedbacks Recebidos</div>
                        <div style="font-size: 11px; color: #9ca3af;">&Uacute;ltimos 7 dias</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION 3: Settings Form -->
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_feedback_settings'); ?>
            <input type="hidden" name="limpvix_save_feedback_settings" value="1">

            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header" style="border-left: 4px solid #3b82f6;">
                    <h3>
                        <span class="dashicons dashicons-admin-generic"></span>
                        Configura&ccedil;&otilde;es de Feedback
                    </h3>
                    <p>Par&acirc;metros do sistema de avalia&ccedil;&atilde;o e janela de feedback</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td style="width: 250px;"><strong>Sistema de Feedback</strong>
                                    <br><small style="color: #666;">Habilita/desabilita todo o fluxo de avalia&ccedil;&atilde;o</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="feedback_enabled" value="1" <?php checked($feedbackEnabled); ?>>
                                        Ativo
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $feedbackEnabled ? 'success' : 'gray'; ?>" style="margin-left: 10px;">
                                        <?php echo $feedbackEnabled ? 'Habilitado' : 'Desativado'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Janela de Feedback</strong>
                                    <br><small style="color: #666;">Tempo que o cliente tem para avaliar ap&oacute;s checkout</small>
                                </td>
                                <td>
                                    <input type="number" name="feedback_window_hours" value="<?php echo esc_attr($windowHours); ?>"
                                           min="1" max="168" class="small-text" style="width: 80px;"> horas
                                    <p class="description">Recomendado: 24h. M&iacute;nimo: 1h. M&aacute;ximo: 168h (7 dias).</p>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Rating M&iacute;n. para Evid&ecirc;ncia</strong>
                                    <br><small style="color: #666;">Abaixo desse rating, fotos obrigat&oacute;rias</small>
                                </td>
                                <td>
                                    <input type="number" name="feedback_min_rating_for_evidence" value="<?php echo esc_attr($minRatingEvidence); ?>"
                                           min="1" max="5" class="small-text" style="width: 80px;"> estrelas
                                    <p class="description">Rating &lt; este valor exige fotos como evid&ecirc;ncia. Recomendado: 3.</p>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Reminder 12h</strong>
                                    <br><small style="color: #666;">Lembrete via WhatsApp/email ap&oacute;s 12h</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="feedback_reminder_12h_enabled" value="1" <?php checked($reminder12h); ?>>
                                        Enviar lembrete
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $reminder12h ? 'success' : 'gray'; ?>" style="margin-left: 10px;">
                                        <?php echo $reminder12h ? 'Ativo' : 'Desativado'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Reminder Final (23h)</strong>
                                    <br><small style="color: #666;">&Uacute;ltimo lembrete 1h antes de expirar</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="feedback_reminder_final_enabled" value="1" <?php checked($reminderFinal); ?>>
                                        Enviar lembrete final
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $reminderFinal ? 'success' : 'gray'; ?>" style="margin-left: 10px;">
                                        <?php echo $reminderFinal ? 'Ativo' : 'Desativado'; ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Severity penalties info -->
                    <div style="padding: 12px 15px; background: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 4px; margin-top: 15px; font-size: 13px;">
                        <strong>Penalidades de Severidade (C2):</strong>
                        <div style="display: flex; gap: 20px; margin-top: 8px; flex-wrap: wrap;">
                            <span><span class="limpvix-badge limpvix-badge-danger">Grave</span> &minus;1.50 pts no score</span>
                            <span><span class="limpvix-badge limpvix-badge-warning">M&eacute;dio</span> &minus;0.75 pts no score</span>
                            <span><span class="limpvix-badge limpvix-badge-success">Leve</span> sem penalidade</span>
                            <span><span class="limpvix-badge limpvix-badge-info">Aprovar</span> libera payout, sem penalidade</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 4: Score Algorithm Info -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header" style="border-left: 4px solid #059669;">
                    <h3>
                        <span class="dashicons dashicons-performance"></span>
                        Algoritmo de Score do Profissional
                    </h3>
                    <p>C&aacute;lculo com decay temporal sobre os &uacute;ltimos 30 feedbacks aprovados</p>
                </div>
                <div class="limpvix-card-body">
                    <!-- Formula -->
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px 20px; margin-bottom: 15px;">
                        <div style="font-family: monospace; font-size: 15px; color: #166534; text-align: center; margin-bottom: 12px;">
                            Score = SUM(rating_adj &times; peso) / SUM(pesos)
                        </div>
                        <div style="font-family: monospace; font-size: 13px; color: #166534; text-align: center;">
                            peso = <strong>0.95</strong><sup>dias</sup> &nbsp;|&nbsp; rating_adj = max(0, rating &minus; penalidade)
                        </div>
                    </div>

                    <!-- Penalty grid -->
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; font-size: 12px; text-align: center; margin-bottom: 15px;">
                        <div style="padding: 8px; background: #fecaca; border-radius: 4px;">
                            <strong>Grave</strong><br>
                            <span class="limpvix-badge limpvix-badge-danger">&minus;1.50</span>
                        </div>
                        <div style="padding: 8px; background: #fef3c7; border-radius: 4px;">
                            <strong>M&eacute;dio</strong><br>
                            <span class="limpvix-badge limpvix-badge-warning">&minus;0.75</span>
                        </div>
                        <div style="padding: 8px; background: #dcfce7; border-radius: 4px;">
                            <strong>Leve</strong><br>
                            <span class="limpvix-badge limpvix-badge-success">0.00</span>
                        </div>
                        <div style="padding: 8px; background: #dbeafe; border-radius: 4px;">
                            <strong>Default</strong><br>
                            <span class="limpvix-badge limpvix-badge-info">5.0</span>
                        </div>
                    </div>

                    <!-- Pipeline -->
                    <h4 style="margin: 20px 0 10px 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <span class="dashicons dashicons-randomize" style="color: #7c3aed;"></span>
                        Pipeline: Feedback &#x2192; Score &#x2192; Payout
                    </h4>
                    <div style="background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; font-size: 13px; font-family: monospace; line-height: 2;">
                        <span style="color: #6b7280;">1.</span> Checkout conclu&iacute;do &#x2192; <span class="limpvix-badge limpvix-badge-info">Window abre (<?php echo esc_html($windowHours); ?>h)</span><br>
                        <span style="color: #6b7280;">2.</span> Rating &#x2265; 4 &#x2192; <span class="limpvix-badge limpvix-badge-success">Payout liberado (1h hold)</span><br>
                        <span style="color: #6b7280;">3.</span> Rating = 3 &#x2192; <span class="limpvix-badge limpvix-badge-warning">Payout retido 24h</span><br>
                        <span style="color: #6b7280;">4.</span> Rating &#x2264; 2 &#x2192; <span class="limpvix-badge limpvix-badge-danger">Payout ON_HOLD + C2</span><br>
                        <span style="color: #6b7280;">5.</span> Admin resolve &#x2192; Gravidade &#x2192; <span class="limpvix-badge limpvix-badge-info">Score recalculado</span><br>
                        <span style="color: #6b7280;">6.</span> Payout liberado &#x2192; <span class="limpvix-badge limpvix-badge-success">Repasse ao profissional</span>
                    </div>

                    <div style="padding: 10px 14px; background: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 4px; font-size: 12px; margin-top: 15px;">
                        <strong>Par&acirc;metros:</strong> &Uacute;ltimos <strong>30</strong> feedbacks | Decay rate <strong>0.95</strong>/dia |
                        Window n&atilde;o expirada + sem feedback &#x2192; payout aguarda |
                        Window expirada sem feedback &#x2192; auto-aprovado
                    </div>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    <span class="dashicons dashicons-saved" style="margin-top: 5px;"></span>
                    Salvar Configura&ccedil;&otilde;es de Feedback
                </button>
            </p>
        </form>

        <!-- SECTION 5: Operational Content (delegated to FeedbackManagementPage) -->
        <?php
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage();
            $page->renderTabContent();
        }
    }

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    private function getFeedbackStats(): array
    {
        $defaults = ['total' => 0, 'avg_rating' => 0.0, 'pending' => 0, 'c2_blocking' => 0, 'approved' => 0, 'rejected' => 0];

        global $wpdb;
        if (!$wpdb) {
            return $defaults;
        }

        $table = $wpdb->prefix . 'limpvix_feedback';
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table
        )) == 0) {
            return $defaults;
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT
            COUNT(*) AS total,
            COALESCE(ROUND(AVG(rating), 2), 0) AS avg_rating,
            SUM(validation_status = 'pending') AS pending,
            SUM(rating <= 2 AND validation_status = 'pending') AS c2_blocking,
            SUM(validation_status = 'approved') AS approved,
            SUM(validation_status = 'rejected') AS rejected
            FROM `{$table}`
            WHERE created_at >= %s
        ", date('Y-m-d H:i:s', strtotime('-30 days'))), ARRAY_A);

        if (!$row) {
            return $defaults;
        }

        return [
            'total'       => (int) $row['total'],
            'avg_rating'  => (float) $row['avg_rating'],
            'pending'     => (int) $row['pending'],
            'c2_blocking' => (int) $row['c2_blocking'],
            'approved'    => (int) $row['approved'],
            'rejected'    => (int) $row['rejected'],
        ];
    }

    private function getFeedbackWindowStats(): array
    {
        $defaults = ['active_windows' => 0, 'expired_without_feedback' => 0, 'feedback_submitted' => 0];

        global $wpdb;
        if (!$wpdb) {
            return $defaults;
        }

        $execTable = $wpdb->prefix . 'limpvix_executions';
        $sfTable   = $wpdb->prefix . 'limpvix_structured_feedbacks';

        // Check both tables exist
        foreach ([$execTable, $sfTable] as $t) {
            if ($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME, $t
            )) == 0) {
                return $defaults;
            }
        }

        $now = current_time('mysql');

        $activeWindows = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT e.order_uuid)
            FROM `{$execTable}` e
            LEFT JOIN `{$sfTable}` f ON e.order_uuid = f.order_uuid
            WHERE e.feedback_window_expires_at IS NOT NULL
              AND e.feedback_window_expires_at > %s
              AND f.id IS NULL
        ", $now));

        $expiredWithoutFeedback = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT e.order_uuid)
            FROM `{$execTable}` e
            LEFT JOIN `{$sfTable}` f ON e.order_uuid = f.order_uuid
            WHERE e.feedback_window_expires_at IS NOT NULL
              AND e.feedback_window_expires_at <= %s
              AND e.feedback_window_expires_at >= DATE_SUB(%s, INTERVAL 7 DAY)
              AND f.id IS NULL
        ", $now, $now));

        $feedbackSubmitted = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM `{$sfTable}`
            WHERE submitted_at >= DATE_SUB(%s, INTERVAL 7 DAY)
        ", $now));

        return [
            'active_windows'           => $activeWindows,
            'expired_without_feedback' => $expiredWithoutFeedback,
            'feedback_submitted'       => $feedbackSubmitted,
        ];
    }
}
