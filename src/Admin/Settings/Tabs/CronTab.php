<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class CronTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'cron'; }
    public function getLabel(): string { return 'Cron & Acoes'; }
    public function getIcon(): string { return '⏰'; }

    public function handleSave(): void
    {
        // No POST handling for this tab
    }

    public function render(): void
    {
        // Inicializar CronMonitor
        $cronMonitor = new \LimpVix\Application\Services\CronMonitor();

        // Buscar cron jobs agendados DINAMICAMENTE do WordPress
        $allCrons = _get_cron_array();
        $limpvixCrons = [];

        // Filtrar apenas cron jobs LimpVix
        foreach ($allCrons as $timestamp => $cron_array) {
            foreach ($cron_array as $hook => $details) {
                if (strpos($hook, 'limpvix_') === 0) {
                    $schedule_name = $details[array_key_first($details)]['schedule'] ?? 'single';
                    $limpvixCrons[$hook] = [
                        'hook' => $hook,
                        'next_run' => $timestamp,
                        'schedule_name' => $schedule_name,
                        'is_overdue' => $timestamp < time(),
                    ];
                }
            }
        }

        // Mapa de nomes amigáveis e descrições (baseado no hook name)
        $cronMetadata = [
            'limpvix_check_contract_expiration' => [
                'name' => 'Verificar Contratos Expirando',
                'description' => 'Verifica contratos que estão próximos de expirar e envia notificações'
            ],
            'limpvix_process_review_timer' => [
                'name' => 'Processar Timer de Reviews',
                'description' => 'Processa timers de janela de feedback e reviews de clientes'
            ],
            'limpvix_send_feedback_reminders' => [
                'name' => 'Enviar Lembretes de Feedback',
                'description' => 'Envia lembretes para clientes que ainda não deram feedback'
            ],
            'limpvix_process_payout_batch' => [
                'name' => 'Processar Batch de Payouts',
                'description' => 'Processa lotes de payouts pendentes para profissionais'
            ],
            'limpvix_sync_payouts' => [
                'name' => 'Sincronizar Payouts MercadoPago',
                'description' => 'Sincroniza status de payouts com MercadoPago API'
            ],
            'limpvix_retry_failed_payouts' => [
                'name' => 'Retentar Payouts Falhos',
                'description' => 'Tenta reprocessar payouts que falharam anteriormente'
            ],
            'limpvix_contracts_daily_check' => [
                'name' => 'Check Diário de Contratos',
                'description' => 'Verificação diária de status e automações de contratos'
            ],
            'limpvix_contracts_weekly_briefing' => [
                'name' => 'Briefing Semanal de Contratos',
                'description' => 'Gera briefing semanal de contratos ativos e métricas'
            ],
            'limpvix_fallback_send_offers' => [
                'name' => 'Fallback Envio de Offers',
                'description' => 'Fallback para garantir envio de offers aos profissionais'
            ],
            'limpvix_clean_message_queue' => [
                'name' => 'Limpar Fila de Mensagens',
                'description' => 'Remove mensagens antigas e processadas da fila'
            ],
            'limpvix_mp_periodic_sync' => [
                'name' => 'Sincronização MercadoPago',
                'description' => 'Sincronização periódica de dados com MercadoPago'
            ],
            'limpvix_charge_recurring_payments' => [
                'name' => 'Cobrar Pagamentos Recorrentes',
                'description' => 'Processa cobranças automáticas de contratos recorrentes'
            ],
            'limpvix_reconcile_payouts' => [
                'name' => 'Reconciliar Payouts',
                'description' => 'Reconcilia status de payouts com gateway de pagamento'
            ],
            'limpvix_payment_authorization_timeout' => [
                'name' => 'Timeout de Autorização de Pagamento',
                'description' => 'Verifica e processa timeouts de autorização de pagamento'
            ],
        ];

        // Calcular threshold baseado no schedule
        $scheduleThresholds = [
            'limpvix_five_minutes' => 0.5, // 30 minutos
            'every_15_minutes' => 1, // 1 hora
            'hourly' => 2, // 2 horas
            'twicedaily' => 13, // 13 horas
            'daily' => 25, // 25 horas
            'limpvix_daily' => 25, // 25 horas
            'weekly' => 169, // 7 dias + 1 hora
            'limpvix_6hours' => 7, // 7 horas
        ];

        // Obter status de todos os jobs AGENDADOS
        $allStatuses = [];
        $healthyCount = 0;
        $warningCount = 0;
        $criticalCount = 0;
        $unknownCount = 0;

        foreach ($limpvixCrons as $hook => $cronInfo) {
            // Remover prefixo limpvix_ para usar como job key no CronMonitor
            $jobKey = str_replace('limpvix_', '', $hook);

            // Obter threshold baseado no schedule
            $maxAgeHours = $scheduleThresholds[$cronInfo['schedule_name']] ?? 25;

            // Obter status do CronMonitor
            $status = $cronMonitor->getJobStatus($jobKey, $maxAgeHours);

            // Adicionar informações do cron agendado
            $status['hook'] = $hook;
            $status['schedule_name'] = $cronInfo['schedule_name'];
            $status['next_run'] = date('Y-m-d H:i:s', $cronInfo['next_run']);
            $status['is_overdue'] = $cronInfo['is_overdue'];

            // Converter schedule name para display amigável
            $schedules = wp_get_schedules();
            if (isset($schedules[$cronInfo['schedule_name']])) {
                $status['schedule'] = $schedules[$cronInfo['schedule_name']]['display'];
            } else {
                $status['schedule'] = ucfirst(str_replace('_', ' ', $cronInfo['schedule_name']));
            }

            // Adicionar metadata (nome e descrição)
            if (isset($cronMetadata[$hook])) {
                $status['display_name'] = $cronMetadata[$hook]['name'];
                $status['description'] = $cronMetadata[$hook]['description'];
            } else {
                // Fallback: usar o hook name como display name
                $status['display_name'] = ucwords(str_replace(['limpvix_', '_'], ['', ' '], $hook));
                $status['description'] = 'Cron job registrado no sistema';
            }

            // Se está atrasado, forçar health para critical
            if ($cronInfo['is_overdue'] && $status['health'] !== 'healthy') {
                $status['health'] = 'critical';
                $hoursLate = round((time() - $cronInfo['next_run']) / 3600, 1);
                $status['message'] = "Cron atrasado em {$hoursLate}h (deveria ter executado em {$status['next_run']})";
            }

            $allStatuses[] = $status;

            // Contar por health
            switch ($status['health']) {
                case 'healthy':
                    $healthyCount++;
                    break;
                case 'warning':
                    $warningCount++;
                    break;
                case 'critical':
                    $criticalCount++;
                    break;
                default:
                    $unknownCount++;
            }
        }

        $totalJobs = count($limpvixCrons);
        $healthPercentage = $totalJobs > 0 ? round(($healthyCount / $totalJobs) * 100) : 0;

        // Verificar ações pendentes no Action Scheduler (simulado - precisa do plugin Action Scheduler)
        $actionSchedulerActive = function_exists('as_next_scheduled_action');
        $pendingActions = 0;
        $pastDueActions = 0;

        if ($actionSchedulerActive) {
            // Contar ações pendentes
            global $wpdb;
            $actions_table = $wpdb->prefix . 'actionscheduler_actions';

            if ($wpdb->get_var("SHOW TABLES LIKE '{$actions_table}'") === $actions_table) {
                $pendingActions = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$actions_table} WHERE status = 'pending'"
                );

                $pastDueActions = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$actions_table}
                     WHERE status = 'pending'
                     AND scheduled_date_gmt < UTC_TIMESTAMP()"
                );
            }
        }

        ?>
        <!-- Hero Card -->
        <div class="limpvix-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px; border: none; box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);">
            <div style="padding: 30px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div>
                        <h1 style="color: white; margin: 0 0 10px 0; font-size: 28px; font-weight: 600;">
                            ⏰ Sistema de Cron Jobs & Ações Agendadas
                        </h1>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 16px;">
                            Monitoramento de tarefas automáticas e ações em background
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); padding: 15px 25px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.3);">
                            <div style="font-size: 32px; font-weight: 700; color: white; line-height: 1;">
                                <?php echo $healthPercentage; ?>%
                            </div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8); margin-top: 5px; font-weight: 500;">
                                Health Score
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats Grid -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 25px;">
                    <!-- Healthy -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">✅ Healthy</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            <?php echo $healthyCount; ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            Executando normalmente
                        </div>
                    </div>

                    <!-- Warning -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">⚠️ Warning</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            <?php echo $warningCount; ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            Executou mas falhou
                        </div>
                    </div>

                    <!-- Critical -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">❌ Critical</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            <?php echo $criticalCount; ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            Não executou recentemente
                        </div>
                    </div>

                    <!-- Ações Pendentes -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 20px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2);">
                        <div style="font-size: 14px; font-weight: 600; color: rgba(255, 255, 255, 0.8); margin-bottom: 8px;">📋 Past Due</div>
                        <div style="font-size: 24px; font-weight: 700; color: white; margin-bottom: 5px;">
                            <?php echo $pastDueActions; ?>
                        </div>
                        <div style="font-size: 12px; color: rgba(255, 255, 255, 0.7);">
                            Ações atrasadas
                        </div>
                    </div>
                </div>

                <!-- Action Scheduler Link -->
                <?php if ($actionSchedulerActive): ?>
                <div style="margin-top: 20px; padding: 15px; background: rgba(255, 255, 255, 0.1); border-radius: 8px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; background: rgba(255, 255, 255, 0.2); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 20px;">
                            📊
                        </div>
                        <div>
                            <div style="font-size: 14px; font-weight: 600; color: white; margin-bottom: 3px;">
                                Action Scheduler
                            </div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8);">
                                <?php echo $pendingActions; ?> ações pendentes no total
                            </div>
                        </div>
                    </div>
                    <a href="<?php echo admin_url('tools.php?page=action-scheduler&status=past-due&order=asc'); ?>"
                       class="button button-primary"
                       style="background: white; color: #667eea; border: none; box-shadow: none; padding: 8px 16px; font-weight: 600;">
                        Ver Ações Atrasadas →
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Lista de Cron Jobs -->
        <div class="limpvix-card">
            <div class="limpvix-card-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                    📋 Cron Jobs Registrados (<?php echo $totalJobs; ?>)
                </h3>
                <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                    Status de todas as tarefas automáticas do sistema
                </p>
            </div>
            <div class="limpvix-card-body" style="padding: 0;">
                <table class="wp-list-table widefat fixed striped" style="border: none; margin: 0;">
                    <thead>
                        <tr>
                            <th style="padding: 12px 20px; width: 50px;">Status</th>
                            <th style="padding: 12px 20px;">Cron Job</th>
                            <th style="padding: 12px 20px; width: 150px;">Frequência</th>
                            <th style="padding: 12px 20px; width: 180px;">Última Execução</th>
                            <th style="padding: 12px 20px; width: 100px;">Duração</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allStatuses as $status): ?>
                        <tr>
                            <td style="padding: 12px 20px; text-align: center;">
                                <?php
                                $healthEmoji = match($status['health']) {
                                    'healthy' => '<span style="font-size: 24px; color: #10b981;" title="Healthy">✅</span>',
                                    'warning' => '<span style="font-size: 24px; color: #f59e0b;" title="Warning">⚠️</span>',
                                    'critical' => '<span style="font-size: 24px; color: #ef4444;" title="Critical">❌</span>',
                                    default => '<span style="font-size: 24px; color: #6b7280;" title="Unknown">❓</span>',
                                };
                                echo $healthEmoji;
                                ?>
                            </td>
                            <td style="padding: 12px 20px;">
                                <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">
                                    <?php echo esc_html($status['display_name']); ?>
                                </div>
                                <div style="font-size: 13px; color: #6b7280;">
                                    <?php echo esc_html($status['description']); ?>
                                </div>
                                <?php if ($status['error']): ?>
                                <div style="font-size: 12px; color: #ef4444; margin-top: 4px;">
                                    <strong>Erro:</strong> <?php echo esc_html($status['error']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 20px;">
                                <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                    <?php echo esc_html($status['schedule']); ?>
                                </code>
                            </td>
                            <td style="padding: 12px 20px;">
                                <?php if ($status['last_run']): ?>
                                    <div style="font-size: 13px; color: #1f2937;">
                                        <?php echo esc_html($status['last_run']); ?>
                                    </div>
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                        (<?php echo esc_html($status['age_hours']); ?>h atrás)
                                    </div>
                                <?php else: ?>
                                    <span style="color: #9ca3af; font-style: italic;">Nunca executou</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 20px;">
                                <?php if ($status['duration_ms']): ?>
                                    <?php
                                    $durationSeconds = $status['duration_ms'] / 1000;
                                    echo sprintf('%.2fs', $durationSeconds);
                                    ?>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Informações do Sistema -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <!-- WordPress Cron Info -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        ⚙️ WordPress Cron System
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        Configuração e status do sistema de cron
                    </p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table" style="margin: 0;">
                        <tr>
                            <th style="width: 200px;">DISABLE_WP_CRON:</th>
                            <td>
                                <?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?>
                                    <span style="color: #10b981; font-weight: 600;">✓ Desabilitado</span>
                                    <p class="description" style="margin-top: 8px;">
                                        WP-Cron desabilitado. Sistema cron do servidor (crontab) está controlando as execuções. ✅ Recomendado para produção.
                                    </p>
                                <?php else: ?>
                                    <span style="color: #f59e0b; font-weight: 600;">⚠ Habilitado</span>
                                    <p class="description" style="margin-top: 8px;">
                                        WP-Cron executado via visitas ao site. Pode ter atrasos. Recomenda-se desabilitar e usar cron do servidor.
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Cron Schedules:</th>
                            <td>
                                <?php
                                $schedules = wp_get_schedules();
                                $customSchedules = 0;
                                foreach ($schedules as $key => $schedule) {
                                    if (strpos($key, 'limpvix_') === 0) {
                                        $customSchedules++;
                                    }
                                }
                                ?>
                                <strong><?php echo count($schedules); ?> intervalos registrados</strong>
                                (<?php echo $customSchedules; ?> personalizados LimpVix)
                            </td>
                        </tr>
                        <tr>
                            <th>Próxima Execução:</th>
                            <td>
                                <?php
                                $nextCron = wp_next_scheduled('wp_cron');
                                if ($nextCron) {
                                    $timeUntil = $nextCron - time();
                                    $minutesUntil = round($timeUntil / 60);
                                    echo sprintf('Em %d minutos (%s)', $minutesUntil, date('Y-m-d H:i:s', $nextCron));
                                } else {
                                    echo '<span style="color: #9ca3af;">Não agendado</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Action Scheduler Info -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        📊 Action Scheduler
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        Sistema de ações assíncronas (WooCommerce)
                    </p>
                </div>
                <div class="limpvix-card-body">
                    <?php if ($actionSchedulerActive): ?>
                        <table class="form-table" style="margin: 0;">
                            <tr>
                                <th style="width: 200px;">Status:</th>
                                <td>
                                    <span style="color: #10b981; font-weight: 600;">✓ Ativo</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Ações Pendentes:</th>
                                <td><strong><?php echo $pendingActions; ?></strong> ações aguardando execução</td>
                            </tr>
                            <tr>
                                <th>Ações Atrasadas:</th>
                                <td>
                                    <?php if ($pastDueActions > 0): ?>
                                        <span style="color: #ef4444; font-weight: 600;">
                                            <?php echo $pastDueActions; ?> ações atrasadas
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #10b981; font-weight: 600;">
                                            ✓ Nenhuma ação atrasada
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Gerenciar:</th>
                                <td>
                                    <a href="<?php echo admin_url('tools.php?page=action-scheduler'); ?>" class="button">
                                        Ver Action Scheduler
                                    </a>
                                    <?php if ($pastDueActions > 0): ?>
                                    <a href="<?php echo admin_url('tools.php?page=action-scheduler&status=past-due&order=asc'); ?>" class="button button-primary" style="margin-left: 10px;">
                                        Ver Atrasadas (<?php echo $pastDueActions; ?>)
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    <?php else: ?>
                        <p style="color: #9ca3af; font-style: italic;">
                            Plugin Action Scheduler não detectado. Instale WooCommerce ou Action Scheduler standalone para habilitar ações assíncronas.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function getLimpVixHooksStatus(): array
    {
        global $wp_filter;

        $expectedHooks = [
            'limpvix_execution_validated' => 'Disparar payout',
            'limpvix_feedback_submitted' => 'Calcular score profissional',
            'limpvix_contract_cancelled' => 'Cancelar contrato',
            'limpvix_professional_updated' => 'Sincronizar profissional',
            'limpvix_order_completed' => 'Redirecionar para Briefing',
            'limpvix_payout_processed' => 'Processar repasse profissional',
            'admin_menu' => 'Ocultar menus para staff',
        ];

        $result = [];

        foreach ($expectedHooks as $hook => $description) {
            $isRegistered = isset($wp_filter[$hook]) && !empty($wp_filter[$hook]->callbacks);

            $callbackCount = 0;
            if ($isRegistered) {
                foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                    $callbackCount += count($callbacks);
                }
            }

            $result[$hook] = [
                'description' => $description,
                'registered' => $isRegistered,
                'callback_count' => $callbackCount,
                'status' => $isRegistered ? 'active' : 'not_registered',
            ];
        }

        return $result;
    }

    private function getLimpVixTablesStatus(): array
    {
        global $wpdb;

        $expectedTables = [
            'limpvix_executions' => [
                'access' => 'READ',
                'purpose' => 'Mapear appointment → order',
            ],
            'limpvix_professionals' => [
                'access' => 'READ',
                'purpose' => 'Vincular user_id WordPress',
            ],
            'limpvix_briefings' => [
                'access' => 'READ',
                'purpose' => 'Dados para Google Reviews',
            ],
            'limpvix_contracts' => [
                'access' => 'READ',
                'purpose' => 'Nome do serviço executado',
            ],
        ];

        $result = [];

        foreach ($expectedTables as $table => $config) {
            $fullTableName = $wpdb->prefix . $table;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $fullTableName)) === $fullTableName;

            $result[$table] = [
                'exists' => $exists,
                'access' => $config['access'],
                'purpose' => $config['purpose'],
                'full_name' => $fullTableName,
            ];
        }

        return $result;
    }
}
