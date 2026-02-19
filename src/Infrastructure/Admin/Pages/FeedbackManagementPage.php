<?php
/**
 * FeedbackManagementPage — Central de Qualidade & Feedback
 *
 * Fluxo operacional refletido:
 *   Execution VALIDATED → Cliente envia Feedback (1-5⭐)
 *   Rating ≤ 2 → Payout em ON_HOLD → Admin resolve/aprova/rejeita → Payout liberado
 *   Feedback aprovado → Score recalculado com decay temporal (0.95^dias) ± penalidade
 *
 * URLs:
 *   Standalone : ?page=limpvix-feedback
 *   Aba Settings: ?page=limpvix-settings&tab=feedback-management
 *
 * Seções:
 *   c2      — Feedback ≤2⭐ que bloqueiam payouts (requerem ação admin)
 *   reviews — Todos os feedbacks pendentes de validação (aprovação / rejeição)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class FeedbackManagementPage
{
    // ─────────────────────────────────────────────────────────────────────
    // Registro de hooks AJAX / admin-post
    // ─────────────────────────────────────────────────────────────────────

    public static function register(): void
    {
        add_action('admin_post_limpvix_approve_feedback', [__CLASS__, 'handleApproveFeedback']);
        add_action('admin_post_limpvix_reject_feedback',  [__CLASS__, 'handleRejectFeedback']);
        add_action('wp_ajax_limpvix_get_feedback_detail', [__CLASS__, 'handleGetFeedbackDetail']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Entry points
    // ─────────────────────────────────────────────────────────────────────

    /** Aba dentro de ?page=limpvix-settings (sem wrap externo). */
    public function renderTabContent(): void
    {
        if (!current_user_can('limpvix_view_feedback') && !current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>Sem permissão. Capability <code>limpvix_view_feedback</code> requerida.</p></div>';
            return;
        }
        $this->render(false);
    }

    /** Página standalone ?page=limpvix-feedback. */
    public function render(bool $standalone = true): void
    {
        if ($standalone && !current_user_can('limpvix_view_feedback') && !current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $section = sanitize_key($_GET['section'] ?? 'c2');
        $message = sanitize_key($_GET['message'] ?? '');

        // Dados para todas as seções
        $c2      = $this->queryFeedbackC2();
        $reviews = $this->queryAllReviews();

        // Counters para badges de navegação
        $c2Pending      = count(array_filter($c2,      fn($r) => $r['validation_status'] === 'pending'));
        $reviewsPending = count(array_filter($reviews, fn($r) => $r['validation_status'] === 'pending'));

        // Payouts em hold por C2
        $payoutsOnHold = $this->countPayoutsBlockedByFeedback();

        if ($standalone): ?>
<div class="wrap limpvix-admin">

    <!-- Header com banner de alerta se houver payouts bloqueados -->
    <div class="limpvix-page-header" style="margin-bottom:0;">
        <div>
            <h1><span class="dashicons dashicons-star-half" style="font-size:28px;width:28px;height:28px;margin-right:6px;"></span>Feedback &amp; Qualidade</h1>
            <p class="limpvix-page-subtitle">Moderação de avaliações · Liberação de payouts · Score dos profissionais</p>
        </div>
    </div>

        <?php else: ?>
<div>
        <?php endif;

        /* ── Banner de payouts bloqueados ── */
        if ($payoutsOnHold > 0): ?>
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid #f97316;border-radius:6px;padding:12px 18px;margin:16px 0;display:flex;align-items:center;gap:14px;">
        <span style="font-size:28px;">⛔</span>
        <div>
            <strong style="color:#9a3412;">
                <?php echo $payoutsOnHold === 1 ? '1 payout está' : "{$payoutsOnHold} payouts estão"; ?> bloqueado<?php echo $payoutsOnHold > 1 ? 's' : ''; ?> por Feedback C2
            </strong>
            <div style="font-size:13px;color:#c2410c;margin-top:2px;">
                Avalie os feedbacks negativos abaixo para liberar os repasses pendentes.
            </div>
        </div>
    </div>
        <?php endif;

        /* ── Mensagens de retorno ── */
        $msgs = [
            'approved' => ['success', '✅ Feedback aprovado — payout liberado.'],
            'rejected' => ['info',    '⚠️ Feedback dispensado como inválido. Payout liberado para aprovação.'],
            'error'    => ['error',   '❌ Erro ao processar. Tente novamente.'],
        ];
        if (isset($msgs[$message])): ?>
    <div class="notice notice-<?php echo esc_attr($msgs[$message][0]); ?> is-dismissible" style="margin:12px 0;">
        <p><?php echo esc_html($msgs[$message][1]); ?></p>
    </div>
        <?php endif;

        /* ── Sub-navegação ── */
        $c2Label  = $c2Pending > 0 ? "⚠️ C2 — Bloqueiam Payout <span class='limpvix-badge limpvix-badge-danger' style='font-size:11px;'>{$c2Pending}</span>" : '⚠️ C2 — Bloqueiam Payout';
        $rvLabel  = $reviewsPending > 0 ? "⭐ Reviews <span class='limpvix-badge limpvix-badge-warning' style='font-size:11px;'>{$reviewsPending}</span>" : '⭐ Reviews';
        ?>

    <h2 class="nav-tab-wrapper" style="margin-bottom:20px;">
        <?php $this->navTab($section, 'c2',      $c2Label,  $standalone); ?>
        <?php $this->navTab($section, 'reviews',  $rvLabel,  $standalone); ?>
    </h2>

        <?php
        if ($section === 'c2') {
            $this->renderSectionC2($c2);
        } else {
            $this->renderSectionReviews($reviews);
        }
        ?>

</div>
        <?php
        $this->renderModals();
        $this->renderScript();
    }

    // ─────────────────────────────────────────────────────────────────────
    // SEÇÃO 1 — Feedback C2 (rating ≤ 2, bloqueiam payout)
    // ─────────────────────────────────────────────────────────────────────

    private function renderSectionC2(array $c2): void
    {
        $pending  = array_filter($c2, fn($r) => $r['validation_status'] === 'pending');
        $approved = array_filter($c2, fn($r) => $r['validation_status'] === 'approved');
        $rejected = array_filter($c2, fn($r) => $r['validation_status'] === 'rejected');

        // Stats
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:16px 0 20px;">';
        $this->stat('Aguardam Ação',    count($pending),  '#fee2e2','#dc2626','#991b1b');
        $this->stat('Payout Liberado',  count($approved), '#d1fae5','#059669','#065f46');
        $this->stat('Payout em Hold',   count($rejected), '#f3f4f6','#6b7280','#374151');
        $this->stat('Total C2 (30d)',   count($c2),       '#e0e7ff','#6366f1','#3730a3');
        echo '</div>';

        // Explicação do fluxo
        ?>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #6366f1;border-radius:6px;padding:14px 18px;margin-bottom:20px;font-size:13px;">
            <strong style="color:#3730a3;">📌 Fluxo C2:</strong>
            <span style="color:#475569;">
                Feedback ≤2⭐ coloca o payout em <code>on_hold</code> automaticamente.
                Para liberar o repasse, aprove o feedback (sem penalidade) ou registre uma
                resolução com gravidade (afeta o score do profissional).
            </span>
            <div style="margin-top:8px;display:flex;gap:20px;flex-wrap:wrap;font-size:12px;">
                <span><span style="color:#dc2626;font-weight:700;">● Grave</span> → −1.50 pts no rating ponderado</span>
                <span><span style="color:#d97706;font-weight:700;">● Médio</span> → −0.75 pts no rating ponderado</span>
                <span><span style="color:#059669;font-weight:700;">● Leve</span> → sem penalidade no score</span>
                <span><span style="color:#2563eb;font-weight:700;">● Aprovar</span> → libera payout, sem penalidade</span>
            </div>
        </div>

        <?php if (empty($c2)): ?>
        <div class="notice notice-success" style="margin:0;"><p>✨ Nenhum feedback C2 nos últimos 30 dias.</p></div>
        <?php return; endif; ?>

        <table class="limpvix-table" style="border-radius:6px;overflow:hidden;">
            <thead style="background:#f1f5f9;">
                <tr>
                    <th style="width:50px;padding:10px 8px;">ID</th>
                    <th style="width:90px;">Order</th>
                    <th>Profissional</th>
                    <th>Cliente</th>
                    <th style="width:75px;text-align:center;">Rating</th>
                    <th>Comentário</th>
                    <th style="width:105px;">Data</th>
                    <th style="width:95px;text-align:center;">Status</th>
                    <th style="width:180px;text-align:center;">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($c2 as $fb): ?>
            <?php
                $isPending  = $fb['validation_status'] === 'pending';
                $hasRes     = !empty($fb['resolution_text']);
                $sev        = $fb['resolution_severity'] ?? null;
                $rowBg      = $isPending ? '#fffbeb' : 'transparent';
            ?>
            <tr style="background:<?php echo $rowBg; ?>;">
                <td style="font-family:monospace;font-size:12px;">#<?php echo (int)$fb['id']; ?></td>
                <td style="font-family:monospace;font-size:12px;">#<?php echo (int)$fb['order_id']; ?></td>
                <td style="font-size:13px;"><strong><?php echo esc_html($fb['professional_name'] ?: '—'); ?></strong></td>
                <td style="font-size:13px;"><?php echo esc_html($fb['client_name'] ?: '—'); ?></td>
                <td style="text-align:center;">
                    <?php
                    $stars = (int)$fb['rating'];
                    echo '<span style="font-size:15px;">' . str_repeat('⭐', $stars) . '</span>';
                    echo '<div style="font-size:11px;color:#6b7280;">' . $stars . '/5</div>';
                    ?>
                </td>
                <td style="font-size:12px;color:#374151;max-width:220px;word-break:break-word;">
                    <?php if ($fb['comment']): ?>
                    <em>"<?php echo esc_html(wp_trim_words($fb['comment'], 14)); ?>"</em>
                    <?php else: ?>
                    <span style="color:#9ca3af;">Sem comentário</span>
                    <?php endif; ?>
                    <?php if ($hasRes): ?>
                    <div style="margin-top:4px;">
                        <?php
                        $sevColors = ['grave'=>'#fee2e2|#dc2626','medio'=>'#fef3c7|#d97706','leve'=>'#d1fae5|#059669'];
                        [$bg,$cl] = explode('|', $sevColors[$sev] ?? '#f3f4f6|#6b7280');
                        ?>
                        <span style="background:<?php echo $bg; ?>;color:<?php echo $cl; ?>;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;">
                            <?php echo strtoupper($sev ?? ''); ?>
                        </span>
                        <span style="font-size:11px;color:#6b7280;">Resolvido por <?php echo esc_html($fb['resolution_by_name'] ?? '—'); ?></span>
                    </div>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;color:#6b7280;"><?php echo esc_html(date('d/m/y H:i', strtotime($fb['created_at']))); ?></td>
                <td style="text-align:center;"><?php echo $this->badge($fb['validation_status']); ?></td>
                <td style="text-align:center;white-space:nowrap;">
                    <?php if ($isPending): ?>
                    <button type="button"
                            class="button button-primary button-small lmpx-fb-btn"
                            data-id="<?php echo (int)$fb['id']; ?>"
                            data-mode="resolve"
                            style="margin-bottom:4px;width:100%;">
                        🔍 Avaliar &amp; Liberar
                    </button>
                    <button type="button"
                            class="button button-small lmpx-fb-btn"
                            data-id="<?php echo (int)$fb['id']; ?>"
                            data-mode="reject"
                            style="width:100%;color:#dc2626;border-color:#fca5a5;">
                        ❌ Dispensar
                    </button>
                    <?php elseif ($hasRes): ?>
                    <button type="button"
                            class="button button-small lmpx-fb-btn"
                            data-id="<?php echo (int)$fb['id']; ?>"
                            data-mode="view"
                            style="color:#6b7280;">
                        📋 Detalhes
                    </button>
                    <?php else: ?>
                    <span style="color:#9ca3af;font-size:12px;">Processado</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // SEÇÃO 2 — Todos os Reviews (moderação geral)
    // ─────────────────────────────────────────────────────────────────────

    private function renderSectionReviews(array $reviews): void
    {
        $rvFilter = sanitize_key($_GET['rv'] ?? 'pending');
        $counts   = [];
        foreach (['pending','approved','rejected'] as $s) {
            $counts[$s] = count(array_filter($reviews, fn($r) => $r['validation_status'] === $s));
        }
        $filtered = $rvFilter === 'all' ? $reviews
                  : array_filter($reviews, fn($r) => $r['validation_status'] === $rvFilter);

        // Filtros
        $baseUrl = $this->tabUrl(['section' => 'reviews']);
        $filterLabels = [
            'pending'  => "⏳ Pendentes ({$counts['pending']})",
            'approved' => "✅ Aprovados ({$counts['approved']})",
            'rejected' => "❌ Rejeitados ({$counts['rejected']})",
            'all'      => '📋 Todos (' . count($reviews) . ')',
        ];
        echo '<div style="margin-bottom:16px;display:flex;gap:6px;flex-wrap:wrap;">';
        foreach ($filterLabels as $s => $lbl) {
            $active = $rvFilter === $s ? 'button-primary' : '';
            echo '<a href="' . esc_url(add_query_arg('rv', $s, $baseUrl)) . '" class="button ' . $active . '">' . $lbl . '</a>';
        }
        echo '</div>';

        if (empty($filtered)): ?>
        <div class="notice notice-info" style="margin:0;"><p>Nenhum review neste filtro.</p></div>
        <?php return; endif; ?>

        <table class="limpvix-table" style="border-radius:6px;overflow:hidden;">
            <thead style="background:#f1f5f9;">
                <tr>
                    <th style="width:50px;">ID</th>
                    <th style="width:90px;">Order</th>
                    <th>Profissional</th>
                    <th>Cliente</th>
                    <th style="width:90px;text-align:center;">Rating</th>
                    <th>Comentário</th>
                    <th style="width:105px;">Data</th>
                    <th style="width:95px;text-align:center;">Status</th>
                    <th style="width:130px;text-align:center;">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($filtered as $rv): ?>
            <?php
                $r   = (int) $rv['rating'];
                $clr = $r >= 4 ? '#16a34a' : ($r <= 2 ? '#ef4444' : '#d97706');
            ?>
            <tr>
                <td style="font-family:monospace;font-size:12px;">#<?php echo (int)$rv['id']; ?></td>
                <td style="font-family:monospace;font-size:12px;">#<?php echo (int)$rv['order_id']; ?></td>
                <td style="font-size:13px;"><strong><?php echo esc_html($rv['professional_name'] ?: '—'); ?></strong></td>
                <td style="font-size:13px;"><?php echo esc_html($rv['client_name'] ?: '—'); ?></td>
                <td style="text-align:center;">
                    <span style="font-size:14px;color:<?php echo $clr; ?>;"><?php echo str_repeat('⭐', $r); ?></span>
                    <div style="font-size:11px;color:#6b7280;"><?php echo $r; ?>/5</div>
                </td>
                <td style="font-size:12px;color:#374151;max-width:200px;word-break:break-word;">
                    <?php echo $rv['comment'] ? '<em>"' . esc_html(wp_trim_words($rv['comment'], 12)) . '"</em>' : '<span style="color:#9ca3af;">—</span>'; ?>
                </td>
                <td style="font-size:12px;color:#6b7280;"><?php echo esc_html(date('d/m/y H:i', strtotime($rv['created_at']))); ?></td>
                <td style="text-align:center;"><?php echo $this->badge($rv['validation_status']); ?></td>
                <td style="text-align:center;white-space:nowrap;">
                    <?php if ($rv['validation_status'] === 'pending'): ?>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                        <?php wp_nonce_field('limpvix_approve_feedback'); ?>
                        <input type="hidden" name="action"      value="limpvix_approve_feedback">
                        <input type="hidden" name="feedback_id" value="<?php echo (int)$rv['id']; ?>">
                        <button type="submit" class="button button-primary button-small" title="Aprovar e liberar payout">✅</button>
                    </form>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;margin-left:4px;">
                        <?php wp_nonce_field('limpvix_reject_feedback'); ?>
                        <input type="hidden" name="action"      value="limpvix_reject_feedback">
                        <input type="hidden" name="feedback_id" value="<?php echo (int)$rv['id']; ?>">
                        <button type="submit" class="button button-small" title="Rejeitar (spam/fraude)" style="color:#dc2626;">❌</button>
                    </form>
                    <?php elseif ($rv['validated_by']): ?>
                    <span style="font-size:11px;color:#6b7280;">
                        por <?php echo esc_html(get_userdata((int)$rv['validated_by'])?->display_name ?: '—'); ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#9ca3af;font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // Modal: Avaliar & Liberar (C2) + Rejeitar + Detalhes
    // ─────────────────────────────────────────────────────────────────────

    private function renderModals(): void
    {
        ?>
        <div id="lmpx-fb-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:99999;justify-content:center;align-items:flex-start;padding-top:60px;">
            <div style="background:#fff;border-radius:10px;width:94%;max-width:660px;max-height:85vh;overflow-y:auto;box-shadow:0 25px 70px rgba(0,0,0,.35);">

                <!-- Header -->
                <div id="lmpx-modal-header" style="padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:1;">
                    <h2 id="lmpx-modal-title" style="margin:0;font-size:17px;color:#111827;"></h2>
                    <button onclick="lmpxClose()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#9ca3af;line-height:1;">✕</button>
                </div>

                <!-- Body: detalhes do feedback -->
                <div id="lmpx-modal-details" style="padding:20px 24px;border-bottom:1px solid #f3f4f6;"></div>

                <!-- Body: formulário de resolução (modo resolve) -->
                <div id="lmpx-modal-form" style="padding:20px 24px;display:none;">
                    <form id="lmpx-resolve-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field('limpvix_approve_feedback'); ?>
                        <input type="hidden" name="action"      value="limpvix_approve_feedback">
                        <input type="hidden" name="feedback_id" id="lmpx-resolve-id">

                        <label style="display:block;margin-bottom:14px;">
                            <span style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">Gravidade da ocorrência <span style="color:#dc2626;">*</span></span>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                                <?php foreach ([
                                    ['grave','🔴 Grave','−1.50 pts no score','#fee2e2','#dc2626'],
                                    ['medio','🟡 Médio','−0.75 pts no score','#fef3c7','#d97706'],
                                    ['leve', '🟢 Leve', 'Sem penalidade',    '#d1fae5','#059669'],
                                ] as [$val,$lbl,$desc,$bg,$cl]): ?>
                                <label style="cursor:pointer;border:2px solid #e5e7eb;border-radius:8px;padding:10px 8px;text-align:center;transition:all .15s;" id="sev-card-<?php echo $val; ?>">
                                    <input type="radio" name="resolution_severity" value="<?php echo $val; ?>" style="display:none;" required
                                           onchange="lmpxSelectSev('<?php echo $val; ?>')">
                                    <div style="font-size:18px;"><?php echo $lbl; ?></div>
                                    <div style="font-size:11px;color:#6b7280;margin-top:2px;"><?php echo $desc; ?></div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </label>

                        <label style="display:block;margin-bottom:14px;">
                            <span style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">O que foi feito para resolver? <span style="color:#dc2626;">*</span></span>
                            <textarea name="resolution_text" id="lmpx-res-text" rows="3" required
                                      placeholder="Ex: Ligação feita, cliente satisfeito após explicação. Nova visita agendada para 21/02."
                                      style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:13px;box-sizing:border-box;resize:vertical;"></textarea>
                        </label>

                        <label style="display:block;margin-bottom:20px;">
                            <span style="display:block;font-weight:600;margin-bottom:6px;color:#374151;">Seu nome (responsável pela resolução)</span>
                            <input type="text" name="resolution_by_name" id="lmpx-res-name"
                                   style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:7px 12px;font-size:13px;box-sizing:border-box;">
                        </label>

                        <div style="display:flex;gap:10px;justify-content:flex-end;">
                            <button type="button" onclick="lmpxClose()" class="button">Cancelar</button>
                            <button type="submit" class="button button-primary">✅ Confirmar Aprovação &amp; Liberar Payout</button>
                        </div>
                    </form>
                </div>

                <!-- Footer: botões contextuais para reject/view -->
                <div id="lmpx-modal-footer" style="padding:16px 24px;border-top:1px solid #f3f4f6;display:flex;justify-content:flex-end;gap:10px;"></div>
            </div>
        </div>
        <?php
    }

    private function renderScript(): void
    {
        $nonce        = wp_create_nonce('limpvix_get_feedback_detail');
        $rejectNonce  = wp_create_nonce('limpvix_reject_feedback');
        $adminPost    = esc_js(admin_url('admin-post.php'));
        $currentUser  = wp_get_current_user()->display_name;
        ?>
        <script>
        (function($) {
            var resolveMode = false;

            /* Abre modal ao clicar em qualquer botão de ação C2 */
            $(document).on('click', '.lmpx-fb-btn', function() {
                var id   = $(this).data('id');
                var mode = $(this).data('mode'); // resolve | reject | view
                lmpxOpen(id, mode);
            });

            window.lmpxOpen = function(id, mode) {
                resolveMode = (mode === 'resolve');
                var titles = {
                    resolve : '🔍 Avaliar Feedback C2 — Liberar Payout',
                    reject  : '❌ Dispensar Feedback — Liberar Payout',
                    view    : '📋 Detalhes da Resolução',
                };
                $('#lmpx-modal-title').text(titles[mode] || '');
                $('#lmpx-modal-details').html('<p style="color:#9ca3af;padding:12px 0;">Carregando…</p>');
                $('#lmpx-modal-form').hide();
                $('#lmpx-modal-footer').html('');
                $('#lmpx-fb-modal').css('display','flex');

                $.post(ajaxurl, {
                    action     : 'limpvix_get_feedback_detail',
                    feedback_id: id,
                    nonce      : '<?php echo $nonce; ?>'
                }, function(res) {
                    if (!res.success) {
                        $('#lmpx-modal-details').html('<p style="color:#ef4444;">Erro: ' + res.data.message + '</p>');
                        return;
                    }
                    var d = res.data;

                    /* Card de detalhes do feedback */
                    var ratingColor = d.rating <= 2 ? '#ef4444' : (d.rating >= 4 ? '#16a34a' : '#d97706');
                    var stars = '⭐'.repeat(d.rating);
                    var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
                        + '<div><div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:3px;">Profissional</div>'
                        + '<div style="font-weight:600;font-size:14px;">' + (d.professional_name || '—') + '</div></div>'
                        + '<div><div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:3px;">Cliente</div>'
                        + '<div style="font-weight:600;font-size:14px;">' + (d.client_name || '—') + '</div></div>'
                        + '<div><div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:3px;">Avaliação</div>'
                        + '<div style="font-size:22px;color:' + ratingColor + ';">' + stars + '</div>'
                        + '<div style="font-size:11px;color:#6b7280;">' + d.rating + '/5</div></div>'
                        + '<div><div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:3px;">Data</div>'
                        + '<div style="font-size:14px;">' + d.date + '</div></div>'
                        + '</div>';

                    if (d.comment) {
                        html += '<div style="margin-top:14px;background:#f9fafb;border-left:3px solid #e5e7eb;border-radius:0 6px 6px 0;padding:12px 16px;">'
                            + '<div style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;margin-bottom:6px;">Comentário do cliente</div>'
                            + '<p style="margin:0;font-size:14px;color:#374151;font-style:italic;">"' + d.comment + '"</p>'
                            + '</div>';
                    }

                    if (d.resolution_text) {
                        var sevMap = {grave:'🔴 Grave',medio:'🟡 Médio',leve:'🟢 Leve'};
                        html += '<div style="margin-top:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 16px;">'
                            + '<div style="font-size:11px;font-weight:700;color:#059669;text-transform:uppercase;margin-bottom:6px;">Resolução registrada</div>'
                            + '<div style="font-size:13px;color:#065f46;">' + d.resolution_text + '</div>'
                            + (d.resolution_severity ? '<div style="margin-top:6px;font-size:12px;font-weight:600;">' + (sevMap[d.resolution_severity]||d.resolution_severity) + '</div>' : '')
                            + '</div>';
                    }

                    $('#lmpx-modal-details').html(html);

                    /* Formulário de resolução */
                    if (mode === 'resolve') {
                        $('#lmpx-resolve-id').val(id);
                        $('#lmpx-res-name').val('<?php echo esc_js($currentUser); ?>');
                        $('input[name="resolution_severity"]').prop('checked', false);
                        $('.sev-card-selected').removeClass('sev-card-selected').css({borderColor:'#e5e7eb',background:''});
                        $('#lmpx-res-text').val('');
                        $('#lmpx-modal-form').show();
                    } else if (mode === 'reject') {
                        $('#lmpx-modal-footer').html(
                            '<button onclick="lmpxClose()" class="button">Cancelar</button>'
                            + '<form method="post" action="<?php echo $adminPost; ?>" style="display:inline;">'
                            + '<input type="hidden" name="action" value="limpvix_reject_feedback">'
                            + '<input type="hidden" name="feedback_id" value="' + id + '">'
                            + '<input type="hidden" name="_wpnonce" value="<?php echo $rejectNonce; ?>">'
                            + '<button type="submit" class="button" style="background:#dc2626;border-color:#b91c1c;color:#fff;margin-left:8px;">❌ Dispensar feedback — Liberar Payout</button>'
                            + '</form>'
                        );
                    } else {
                        $('#lmpx-modal-footer').html('<button onclick="lmpxClose()" class="button">Fechar</button>');
                    }
                });
            };

            /* Selecionar card de gravidade */
            window.lmpxSelectSev = function(sev) {
                var colors = {grave:'#fee2e2|#dc2626', medio:'#fef3c7|#d97706', leve:'#d1fae5|#059669'};
                ['grave','medio','leve'].forEach(function(s) {
                    var parts = colors[s].split('|');
                    var el = $('#sev-card-' + s);
                    if (s === sev) {
                        el.css({background:parts[0], borderColor:parts[1]});
                    } else {
                        el.css({background:'', borderColor:'#e5e7eb'});
                    }
                });
            };

            window.lmpxClose = function() {
                $('#lmpx-fb-modal').hide();
            };

            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') lmpxClose();
            });

            /* Fechar ao clicar no backdrop */
            $('#lmpx-fb-modal').on('click', function(e) {
                if (e.target === this) lmpxClose();
            });

        })(jQuery);
        </script>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // Queries
    // ─────────────────────────────────────────────────────────────────────

    private function queryFeedbackC2(): array
    {
        global $wpdb;
        $fb  = $wpdb->prefix . 'limpvix_feedback';
        $usr = $wpdb->users;
        $pro = $wpdb->prefix . 'limpvix_professionals';

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                f.id, f.order_id, f.rating, f.comment,
                f.validation_status, f.validated_by,
                f.resolution_text, f.resolution_severity, f.resolution_by_name, f.resolution_at,
                f.created_at,
                COALESCE(u.display_name, CONCAT('Cliente #', f.client_id)) AS client_name,
                COALESCE(p.full_name, CONCAT('Prof. #', f.professional_id)) AS professional_name
            FROM {$fb} f
            LEFT JOIN {$usr} u ON u.ID = f.client_id
            LEFT JOIN {$pro} p ON p.id = f.professional_id
            WHERE f.rating <= 2
              AND f.created_at >= %s
            ORDER BY
                CASE f.validation_status WHEN 'pending' THEN 0 ELSE 1 END,
                f.created_at DESC
            LIMIT 100
        ", date('Y-m-d H:i:s', strtotime('-30 days'))), ARRAY_A) ?: [];
    }

    private function queryAllReviews(): array
    {
        global $wpdb;
        $fb  = $wpdb->prefix . 'limpvix_feedback';
        $usr = $wpdb->users;
        $pro = $wpdb->prefix . 'limpvix_professionals';

        return $wpdb->get_results($wpdb->prepare("
            SELECT
                f.id, f.order_id, f.rating, f.comment,
                f.validation_status, f.validated_by, f.created_at,
                COALESCE(u.display_name, CONCAT('Cliente #', f.client_id)) AS client_name,
                COALESCE(p.full_name, CONCAT('Prof. #', f.professional_id)) AS professional_name
            FROM {$fb} f
            LEFT JOIN {$usr} u ON u.ID = f.client_id
            LEFT JOIN {$pro} p ON p.id = f.professional_id
            WHERE f.created_at >= %s
            ORDER BY
                CASE f.validation_status WHEN 'pending' THEN 0 ELSE 1 END,
                f.rating ASC,
                f.created_at DESC
            LIMIT 200
        ", date('Y-m-d H:i:s', strtotime('-30 days'))), ARRAY_A) ?: [];
    }

    private function countPayoutsBlockedByFeedback(): int
    {
        global $wpdb;
        $tbl = $wpdb->prefix . 'limpvix_payouts';
        $fbl = $wpdb->prefix . 'limpvix_feedback';

        // Conta payouts on_hold que têm feedback C2 pendente
        $n = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT py.id)
            FROM {$tbl} py
            INNER JOIN {$fbl} fb ON fb.order_id = py.order_id
                AND fb.rating <= 2
                AND fb.validation_status = 'pending'
            WHERE py.status = 'on_hold'
        ");
        return $n;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Handlers POST — Approve / Reject
    // ─────────────────────────────────────────────────────────────────────

    public static function handleApproveFeedback(): void
    {
        check_admin_referer('limpvix_approve_feedback');

        if (!current_user_can('limpvix_resolve_feedback') && !current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        global $wpdb;
        $id         = absint($_POST['feedback_id'] ?? 0);
        $severity   = sanitize_key($_POST['resolution_severity'] ?? '');
        $resText    = sanitize_textarea_field($_POST['resolution_text'] ?? '');
        $resName    = sanitize_text_field($_POST['resolution_by_name'] ?? '');
        $userId     = get_current_user_id();
        $now        = current_time('mysql');

        $penalties  = ['grave' => 1.50, 'medio' => 0.75, 'leve' => 0.00];

        $data = [
            'validation_status'  => 'approved',
            'validated_by_admin' => 1,
            'validated_by'       => $userId,
            'validated_at'       => $now,
        ];
        $fmt  = ['%s','%d','%d','%s'];

        if ($severity && isset($penalties[$severity])) {
            $data['resolution_severity'] = $severity;
            $data['resolution_text']     = $resText;
            $data['resolution_by']       = $userId;
            $data['resolution_by_name']  = $resName ?: (get_userdata($userId)?->display_name ?? '');
            $data['resolution_at']       = $now;
            $data['score_penalty']       = $penalties[$severity];
            $fmt = array_merge($fmt, ['%s','%s','%d','%s','%s','%f']);
        }

        if ($id) {
            $wpdb->update($wpdb->prefix . 'limpvix_feedback', $data, ['id' => $id], $fmt, ['%d']);

            // ── Pós-aprovação: liberar payout bloqueado + recalcular score ──

            // 1. Buscar order_id e professional_id do feedback recém-aprovado
            $fb_row = $wpdb->get_row($wpdb->prepare(
                "SELECT order_id, professional_id FROM {$wpdb->prefix}limpvix_feedback WHERE id = %d LIMIT 1",
                $id
            ), ARRAY_A);

            // 2. Liberar payout on_hold vinculado à mesma order_id
            if (!empty($fb_row['order_id'])) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}limpvix_payouts
                     SET status = 'approved', approved_at = %s, updated_at = %s
                     WHERE order_id = %d AND status = 'on_hold'",
                    $now, $now, (int) $fb_row['order_id']
                ));
            }

            // 3. Recalcular score do profissional com a penalidade (best-effort)
            if (!empty($fb_row['professional_id'])) {
                try {
                    $feedbackRepo     = new \LimpVix\Infrastructure\Feedback\Repositories\WpFeedbackRepository();
                    $professionalRepo = new \LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository();
                    $scoreUseCase     = new \LimpVix\Application\UseCases\Feedback\CalculateProfessionalScore(
                        $feedbackRepo,
                        $professionalRepo
                    );
                    $scoreUseCase->execute((int) $fb_row['professional_id']);
                } catch (\Throwable $e) {
                    // Falha silenciosa — score será recalculado no próximo ciclo
                    error_log('[LimpVix] Score recalc falhou após aprovar feedback #' . $id . ': ' . $e->getMessage());
                }
            }
        }

        wp_redirect(self::redirectUrl('approved'));
        exit;
    }

    public static function handleRejectFeedback(): void
    {
        check_admin_referer('limpvix_reject_feedback');

        if (!current_user_can('limpvix_resolve_feedback') && !current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        global $wpdb;
        $id  = absint($_POST['feedback_id'] ?? 0);
        $now = current_time('mysql');

        if ($id) {
            $wpdb->update(
                $wpdb->prefix . 'limpvix_feedback',
                [
                    'validation_status'  => 'rejected',
                    'validated_by_admin' => 0,
                    'validated_by'       => get_current_user_id(),
                    'validated_at'       => $now,
                ],
                ['id' => $id],
                ['%s','%d','%d','%s'],
                ['%d']
            );

            // Rejeitar feedback como inválido → liberar payout on_hold associado
            // (feedback fraudulento/fora de escopo não deve bloquear repasse)
            $fb_row = $wpdb->get_row($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}limpvix_feedback WHERE id = %d LIMIT 1",
                $id
            ), ARRAY_A);

            if (!empty($fb_row['order_id'])) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}limpvix_payouts
                     SET status = 'approved', approved_at = %s, updated_at = %s
                     WHERE order_id = %d AND status = 'on_hold'",
                    $now, $now, (int) $fb_row['order_id']
                ));
            }
        }

        wp_redirect(self::redirectUrl('rejected'));
        exit;
    }

    public static function handleGetFeedbackDetail(): void
    {
        check_ajax_referer('limpvix_get_feedback_detail', 'nonce');

        if (!current_user_can('limpvix_view_feedback') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão']);
            return;
        }

        global $wpdb;
        $id  = absint($_POST['feedback_id'] ?? 0);
        $fb  = $wpdb->prefix . 'limpvix_feedback';
        $usr = $wpdb->users;
        $pro = $wpdb->prefix . 'limpvix_professionals';

        $row = $wpdb->get_row($wpdb->prepare("
            SELECT f.*, u.display_name AS client_name, p.full_name AS professional_name
            FROM {$fb} f
            LEFT JOIN {$usr} u ON u.ID = f.client_id
            LEFT JOIN {$pro} p ON p.id = f.professional_id
            WHERE f.id = %d LIMIT 1
        ", $id), ARRAY_A);

        if (!$row) {
            wp_send_json_error(['message' => 'Feedback não encontrado.']);
            return;
        }

        wp_send_json_success([
            'professional_name'  => $row['professional_name'] ?: "Prof. #{$row['professional_id']}",
            'client_name'        => $row['client_name']       ?: "Cliente #{$row['client_id']}",
            'rating'             => (int) $row['rating'],
            'comment'            => $row['comment'] ?? '',
            'date'               => date('d/m/Y H:i', strtotime($row['created_at'])),
            'resolution_text'    => $row['resolution_text']    ?? '',
            'resolution_severity'=> $row['resolution_severity'] ?? '',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    private function navTab(string $active, string $section, string $label, bool $standalone): void
    {
        $url    = $this->tabUrl(['section' => $section], $standalone);
        $isAct  = $active === $section ? 'nav-tab-active' : '';
        echo "<a href=\"" . esc_url($url) . "\" class=\"nav-tab {$isAct}\">{$label}</a>\n";
    }

    private function tabUrl(array $extra = [], bool $standalone = false): string
    {
        if ($standalone) {
            return add_query_arg(array_merge(['page' => 'limpvix-feedback'], $extra), admin_url('admin.php'));
        }
        return add_query_arg(
            array_merge(['page' => 'limpvix-settings', 'tab' => 'feedback-management'], $extra),
            admin_url('admin.php')
        );
    }

    private static function redirectUrl(string $msg): string
    {
        $ref = wp_get_referer() ?? '';
        if (strpos($ref, 'page=limpvix-feedback') !== false && strpos($ref, 'limpvix-settings') === false) {
            return add_query_arg(['page' => 'limpvix-feedback', 'message' => $msg, 'section' => 'c2'], admin_url('admin.php'));
        }
        return add_query_arg(['page' => 'limpvix-settings', 'tab' => 'feedback-management', 'message' => $msg, 'section' => 'c2'], admin_url('admin.php'));
    }

    private function badge(string $status): string
    {
        return match($status) {
            'approved' => '<span class="limpvix-badge limpvix-badge-success">Aprovado</span>',
            'rejected' => '<span class="limpvix-badge limpvix-badge-danger">Rejeitado</span>',
            default    => '<span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">Pendente</span>',
        };
    }

    private function stat(string $label, int $val, string $bg, string $border, string $color): void
    {
        echo "<div style=\"padding:14px 18px;border-radius:6px;border-left:4px solid {$border};background:{$bg};\">"
           . "<small style=\"display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;color:{$color};\">{$label}</small>"
           . "<strong style=\"font-size:30px;font-weight:800;display:block;line-height:1;color:{$color};\">{$val}</strong>"
           . "</div>\n";
    }
}
