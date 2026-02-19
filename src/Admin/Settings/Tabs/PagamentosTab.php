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
        // Nenhum POST handling para esta aba
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

        ?>
        <!-- Hero Card -->
        <div class="limpvix-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px; border: none; box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);">
            <div style="padding: 30px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div>
                        <h1 style="color: white; margin: 0 0 10px 0; font-size: 28px; font-weight: 600;">
                            💳 Sistema de Pagamentos & Payouts
                        </h1>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 16px;">
                            EFI Bank — pagamentos via PIX e repasses automáticos aos profissionais
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
                    <!-- Total Processado -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">💰 Total Processado</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            R$ <?php echo number_format($totalProcessed, 2, ',', '.'); ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            <?php echo $stats['total_completed']; ?> transferências
                        </div>
                    </div>

                    <!-- Aguardando -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">⏳ Aguardando</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            R$ <?php echo number_format($totalPending, 2, ',', '.'); ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            <?php echo $stats['total_pending'] + $stats['total_approved'] + $stats['total_on_hold']; ?> pendentes
                        </div>
                    </div>

                    <!-- Em Processamento -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">🔄 Processando</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            <?php echo $stats['total_processing']; ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            Em andamento
                        </div>
                    </div>

                    <!-- Falhas -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">❌ Falhas</div>
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
                            🏦
                        </div>
                        <div>
                            <div style="font-size: 14px; font-weight: 600; color: white; margin-bottom: 3px;">
                                EFI Bank — Gateway Primário (PIX)
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
                        Ver Todos os Payouts →
                    </a>
                </div>
            </div>
        </div>

        <!-- Card EFI Bank (Provider Primário) -->
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header" style="background: linear-gradient(135deg, #00897b 0%, #00695c 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                            🏦 EFI Bank — Configuração (Provider Primário)
                        </h3>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                            PIX Cash-Out automático · OAuth2 + mTLS · Sandbox & Produção
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

        <!-- Card: Payout Features (EFI Bank) -->
        <div>
            <!-- Payout Features -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        🚀 Sistema de Payouts - Features
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        Recursos implementados para repasses automáticos
                    </p>
                </div>
                <div class="limpvix-card-body">
                    <?php $features = $this->getPayoutFeaturesStatus(); ?>
                    <div style="display: flex; flex-direction: column; gap: 15px;">
                        <!-- Feature 1: Transferência Automática via PIX -->
                        <div style="display: flex; align-items: start; gap: 12px; padding: 12px; background: <?php echo $features['pix_transfer']['implemented'] ? '#f0fdf4' : '#fef3f2'; ?>; border-radius: 8px; border-left: 4px solid <?php echo $features['pix_transfer']['implemented'] ? '#10b981' : '#ef4444'; ?>;">
                            <span style="font-size: 24px;"><?php echo esc_html($features['pix_transfer']['icon']); ?></span>
                            <div>
                                <div style="font-weight: 600; color: <?php echo $features['pix_transfer']['implemented'] ? '#065f46' : '#991b1b'; ?>; margin-bottom: 4px;">
                                    Transferência Automática via PIX
                                </div>
                                <div style="font-size: 13px; color: <?php echo $features['pix_transfer']['implemented'] ? '#047857' : '#b91c1c'; ?>;">
                                    Repasses automáticos para profissionais após conclusão do serviço e feedback positivo
                                    <span style="font-weight: 600; margin-left: 8px;">(<?php echo esc_html($features['pix_transfer']['status']); ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Feature 2: Feedback Window Enforcement -->
                        <div style="display: flex; align-items: start; gap: 12px; padding: 12px; background: <?php echo $features['feedback_window']['implemented'] ? '#f0fdf4' : '#fffbeb'; ?>; border-radius: 8px; border-left: 4px solid <?php echo $features['feedback_window']['implemented'] ? '#10b981' : '#f59e0b'; ?>;">
                            <span style="font-size: 24px;"><?php echo esc_html($features['feedback_window']['icon']); ?></span>
                            <div>
                                <div style="font-weight: 600; color: <?php echo $features['feedback_window']['implemented'] ? '#065f46' : '#92400e'; ?>; margin-bottom: 4px;">
                                    Feedback Window Enforcement
                                </div>
                                <div style="font-size: 13px; color: <?php echo $features['feedback_window']['implemented'] ? '#047857' : '#b45309'; ?>;">
                                    Payouts retidos por 48h aguardando feedback do cliente (Golden Rule)
                                    <span style="font-weight: 600; margin-left: 8px;">(<?php echo esc_html($features['feedback_window']['status']); ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Feature 3: Reconciliação Automática -->
                        <div style="display: flex; align-items: start; gap: 12px; padding: 12px; background: <?php echo $features['reconciliation']['cron_active'] ? '#f0fdf4' : '#fffbeb'; ?>; border-radius: 8px; border-left: 4px solid <?php echo $features['reconciliation']['cron_active'] ? '#10b981' : '#f59e0b'; ?>;">
                            <span style="font-size: 24px;"><?php echo esc_html($features['reconciliation']['icon']); ?></span>
                            <div>
                                <div style="font-weight: 600; color: <?php echo $features['reconciliation']['cron_active'] ? '#065f46' : '#92400e'; ?>; margin-bottom: 4px;">
                                    Reconciliação Automática
                                </div>
                                <div style="font-size: 13px; color: <?php echo $features['reconciliation']['cron_active'] ? '#047857' : '#b45309'; ?>;">
                                    Cron job que sincroniza status de transferências com EFI Bank a cada 15 minutos
                                    <span style="font-weight: 600; margin-left: 8px;">(<?php echo esc_html($features['reconciliation']['status']); ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Feature 4: Retry Automático em Falhas -->
                        <div style="display: flex; align-items: start; gap: 12px; padding: 12px; background: <?php echo $features['retry_on_failure']['implemented'] ? '#f0fdf4' : '#fef3f2'; ?>; border-radius: 8px; border-left: 4px solid <?php echo $features['retry_on_failure']['implemented'] ? '#10b981' : '#ef4444'; ?>;">
                            <span style="font-size: 24px;"><?php echo esc_html($features['retry_on_failure']['icon']); ?></span>
                            <div>
                                <div style="font-weight: 600; color: <?php echo $features['retry_on_failure']['implemented'] ? '#065f46' : '#991b1b'; ?>; margin-bottom: 4px;">
                                    Retry Automático em Falhas
                                </div>
                                <div style="font-size: 13px; color: <?php echo $features['retry_on_failure']['implemented'] ? '#047857' : '#b91c1c'; ?>;">
                                    Sistema tenta até 3x automaticamente quando transferência falha (backoff exponencial)
                                    <span style="font-weight: 600; margin-left: 8px;">(<?php echo esc_html($features['retry_on_failure']['status']); ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Feature 5: Auditoria Completa -->
                        <div style="display: flex; align-items: start; gap: 12px; padding: 12px; background: <?php echo $features['audit_trail']['implemented'] ? '#f0fdf4' : '#fffbeb'; ?>; border-radius: 8px; border-left: 4px solid <?php echo $features['audit_trail']['implemented'] ? '#10b981' : '#f59e0b'; ?>;">
                            <span style="font-size: 24px;"><?php echo esc_html($features['audit_trail']['icon']); ?></span>
                            <div>
                                <div style="font-weight: 600; color: <?php echo $features['audit_trail']['implemented'] ? '#065f46' : '#92400e'; ?>; margin-bottom: 4px;">
                                    Auditoria Completa
                                </div>
                                <div style="font-size: 13px; color: <?php echo $features['audit_trail']['implemented'] ? '#047857' : '#b45309'; ?>;">
                                    Logs detalhados de todas as transações com raw_response do EFI Bank
                                    <span style="font-weight: 600; margin-left: 8px;">(<?php echo esc_html($features['audit_trail']['status']); ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Feature 6: Suporte a PIX, Conta Bancária e MP Account -->
                        <div style="display: flex; align-items: start; gap: 12px; padding: 12px; background: <?php echo $features['multi_recipient']['implemented'] ? '#f0fdf4' : '#fef3f2'; ?>; border-radius: 8px; border-left: 4px solid <?php echo $features['multi_recipient']['implemented'] ? '#10b981' : '#ef4444'; ?>;">
                            <span style="font-size: 24px;"><?php echo esc_html($features['multi_recipient']['icon']); ?></span>
                            <div>
                                <div style="font-weight: 600; color: <?php echo $features['multi_recipient']['implemented'] ? '#065f46' : '#991b1b'; ?>; margin-bottom: 4px;">
                                    Suporte a PIX, Conta Bancária e MP Account
                                </div>
                                <div style="font-size: 13px; color: <?php echo $features['multi_recipient']['implemented'] ? '#047857' : '#b91c1c'; ?>;">
                                    Profissional escolhe método preferido: PIX (instantâneo) ou Conta Bancária via EFI Bank
                                    <span style="font-weight: 600; margin-left: 8px;">(<?php echo esc_html($features['multi_recipient']['status']); ?>)</span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Button -->
                        <div style="margin-top: 10px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
                            <a href="<?php echo admin_url('admin.php?page=limpvix-professionals&tab=payouts'); ?>" class="button button-primary button-large" style="width: 100%; text-align: center; justify-content: center; display: flex; align-items: center; gap: 8px;">
                                <span>📊</span>
                                <span>Gerenciar Payouts</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /Card: Payout Features -->

        <!-- Informações Técnicas -->
        <div class="limpvix-card" style="margin-top: 20px;">
            <div class="limpvix-card-header" style="background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                    🔧 Arquitetura Técnica
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
                            <span style="font-size: 20px;">🏗️</span>
                            Domain Layer
                        </h4>
                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: #4b5563; line-height: 1.8;">
                            <?php foreach ($arch['domain'] as $class => $exists): ?>
                                <li>
                                    <span style="color: <?php echo $exists ? '#10b981' : '#ef4444'; ?>;">
                                        <?php echo $exists ? '✓' : '❌'; ?>
                                    </span>
                                    <code><?php echo esc_html($class); ?></code>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Application Layer -->
                    <div>
                        <h4 style="color: #1f2937; font-size: 16px; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 20px;">⚙️</span>
                            Application Layer
                        </h4>
                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: #4b5563; line-height: 1.8;">
                            <?php foreach ($arch['application'] as $class => $exists): ?>
                                <li>
                                    <span style="color: <?php echo $exists ? '#10b981' : '#ef4444'; ?>;">
                                        <?php echo $exists ? '✓' : '❌'; ?>
                                    </span>
                                    <code><?php echo esc_html($class); ?></code>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <!-- Infrastructure Layer -->
                    <div>
                        <h4 style="color: #1f2937; font-size: 16px; margin: 0 0 12px 0; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 20px;">🔌</span>
                            Infrastructure Layer
                        </h4>
                        <ul style="list-style: none; padding: 0; margin: 0; font-size: 13px; color: #4b5563; line-height: 1.8;">
                            <?php foreach ($arch['infrastructure'] as $class => $exists): ?>
                                <li>
                                    <span style="color: <?php echo $exists ? '#10b981' : '#ef4444'; ?>;">
                                        <?php echo $exists ? '✓' : '❌'; ?>
                                    </span>
                                    <code><?php echo esc_html($class); ?></code>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <!-- Database Info (DINÂMICO) -->
                <?php $dbInfo = $this->getPayoutDatabaseInfo(); ?>
                <div style="margin-top: 20px; padding: 15px; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb;">
                    <h4 style="color: #1f2937; font-size: 14px; margin: 0 0 10px 0; font-weight: 600;">
                        💾 Database Table: <code><?php echo esc_html($dbInfo['table_name']); ?></code>
                        <?php if ($dbInfo['exists']): ?>
                            <span style="color: #10b981; font-weight: 600;">✓ Criada</span>
                        <?php else: ?>
                            <span style="color: #ef4444; font-weight: 600;">❌ Não Criada</span>
                        <?php endif; ?>
                    </h4>
                    <?php if ($dbInfo['exists']): ?>
                        <div style="font-size: 13px; color: #6b7280; line-height: 1.6;">
                            <strong>Status Flow:</strong> <code>pending</code> → <code>approved</code> → <code>processing</code> → <code>completed</code> / <code>failed</code>
                            <br>
                            <strong>Índices:</strong> <?php echo esc_html(implode(', ', $dbInfo['indexes'])); ?>
                            <br>
                            <strong>Campos Timestamp:</strong> <?php echo count($dbInfo['timestamp_columns']); ?> campos
                            (<?php echo esc_html(implode(', ', array_slice($dbInfo['timestamp_columns'], 0, 5))); ?><?php echo count($dbInfo['timestamp_columns']) > 5 ? '...' : ''; ?>)
                            <br>
                            <strong>Auditoria:</strong>
                            <?php if ($dbInfo['has_audit']): ?>
                                <span style="color: #10b981;">✓ Completa</span>
                                (raw_response + <?php echo count($dbInfo['timestamp_columns']); ?> timestamps)
                            <?php else: ?>
                                <span style="color: #f59e0b;">⚠ Parcial</span>
                                (<?php echo in_array('raw_response', $dbInfo['columns']) ? 'tem raw_response' : 'falta raw_response'; ?>)
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size: 13px; color: #ef4444; line-height: 1.6;">
                            ⚠ Tabela não foi criada. Execute as migrations do plugin.
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

    private function getPayoutFeaturesStatus(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_payouts';

        $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;

        return [
            'pix_transfer' => [
                'implemented' => class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\EfiPayoutProvider')
                    && $tableExists,
                'icon' => (class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\EfiPayoutProvider') && $tableExists) ? '✅' : '❌',
                'status' => (class_exists('LimpVix\\Infrastructure\\Finance\\Providers\\EfiPayoutProvider') && $tableExists) ? 'Ativo' : 'Não Implementado'
            ],
            'feedback_window' => [
                'implemented' => class_exists('LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus')
                    && ($tableExists && $this->tableHasColumn($table, 'hold_until')),
                'icon' => (class_exists('LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus') && $tableExists && $this->tableHasColumn($table, 'hold_until')) ? '✅' : '⚠️',
                'status' => (class_exists('LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus') && $tableExists && $this->tableHasColumn($table, 'hold_until')) ? 'Ativo' : 'Parcial'
            ],
            'reconciliation' => [
                'implemented' => class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter'),
                'cron_active' => wp_next_scheduled('limpvix_reconcile_payouts') !== false,
                'icon' => (class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter') && wp_next_scheduled('limpvix_reconcile_payouts')) ? '✅' : '⚠️',
                'status' => wp_next_scheduled('limpvix_reconcile_payouts') ? 'Ativo' : (class_exists('LimpVix\\Infrastructure\\Cron\\PayoutReconciliationCronAdapter') ? 'Cron Desabilitado' : 'Não Implementado')
            ],
            'retry_on_failure' => [
                'implemented' => $tableExists && $this->tableHasColumn($table, 'retry_count'),
                'icon' => ($tableExists && $this->tableHasColumn($table, 'retry_count')) ? '✅' : '❌',
                'status' => ($tableExists && $this->tableHasColumn($table, 'retry_count')) ? 'Ativo' : 'Não Implementado'
            ],
            'audit_trail' => [
                'implemented' => $tableExists
                    && $this->tableHasColumn($table, 'raw_response')
                    && $this->tableHasColumn($table, 'created_at'),
                'icon' => ($tableExists && $this->tableHasColumn($table, 'raw_response')) ? '✅' : '⚠️',
                'status' => ($tableExists && $this->tableHasColumn($table, 'raw_response')) ? 'Completo' : 'Parcial'
            ],
            'multi_recipient' => [
                'implemented' => $tableExists && $this->tableHasColumn($table, 'recipient_type'),
                'icon' => ($tableExists && $this->tableHasColumn($table, 'recipient_type')) ? '✅' : '❌',
                'status' => ($tableExists && $this->tableHasColumn($table, 'recipient_type')) ? 'PIX + Conta Bancária' : 'Não Implementado'
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

        // Get indexes
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A);
        $indexNames = !empty($indexes) ? array_unique(array_column($indexes, 'Key_name')) : [];

        // Get columns
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $columnNames = !empty($columns) ? array_column($columns, 'Field') : [];

        // Check for timestamp columns
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
