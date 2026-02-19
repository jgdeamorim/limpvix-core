<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class PagamentosTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'pagamentos'; }
    public function getLabel(): string { return 'Pagamentos'; }
    public function getIcon(): string { return '💳'; }

    public function handleSave(): void
    {
        if (!isset($_POST['limpvix_save_pagamentos_settings'])) {
            return;
        }
        if (!check_admin_referer('limpvix_pagamentos_settings')) {
            return;
        }

        // Manual payout approval threshold (4-eyes)
        update_option('limpvix_manual_payout_approval_threshold',
            max(0, floatval($_POST['manual_payout_threshold'] ?? 500)));

        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=pagamentos&updated=1'));
        exit;
    }

    public function render(): void
    {
        // Buscar estatísticas de payouts
        $payoutRepo = new \LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository();
        $stats = $payoutRepo->getStats();

        // Calcular totais
        $totalPayouts = $stats['total_pending'] + $stats['total_approved'] + $stats['total_processing'] + $stats['total_completed'] + $stats['total_failed'] + $stats['total_on_hold'];
        $successRate = $totalPayouts > 0 ? round(($stats['total_completed'] / $totalPayouts) * 100, 1) : 0;

        // Valor total processado (completed)
        $totalProcessed = $stats['amount_completed'];

        // Valor aguardando processamento (pending + approved + on_hold)
        $totalPending = $stats['amount_pending'] + $stats['amount_approved'] + $stats['amount_on_hold'];

        // New data
        $recurringStats = $this->getRecurringPaymentStats();
        $ledgerStats = $this->getLedgerStats();
        $manualPayoutThreshold = get_option('limpvix_manual_payout_approval_threshold', 500.0);

        ?>
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_pagamentos_settings'); ?>

        <!-- SECTION 1: Hero Card (EXISTING) -->
        <div class="limpvix-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px; border: none; box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);">
            <div style="padding: 30px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div>
                        <h1 style="color: white; margin: 0 0 10px 0; font-size: 28px; font-weight: 600;">
                            Sistema de Pagamentos & Payouts
                        </h1>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 16px;">
                            EFI Bank — pagamentos via PIX e repasses automaticos aos profissionais
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); padding: 15px 25px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.3);">
                            <div style="font-size: 32px; font-weight: 700; color: white; line-height: 1;">
                                <?php echo $successRate; ?>%
                            </div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8); margin-top: 5px; font-weight: 500;">
                                Taxa de Sucesso
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats Grid -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 25px;">
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">Total Processado</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            R$ <?php echo number_format($totalProcessed, 2, ',', '.'); ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            <?php echo $stats['total_completed']; ?> transferencias
                        </div>
                    </div>

                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">Aguardando</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            R$ <?php echo number_format($totalPending, 2, ',', '.'); ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            <?php echo $stats['total_pending'] + $stats['total_approved'] + $stats['total_on_hold']; ?> pendentes
                        </div>
                    </div>

                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">Processando</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            <?php echo $stats['total_processing']; ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            Em andamento
                        </div>
                    </div>

                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">Falhas</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            <?php echo $stats['total_failed']; ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            <?php
                            $failureRate = $totalPayouts > 0 ? round(($stats['total_failed'] / $totalPayouts) * 100, 1) : 0;
                            echo $failureRate;
                            ?>% do total
                        </div>
                    </div>
                </div>

                <!-- Status EFI Bank -->
                <?php $efiStatusForHero = \LimpVix\Admin\Settings\EfiBankSettings::getConfigStatus(); ?>
                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; background: rgba(255, 255, 255, 0.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                            <span class="dashicons dashicons-bank" style="color: white;"></span>
                        </div>
                        <div>
                            <div style="font-size: 14px; font-weight: 600; color: white; margin-bottom: 3px;">
                                EFI Bank — Gateway Primario (PIX)
                            </div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8);">
                                <span style="color: <?php echo esc_attr($efiStatusForHero['status_color']); ?>;">
                                    <?php echo esc_html($efiStatusForHero['status_icon']); ?> <?php echo esc_html($efiStatusForHero['status_text']); ?>
                                </span>
                                <?php if (!empty($efiStatusForHero['missing'])): ?>
                                    <div style="margin-top: 5px; font-size: 11px; color: #fbbf24;">
                                        Faltando: <?php echo esc_html(implode(', ', $efiStatusForHero['missing'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=limpvix-professionals&tab=payouts'); ?>"
                       class="button button-primary"
                       style="background: white; color: #00695c; border: none; box-shadow: none; padding: 8px 16px; font-weight: 600;">
                        Ver Todos os Payouts &rarr;
                    </a>
                </div>
            </div>
        </div>

        <!-- SECTION 2: EFI Bank Config (EXISTING) -->
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header" style="background: linear-gradient(135deg, #00897b 0%, #00695c 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                            <span class="dashicons dashicons-bank" style="margin-right: 4px;"></span>
                            EFI Bank — Configuracao (Provider Primario)
                        </h3>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                            PIX Cash-Out automatico · OAuth2 + mTLS · Sandbox & Producao
                        </p>
                    </div>
                    <?php
                    $efiCardStatus = \LimpVix\Admin\Settings\EfiBankSettings::getConfigStatus();
                    ?>
                    <div style="padding: 6px 14px; background: rgba(255,255,255,0.2); border-radius: 20px; font-size: 12px; font-weight: 600; white-space: nowrap;">
                        <?php echo esc_html($efiCardStatus['status_icon'] . ' ' . $efiCardStatus['status_text']); ?>
                    </div>
                </div>
            </div>
            <div class="limpvix-card-body">
                <?php \LimpVix\Admin\Settings\EfiBankSettings::render(); ?>
            </div>
        </div>

        <!-- SECTION 3: Pagamentos Recorrentes (NEW) -->
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-update"></span>
                    Pagamentos Recorrentes
                </h3>
                <p>Cobrancas automaticas de contratos ativos (<code>wp_limpvix_recurring_payments</code>)</p>
            </div>
            <div class="limpvix-card-body">
                <?php if ($recurringStats['table_exists']): ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:15px;">
                    <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;padding:15px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo esc_html($recurringStats['total']); ?></div>
                        <div style="font-size:12px;color:#646970;margin-top:4px;">Total</div>
                    </div>
                    <?php foreach ($recurringStats['by_status'] as $status => $count): ?>
                    <?php
                        $statusColors = [
                            'completed' => ['bg' => '#e7f5e7', 'border' => '#00a32a', 'text' => '#00a32a'],
                            'pending' => ['bg' => '#fcf9e8', 'border' => '#dba617', 'text' => '#996800'],
                            'processing' => ['bg' => '#f0f6fc', 'border' => '#2271b1', 'text' => '#2271b1'],
                            'failed' => ['bg' => '#fce4e4', 'border' => '#d63638', 'text' => '#d63638'],
                            'cancelled' => ['bg' => '#f0f0f1', 'border' => '#c3c4c7', 'text' => '#646970'],
                        ];
                        $sc = $statusColors[$status] ?? ['bg' => '#fff', 'border' => '#c3c4c7', 'text' => '#1d2327'];
                    ?>
                    <div style="background:<?php echo $sc['bg']; ?>;border:1px solid <?php echo $sc['border']; ?>;border-radius:4px;padding:15px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:<?php echo $sc['text']; ?>;"><?php echo esc_html($count); ?></div>
                        <div style="font-size:12px;color:#646970;margin-top:4px;"><?php echo esc_html(ucfirst($status)); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;padding:15px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo esc_html($recurringStats['last_30_days']); ?></div>
                        <div style="font-size:12px;color:#646970;margin-top:4px;">Ultimos 30 dias</div>
                    </div>
                    <?php if ($recurringStats['retry_pending'] > 0): ?>
                    <div style="background:#fce4e4;border:1px solid #d63638;border-radius:4px;padding:15px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:#d63638;"><?php echo esc_html($recurringStats['retry_pending']); ?></div>
                        <div style="font-size:12px;color:#646970;margin-top:4px;">Retry Pendente</div>
                    </div>
                    <?php endif; ?>
                </div>

                <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin-top:15px;border-radius:0 4px 4px 0;">
                    <strong>Regras de Cobranca Recorrente:</strong><br>
                    Max 3 tentativas por cobranca · Cron diario: <code>limpvix_charge_recurring_payments</code><br>
                    Frequencias: semanal (&divide;4.33), quinzenal (&divide;2.16), mensal<br>
                    <?php
                    $cronNext = wp_next_scheduled('limpvix_charge_recurring_payments');
                    if ($cronNext) {
                        echo '<span style="color:#00a32a;font-weight:600;">Cron ativo</span> — proxima execucao: ' . esc_html(date_i18n('d/m/Y H:i', $cronNext));
                    } else {
                        echo '<span style="color:#dba617;font-weight:600;">Cron nao agendado</span>';
                    }
                    ?>
                </div>
                <?php else: ?>
                <p style="color:#646970;"><em>Tabela <code>wp_limpvix_recurring_payments</code> nao encontrada. Execute as migrations.</em></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- SECTION 4: Ledger Financeiro (NEW) -->
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-book"></span>
                    Ledger Financeiro
                </h3>
                <p>Registro imutavel de todos os eventos financeiros (<code>wp_limpvix_financial_ledger</code>)</p>
            </div>
            <div class="limpvix-card-body">
                <?php if ($ledgerStats['table_exists']): ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:15px;">
                    <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;padding:15px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo esc_html($ledgerStats['total']); ?></div>
                        <div style="font-size:12px;color:#646970;margin-top:4px;">Total Eventos</div>
                    </div>
                    <?php foreach ($ledgerStats['by_event_type'] as $type => $count): ?>
                    <?php
                        $typeColors = [
                            'payment_received' => '#00a32a',
                            'payout_authorized' => '#2271b1',
                            'transferred' => '#00a32a',
                            'dispute_opened' => '#d63638',
                        ];
                        $tc = $typeColors[$type] ?? '#1d2327';
                    ?>
                    <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:15px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:<?php echo $tc; ?>;"><?php echo esc_html($count); ?></div>
                        <div style="font-size:12px;color:#646970;margin-top:4px;"><?php echo esc_html(str_replace('_', ' ', $type)); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($ledgerStats['disputes_open'] > 0): ?>
                    <div style="background:#fce4e4;border:1px solid #d63638;border-radius:4px;padding:15px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:#d63638;"><?php echo esc_html($ledgerStats['disputes_open']); ?></div>
                        <div style="font-size:12px;color:#646970;margin-top:4px;">Disputas Abertas</div>
                    </div>
                    <?php endif; ?>
                    <div style="background:#fcf9e8;border:1px solid #dba617;border-radius:4px;padding:15px;text-align:center;">
                        <div style="font-size:28px;font-weight:700;color:#996800;"><?php echo esc_html($ledgerStats['last_7_days']); ?></div>
                        <div style="font-size:12px;color:#646970;margin-top:4px;">Ultimos 7 dias</div>
                    </div>
                </div>

                <div style="background:#e7f5e7;border:1px solid #00a32a;border-left:4px solid #00a32a;padding:12px 16px;margin-top:15px;border-radius:0 4px 4px 0;">
                    <strong>Append-only audit trail</strong> — nenhum registro e deletado ou alterado.<br>
                    <small>Cada evento financeiro (pagamento, autorizacao, transferencia, disputa) gera um registro imutavel com UUID de idempotencia.</small>
                </div>
                <?php else: ?>
                <p style="color:#646970;"><em>Tabela <code>wp_limpvix_financial_ledger</code> nao encontrada. Execute as migrations.</em></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- SECTION 5: Parametros Operacionais (NEW — EDITABLE) -->
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-admin-generic"></span>
                    Parametros Operacionais de Payout
                </h3>
                <p>Configuracoes e constantes do fluxo financeiro</p>
            </div>
            <div class="limpvix-card-body">
                <table class="form-table">
                    <tr>
                        <th scope="row">Threshold Aprovacao Manual (4-eyes):</th>
                        <td>
                            R$ <input type="number" name="manual_payout_threshold" value="<?php echo esc_attr($manualPayoutThreshold); ?>" step="0.01" min="0" class="small-text" style="width:120px;">
                            <p class="description">Payouts manuais acima deste valor requerem segundo aprovador (4-eyes policy). Lido por <code>CreateManualPayout</code>.</p>
                        </td>
                    </tr>
                </table>

                <h4 style="margin-top:20px;margin-bottom:10px;color:#1d2327;">Constantes do Sistema (read-only)</h4>
                <div style="display:flex;flex-wrap:wrap;gap:10px;">
                    <span style="display:inline-block;background:#f0f6fc;border:1px solid #c3c4c7;padding:6px 14px;border-radius:4px;font-size:13px;">
                        <strong>Max Retry:</strong> 3 tentativas
                    </span>
                    <span style="display:inline-block;background:#f0f6fc;border:1px solid #c3c4c7;padding:6px 14px;border-radius:4px;font-size:13px;">
                        <strong>Reconciliacao:</strong> cada 6h
                    </span>
                    <span style="display:inline-block;background:#f0f6fc;border:1px solid #c3c4c7;padding:6px 14px;border-radius:4px;font-size:13px;">
                        <strong>Feedback Hold:</strong> 48h timeout
                    </span>
                    <span style="display:inline-block;background:#f0f6fc;border:1px solid #c3c4c7;padding:6px 14px;border-radius:4px;font-size:13px;">
                        <strong>Negative Threshold:</strong> &lt; 3.0 estrelas
                    </span>
                    <span style="display:inline-block;background:#e7f5e7;border:1px solid #00a32a;padding:6px 14px;border-radius:4px;font-size:13px;">
                        <strong>Golden Rule:</strong> Payout so apos CHECKED_OUT + evidencia
                    </span>
                </div>

                <!-- Fluxo Operacional Completo -->
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-top:20px;">
                    <h4 style="margin:0 0 15px;color:#1d2327;font-size:15px;">Fluxo Operacional Completo</h4>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;">
                        <!-- Step 1 -->
                        <div style="background:#fff;border:1px solid #c3c4c7;border-top:3px solid #2271b1;border-radius:0 0 6px 6px;padding:14px;">
                            <div style="font-weight:700;color:#2271b1;margin-bottom:8px;font-size:13px;">1. Cliente Paga</div>
                            <div style="font-size:12px;color:#4b5563;line-height:1.6;">
                                PIX/Cartao via WooCommerce<br>
                                Order: PENDING &rarr; <strong>PAID</strong><br>
                                Briefing: &rarr; <strong>LOCKED</strong>
                            </div>
                        </div>
                        <!-- Step 2 -->
                        <div style="background:#fff;border:1px solid #c3c4c7;border-top:3px solid #f59e0b;border-radius:0 0 6px 6px;padding:14px;">
                            <div style="font-weight:700;color:#996800;margin-bottom:8px;font-size:13px;">2. Servico Executado</div>
                            <div style="font-size:12px;color:#4b5563;line-height:1.6;">
                                Check-in (GPS + geofence)<br>
                                Check-out (fotos obrigatorias)<br>
                                Execution: <strong>CHECKED_OUT</strong>
                            </div>
                        </div>
                        <!-- Step 3 -->
                        <div style="background:#fff;border:1px solid #c3c4c7;border-top:3px solid #9333ea;border-radius:0 0 6px 6px;padding:14px;">
                            <div style="font-weight:700;color:#7e22ce;margin-bottom:8px;font-size:13px;">3. Feedback (48h)</div>
                            <div style="font-size:12px;color:#4b5563;line-height:1.6;">
                                5 estrelas: payout imediato<br>
                                4 estrelas: hold 1h<br>
                                3 estrelas: hold 24h<br>
                                &lt;3: hold 24h + manual
                            </div>
                        </div>
                        <!-- Step 4 -->
                        <div style="background:#fff;border:1px solid #c3c4c7;border-top:3px solid #00a32a;border-radius:0 0 6px 6px;padding:14px;">
                            <div style="font-weight:700;color:#00a32a;margin-bottom:8px;font-size:13px;">4. Payout</div>
                            <div style="font-size:12px;color:#4b5563;line-height:1.6;">
                                EFI Bank PIX Cash-Out<br>
                                Reconciliacao cada 6h<br>
                                Retry em falhas (max 3x)
                            </div>
                        </div>
                    </div>
                </div>

                <div style="background:#fcf9e8;border:1px solid #dba617;border-left:4px solid #dba617;padding:12px 16px;margin-top:15px;border-radius:0 4px 4px 0;">
                    <strong>Referencia:</strong> Configuracoes de hold por feedback, valor minimo de payout e taxa da plataforma estao em
                    <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=profissionais'); ?>"><strong>Profissionais</strong></a> &rarr; Payouts & Feedback.
                    Calibracao de geo-tiers (multiplicadores e fees por regiao) em
                    <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=profissionais'); ?>"><strong>Profissionais</strong></a> &rarr; Geo-Tier IBGE.
                </div>
            </div>
        </div>

        <!-- Save Button -->
        <p class="submit" style="margin-bottom: 20px;">
            <button type="submit" name="limpvix_save_pagamentos_settings" class="button button-primary button-large">
                Salvar Parametros de Pagamento
            </button>
        </p>

        </form>

        <!-- SECTION 6: Payout Features Status (EXISTING — outside form, read-only) -->
        <div>
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        Sistema de Payouts - Features
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        Recursos implementados para repasses automaticos
                    </p>
                </div>
                <div class="limpvix-card-body">
                    <?php $features = $this->getPayoutFeaturesStatus(); ?>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <?php
                        $featureList = [
                            'pix_transfer' => ['title' => 'Transferencia Automatica via PIX', 'desc' => 'Repasses automaticos para profissionais apos conclusao do servico e feedback positivo'],
                            'feedback_window' => ['title' => 'Feedback Window Enforcement', 'desc' => 'Payouts retidos por 48h aguardando feedback do cliente (Golden Rule)'],
                            'reconciliation' => ['title' => 'Reconciliacao Automatica', 'desc' => 'Cron job que sincroniza status de transferencias com EFI Bank a cada 6 horas'],
                            'retry_on_failure' => ['title' => 'Retry Automatico em Falhas', 'desc' => 'Sistema tenta ate 3x automaticamente quando transferencia falha'],
                            'audit_trail' => ['title' => 'Auditoria Completa', 'desc' => 'Logs detalhados de todas as transacoes com raw_response do EFI Bank'],
                            'multi_recipient' => ['title' => 'Suporte a PIX e Conta Bancaria', 'desc' => 'Profissional escolhe metodo preferido: PIX (instantaneo) ou Conta Bancaria via EFI Bank'],
                        ];
                        foreach ($featureList as $key => $featureInfo):
                            $f = $features[$key];
                            $impl = $f['implemented'] ?? false;
                            $cronKey = $f['cron_active'] ?? $impl;
                            $bgColor = $impl ? '#f0fdf4' : ($cronKey ? '#fffbeb' : '#fef3f2');
                            $borderColor = $impl ? '#10b981' : ($cronKey ? '#f59e0b' : '#ef4444');
                            $titleColor = $impl ? '#065f46' : '#991b1b';
                            $descColor = $impl ? '#047857' : '#b91c1c';
                        ?>
                        <div style="display: flex; align-items: start; gap: 12px; padding: 12px; background: <?php echo $bgColor; ?>; border-radius: 8px; border-left: 4px solid <?php echo $borderColor; ?>;">
                            <span style="font-size: 24px;"><?php echo esc_html($f['icon']); ?></span>
                            <div>
                                <div style="font-weight: 600; color: <?php echo $titleColor; ?>; margin-bottom: 4px;">
                                    <?php echo esc_html($featureInfo['title']); ?>
                                </div>
                                <div style="font-size: 13px; color: <?php echo $descColor; ?>;">
                                    <?php echo esc_html($featureInfo['desc']); ?>
                                    <span style="font-weight: 600; margin-left: 8px;">(<?php echo esc_html($f['status']); ?>)</span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div style="margin-top: 10px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                            <a href="<?php echo admin_url('admin.php?page=limpvix-professionals&tab=payouts'); ?>" class="button button-primary button-large" style="width: 100%; text-align: center; justify-content: center; display: flex; align-items: center; gap: 8px;">
                                <span class="dashicons dashicons-chart-bar"></span>
                                <span>Gerenciar Payouts</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SECTION 7: Arquitetura Técnica (EXISTING) -->
        <div class="limpvix-card" style="margin-top: 20px;">
            <div class="limpvix-card-header" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                    <span class="dashicons dashicons-admin-tools" style="margin-right: 4px;"></span>
                    Arquitetura Tecnica
                </h3>
                <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                    Componentes e fluxo de processamento de payouts
                </p>
            </div>
            <div class="limpvix-card-body">
                <?php $arch = $this->getPayoutArchitectureStatus(); ?>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                    <!-- Domain Layer -->
                    <div>
                        <h4 style="color: #1f2937; font-size: 16px; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-building"></span>
                            Domain Layer
                        </h4>
                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: #4b5563; line-height: 1.8;">
                            <?php foreach ($arch['domain'] as $class => $exists): ?>
                                <li>
                                    <span style="color: <?php echo $exists ? '#10b981' : '#ef4444'; ?>;">
                                        <?php echo $exists ? '&#10003;' : '&#10007;'; ?>
                                    </span>
                                    <code><?php echo esc_html($class); ?></code>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Application Layer -->
                    <div>
                        <h4 style="color: #1f2937; font-size: 16px; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-admin-generic"></span>
                            Application Layer
                        </h4>
                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: #4b5563; line-height: 1.8;">
                            <?php foreach ($arch['application'] as $class => $exists): ?>
                                <li>
                                    <span style="color: <?php echo $exists ? '#10b981' : '#ef4444'; ?>;">
                                        <?php echo $exists ? '&#10003;' : '&#10007;'; ?>
                                    </span>
                                    <code><?php echo esc_html($class); ?></code>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Infrastructure Layer -->
                    <div>
                        <h4 style="color: #1f2937; font-size: 16px; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                            <span class="dashicons dashicons-admin-plugins"></span>
                            Infrastructure Layer
                        </h4>
                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: #4b5563; line-height: 1.8;">
                            <?php foreach ($arch['infrastructure'] as $class => $exists): ?>
                                <li>
                                    <span style="color: <?php echo $exists ? '#10b981' : '#ef4444'; ?>;">
                                        <?php echo $exists ? '&#10003;' : '&#10007;'; ?>
                                    </span>
                                    <code><?php echo esc_html($class); ?></code>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <!-- Database Info -->
                <?php $dbInfo = $this->getPayoutDatabaseInfo(); ?>
                <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <h4 style="color: #1f2937; font-size: 14px; margin: 0 0 10px 0; font-weight: 600;">
                        <span class="dashicons dashicons-database" style="font-size:16px;"></span>
                        Database Table: <code><?php echo esc_html($dbInfo['table_name']); ?></code>
                        <?php if ($dbInfo['exists']): ?>
                            <span style="color: #10b981; font-weight: 600;">&#10003; Criada</span>
                        <?php else: ?>
                            <span style="color: #ef4444; font-weight: 600;">&#10007; Nao Criada</span>
                        <?php endif; ?>
                    </h4>
                    <?php if ($dbInfo['exists']): ?>
                        <div style="font-size: 13px; color: #6b7280; line-height: 1.6;">
                            <strong>Status Flow:</strong> <code>pending</code> &rarr; <code>approved</code> &rarr; <code>processing</code> &rarr; <code>completed</code> / <code>failed</code>
                            <br>
                            <strong>Indices:</strong> <?php echo esc_html(implode(', ', $dbInfo['indexes'])); ?>
                            <br>
                            <strong>Campos Timestamp:</strong> <?php echo count($dbInfo['timestamp_columns']); ?> campos
                            (<?php echo esc_html(implode(', ', array_slice($dbInfo['timestamp_columns'], 0, 5))); ?><?php echo count($dbInfo['timestamp_columns']) > 5 ? '...' : ''; ?>)
                            <br>
                            <strong>Auditoria:</strong>
                            <?php if ($dbInfo['has_audit']): ?>
                                <span style="color: #10b981;">&#10003; Completa</span>
                                (raw_response + <?php echo count($dbInfo['timestamp_columns']); ?> timestamps)
                            <?php else: ?>
                                <span style="color: #f59e0b;">&#9888; Parcial</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size: 13px; color: #ef4444; line-height: 1.6;">
                            Tabela nao foi criada. Execute as migrations do plugin.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    private function getRecurringPaymentStats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_recurring_payments';

        $tableExists = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s", DB_NAME, $table)
        );

        if (!$tableExists) {
            return ['table_exists' => false, 'total' => 0, 'by_status' => [], 'last_30_days' => 0, 'retry_pending' => 0];
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $statusRows = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status ORDER BY cnt DESC",
            ARRAY_A
        );
        $byStatus = [];
        foreach ($statusRows as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }

        $last30 = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        $retryPending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND attempt_count < 3"
        );

        return [
            'table_exists' => true,
            'total' => $total,
            'by_status' => $byStatus,
            'last_30_days' => $last30,
            'retry_pending' => $retryPending,
        ];
    }

    private function getLedgerStats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_financial_ledger';

        $tableExists = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s", DB_NAME, $table)
        );

        if (!$tableExists) {
            return ['table_exists' => false, 'total' => 0, 'by_event_type' => [], 'disputes_open' => 0, 'last_7_days' => 0];
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $typeRows = $wpdb->get_results(
            "SELECT event_type, COUNT(*) as cnt FROM {$table} GROUP BY event_type ORDER BY cnt DESC LIMIT 8",
            ARRAY_A
        );
        $byType = [];
        foreach ($typeRows as $row) {
            $byType[$row['event_type']] = (int) $row['cnt'];
        }

        $disputesOpen = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE event_type = 'dispute_opened' AND resolved = 0"
        );

        $last7 = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return [
            'table_exists' => true,
            'total' => $total,
            'by_event_type' => $byType,
            'disputes_open' => $disputesOpen,
            'last_7_days' => $last7,
        ];
    }

    private function getPayoutFeaturesStatus(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_payouts';

        $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;

        return [
            'pix_transfer' => [
                'implemented' => class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\EfiPayoutProvider')
                    && $tableExists,
                'icon' => (class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\EfiPayoutProvider') && $tableExists) ? '&#10003;' : '&#10007;',
                'status' => (class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\EfiPayoutProvider') && $tableExists) ? 'Ativo' : 'Nao Implementado'
            ],
            'feedback_window' => [
                'implemented' => class_exists('LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus')
                    && ($tableExists && $this->tableHasColumn($table, 'hold_until')),
                'icon' => (class_exists('LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus') && $tableExists && $this->tableHasColumn($table, 'hold_until')) ? '&#10003;' : '&#9888;',
                'status' => (class_exists('LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus') && $tableExists && $this->tableHasColumn($table, 'hold_until')) ? 'Ativo' : 'Parcial'
            ],
            'reconciliation' => [
                'implemented' => class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter'),
                'cron_active' => wp_next_scheduled('limpvix_reconcile_payouts') !== false,
                'icon' => (class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter') && wp_next_scheduled('limpvix_reconcile_payouts')) ? '&#10003;' : '&#9888;',
                'status' => wp_next_scheduled('limpvix_reconcile_payouts') ? 'Ativo' : (class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter') ? 'Cron Desabilitado' : 'Nao Implementado')
            ],
            'retry_on_failure' => [
                'implemented' => $tableExists && $this->tableHasColumn($table, 'retry_count'),
                'icon' => ($tableExists && $this->tableHasColumn($table, 'retry_count')) ? '&#10003;' : '&#10007;',
                'status' => ($tableExists && $this->tableHasColumn($table, 'retry_count')) ? 'Ativo' : 'Nao Implementado'
            ],
            'audit_trail' => [
                'implemented' => $tableExists
                    && $this->tableHasColumn($table, 'raw_response')
                    && $this->tableHasColumn($table, 'created_at'),
                'icon' => ($tableExists && $this->tableHasColumn($table, 'raw_response')) ? '&#10003;' : '&#9888;',
                'status' => ($tableExists && $this->tableHasColumn($table, 'raw_response')) ? 'Completo' : 'Parcial'
            ],
            'multi_recipient' => [
                'implemented' => $tableExists && $this->tableHasColumn($table, 'recipient_type'),
                'icon' => ($tableExists && $this->tableHasColumn($table, 'recipient_type')) ? '&#10003;' : '&#10007;',
                'status' => ($tableExists && $this->tableHasColumn($table, 'recipient_type')) ? 'PIX + Conta Bancaria' : 'Nao Implementado'
            ],
        ];
    }

    private function getPayoutArchitectureStatus(): array
    {
        return [
            'domain' => [
                'PayoutRepositoryInterface' => interface_exists('LimpVix\\Domain\\Finance\\PayoutRepositoryInterface'),
                'FinancialTransitionEvent' => class_exists('LimpVix\\Domain\\Finance\\FinancialTransitionEvent'),
            ],
            'application' => [
                'ExecutePayout' => class_exists('LimpVix\\Application\\UseCases\\Financial\\ExecutePayout'),
                'CompleteServiceWithPayout' => class_exists('LimpVix\\Application\\UseCases\\Order\\CompleteServiceWithPayout'),
                'PayoutReconciliationService' => class_exists('LimpVix\\Application\\Services\\PayoutReconciliationService'),
                'AutomaticPayoutDispatcher' => class_exists('LimpVix\\Infrastructure\\Adapters\\AutomaticPayoutDispatcher'),
            ],
            'infrastructure' => [
                'WpPayoutRepository' => class_exists('LimpVix\\Infrastructure\\Finance\\Repositories\\WpPayoutRepository'),
                'EfiPayoutProvider' => class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\EfiPayoutProvider'),
                'PayoutReconciliationCronAdapter' => class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter'),
                'ReleasePayoutHoldOnFeedbackApproved' => class_exists('LimpVix\\Infrastructure\\EventListeners\\ReleasePayoutHoldOnFeedbackApproved'),
            ],
        ];
    }

    private function getPayoutDatabaseInfo(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_payouts';

        $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;

        if (!$tableExists) {
            return [
                'exists' => false,
                'table_name' => $table,
                'indexes' => [],
                'columns' => [],
                'timestamp_columns' => [],
                'has_audit' => false,
            ];
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $indexNames = !empty($indexes) ? array_unique(array_column($indexes, 'Key_name')) : [];

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $columnNames = !empty($columns) ? array_column($columns, 'Field') : [];

        $timestampColumns = array_filter($columnNames, fn($col) => str_ends_with($col, '_at'));

        return [
            'exists' => true,
            'table_name' => $table,
            'indexes' => $indexNames,
            'columns' => $columnNames,
            'timestamp_columns' => $timestampColumns,
            'has_audit' => in_array('raw_response', $columnNames) && count($timestampColumns) >= 5,
        ];
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        global $wpdb;
        $result = $wpdb->get_results($wpdb->prepare(
            "SHOW COLUMNS FROM {$table} LIKE %s",
            $column
        ), ARRAY_A);
        return !empty($result);
    }
}
