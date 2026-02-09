<?php
/**
 * ContractAutomation - Automação de Contratos Recorrentes via WP-Cron
 *
 * RESPONSABILIDADES:
 * - Verificar contratos ativos diariamente
 * - Calcular próximas execuções baseado em recurrence
 * - Gerar briefings automaticamente 7 dias antes
 * - Enviar notificações de renovação
 * - Marcar contratos expirados
 *
 * CRON JOBS REGISTRADOS:
 * - limpvix_contracts_daily_check (diário às 00:00)
 * - limpvix_contracts_weekly_briefing (semanal, domingo)
 *
 * @package LimpVix
 * @since 0.1.13
 */

namespace LimpVix\Infrastructure\Automation;

defined('ABSPATH') || exit;

class ContractAutomation
{
    /**
     * Registrar cron hooks
     */
    public static function init(): void
    {
        // Registrar hooks de cron
        add_action('limpvix_contracts_daily_check', [__CLASS__, 'dailyContractCheck']);
        add_action('limpvix_contracts_weekly_briefing', [__CLASS__, 'weeklyBriefingGeneration']);

        // Registrar eventos de ativação (se necessário criar schedules)
        register_activation_hook(LIMPVIX_PLUGIN_FILE, [__CLASS__, 'scheduleCronJobs']);
        register_deactivation_hook(LIMPVIX_PLUGIN_FILE, [__CLASS__, 'unscheduleCronJobs']);
    }

    /**
     * Agendar cron jobs na ativação do plugin
     */
    public static function scheduleCronJobs(): void
    {
        // Daily check às 00:00
        if (!wp_next_scheduled('limpvix_contracts_daily_check')) {
            wp_schedule_event(strtotime('tomorrow midnight'), 'daily', 'limpvix_contracts_daily_check');
        }

        // Weekly briefing geração aos domingos
        if (!wp_next_scheduled('limpvix_contracts_weekly_briefing')) {
            wp_schedule_event(strtotime('next sunday midnight'), 'weekly', 'limpvix_contracts_weekly_briefing');
        }
    }

    /**
     * Desagendar cron jobs na desativação
     */
    public static function unscheduleCronJobs(): void
    {
        $timestamp = wp_next_scheduled('limpvix_contracts_daily_check');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'limpvix_contracts_daily_check');
        }

        $timestamp = wp_next_scheduled('limpvix_contracts_weekly_briefing');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'limpvix_contracts_weekly_briefing');
        }
    }

    /**
     * CRON JOB: Verificação diária de contratos
     * Hook: limpvix_contracts_daily_check
     * Frequência: Diária
     */
    public static function dailyContractCheck(): void
    {
        global $wpdb;
        $contractsTable = $wpdb->prefix . 'limpvix_contracts';

        self::log('Iniciando verificação diária de contratos...');

        // 1. Marcar contratos expirados
        $expired = self::markExpiredContracts();
        self::log("Contratos expirados marcados: {$expired}");

        // 2. Verificar contratos próximos de expirar (30 dias)
        $expiring = self::notifyExpiringContracts();
        self::log("Notificações de expiração enviadas: {$expiring}");

        // 3. Verificar pagamentos pendentes (dia do pagamento)
        $payments = self::notifyPaymentDue();
        self::log("Notificações de pagamento enviadas: {$payments}");

        // 4. Criar execuções pendentes para próximo ciclo
        $scheduled = self::scheduleNextExecutions();
        self::log("Execuções agendadas: {$scheduled}");

        self::log('Verificação diária concluída.');
    }

    /**
     * CRON JOB: Geração semanal de briefings
     * Hook: limpvix_contracts_weekly_briefing
     * Frequência: Semanal (domingo)
     */
    public static function weeklyBriefingGeneration(): void
    {
        global $wpdb;
        $executionsTable = $wpdb->prefix . 'limpvix_contract_executions';

        self::log('Iniciando geração semanal de briefings...');

        // Buscar execuções pendentes para próximos 7 dias
        $nextWeek = date('Y-m-d', strtotime('+7 days'));
        $today = date('Y-m-d');

        $pendingExecutions = $wpdb->get_results($wpdb->prepare(
            "SELECT ce.*, c.client_user_id, c.service_code, c.service_address, c.estimated_m2
             FROM {$executionsTable} ce
             INNER JOIN {$wpdb->prefix}limpvix_contracts c ON ce.contract_id = c.id
             WHERE ce.status = 'pending'
             AND ce.scheduled_date BETWEEN %s AND %s
             AND ce.briefing_uuid IS NULL
             ORDER BY ce.scheduled_date ASC",
            $today,
            $nextWeek
        ), ARRAY_A);

        $generated = 0;
        foreach ($pendingExecutions as $execution) {
            $briefingUuid = self::generateBriefingForExecution($execution);
            if ($briefingUuid) {
                // Atualizar execução com briefing_uuid
                $wpdb->update(
                    $executionsTable,
                    ['briefing_uuid' => $briefingUuid, 'status' => 'scheduled'],
                    ['id' => $execution['id']],
                    ['%s', '%s'],
                    ['%d']
                );
                $generated++;
                self::log("Briefing gerado para execução #{$execution['id']}: {$briefingUuid}");
            }
        }

        self::log("Briefings gerados: {$generated} de " . count($pendingExecutions) . " execuções pendentes.");
    }

    // ========================================
    // MÉTODOS AUXILIARES
    // ========================================

    /**
     * Marcar contratos expirados
     * Contratos com end_date < hoje e status = active
     */
    private static function markExpiredContracts(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        $today = date('Y-m-d');

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'expired', updated_at = NOW()
             WHERE end_date < %s
             AND status = 'active'
             AND auto_renew = 0",
            $today
        ));

        return $updated ?: 0;
    }

    /**
     * Notificar contratos próximos de expirar (30 dias)
     */
    private static function notifyExpiringContracts(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        $in30Days = date('Y-m-d', strtotime('+30 days'));
        $today = date('Y-m-d');

        $expiring = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, u.user_email, u.display_name
             FROM {$table} c
             INNER JOIN {$wpdb->users} u ON c.client_user_id = u.ID
             WHERE c.end_date BETWEEN %s AND %s
             AND c.status = 'active'
             AND c.auto_renew = 0",
            $today,
            $in30Days
        ), ARRAY_A);

        $notified = 0;
        foreach ($expiring as $contract) {
            $daysRemaining = (strtotime($contract['end_date']) - time()) / 86400;
            $daysRemaining = (int) ceil($daysRemaining);

            $message = sprintf(
                "Olá %s! Seu contrato %s expira em %d dias (%s). Entre em contato para renovar.",
                $contract['display_name'],
                $contract['contract_number'],
                $daysRemaining,
                date('d/m/Y', strtotime($contract['end_date']))
            );

            // Disparar evento para comunicação
            do_action('limpvix_contract_expiring', [
                'contract_id' => $contract['id'],
                'contract_number' => $contract['contract_number'],
                'client_user_id' => $contract['client_user_id'],
                'client_email' => $contract['user_email'],
                'client_name' => $contract['display_name'],
                'end_date' => $contract['end_date'],
                'days_remaining' => $daysRemaining,
                'message' => $message
            ]);

            $notified++;
        }

        return $notified;
    }

    /**
     * Notificar pagamentos vencendo (dia do pagamento)
     */
    private static function notifyPaymentDue(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        $today = (int) date('d'); // Dia do mês (1-31)

        $dueContracts = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, u.user_email, u.display_name
             FROM {$table} c
             INNER JOIN {$wpdb->users} u ON c.client_user_id = u.ID
             WHERE c.payment_day = %d
             AND c.status = 'active'",
            $today
        ), ARRAY_A);

        $notified = 0;
        foreach ($dueContracts as $contract) {
            $message = sprintf(
                "Olá %s! Lembrete: seu contrato %s vence hoje. Valor: R$ %s.",
                $contract['display_name'],
                $contract['contract_number'],
                number_format($contract['monthly_value'], 2, ',', '.')
            );

            // Disparar evento para comunicação
            do_action('limpvix_contract_payment_due', [
                'contract_id' => $contract['id'],
                'contract_number' => $contract['contract_number'],
                'client_user_id' => $contract['client_user_id'],
                'client_email' => $contract['user_email'],
                'client_name' => $contract['display_name'],
                'monthly_value' => $contract['monthly_value'],
                'payment_day' => $contract['payment_day'],
                'message' => $message
            ]);

            $notified++;
        }

        return $notified;
    }

    /**
     * Criar execuções para próximo ciclo
     * Verifica se já existe execução agendada para o próximo ciclo
     */
    private static function scheduleNextExecutions(): int
    {
        global $wpdb;
        $contractsTable = $wpdb->prefix . 'limpvix_contracts';
        $executionsTable = $wpdb->prefix . 'limpvix_contract_executions';

        // Buscar contratos ativos
        $activeContracts = $wpdb->get_results(
            "SELECT * FROM {$contractsTable} WHERE status = 'active'",
            ARRAY_A
        );

        $scheduled = 0;
        foreach ($activeContracts as $contract) {
            $nextDate = self::calculateNextExecutionDate($contract);

            if (!$nextDate) {
                continue; // Não há próxima data (contrato expirado, por exemplo)
            }

            // Verificar se já existe execução agendada para esta data
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$executionsTable}
                 WHERE contract_id = %d AND scheduled_date = %s AND status != 'cancelled'",
                $contract['id'],
                $nextDate
            ));

            if ($exists > 0) {
                continue; // Já existe
            }

            // Criar execução
            $wpdb->insert($executionsTable, [
                'contract_id' => $contract['id'],
                'scheduled_date' => $nextDate,
                'status' => 'pending',
                'execution_value' => $contract['monthly_value'],
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

            $scheduled++;
        }

        return $scheduled;
    }

    /**
     * Calcular próxima data de execução baseado no tipo de contrato
     */
    private static function calculateNextExecutionDate(array $contract): ?string
    {
        $today = date('Y-m-d');
        $type = $contract['contract_type'];
        $recurrenceDay = (int) $contract['recurrence_day'];
        $recurrenceWeeks = (int) ($contract['recurrence_weeks'] ?: 1);

        // Se contrato tem end_date e já passou, não agendar
        if ($contract['end_date'] && $contract['end_date'] < $today) {
            return null;
        }

        switch ($type) {
            case 'monthly':
                // Próximo dia X do mês
                $currentMonth = date('Y-m');
                $nextDate = date('Y-m-d', strtotime("{$currentMonth}-{$recurrenceDay}"));

                // Se a data já passou, pegar do próximo mês
                if ($nextDate <= $today) {
                    $nextMonth = date('Y-m', strtotime('+1 month'));
                    $nextDate = date('Y-m-d', strtotime("{$nextMonth}-{$recurrenceDay}"));
                }

                return $nextDate;

            case 'weekly':
                // Próximo dia da semana (0=domingo, 6=sábado)
                $daysOfWeek = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                $dayName = $daysOfWeek[$recurrenceDay] ?? 'monday';
                $nextDate = date('Y-m-d', strtotime("next {$dayName}"));

                return $nextDate;

            case 'biweekly':
                // A cada X semanas a partir de start_date
                $startDate = strtotime($contract['start_date']);
                $weeksSinceStart = floor((time() - $startDate) / (7 * 86400));
                $cycleNumber = floor($weeksSinceStart / $recurrenceWeeks) + 1;
                $nextDate = date('Y-m-d', $startDate + ($cycleNumber * $recurrenceWeeks * 7 * 86400));

                return $nextDate;

            default:
                return null;
        }
    }

    /**
     * Gerar briefing automaticamente para uma execução
     */
    private static function generateBriefingForExecution(array $execution): ?string
    {
        global $wpdb;

        try {
            // Gerar UUID único
            $uuid = wp_generate_uuid4();

            // Dados do serviço (baseado no contrato)
            $serviceAddress = json_decode($execution['service_address'], true);

            // Calcular métricas básicas (simplificado)
            $estimatedM2 = $execution['estimated_m2'] ?: 100;
            $estimatedTime = (int) ($estimatedM2 * 1.5); // 1.5 min por m²

            // Inserir briefing
            $briefingsTable = $wpdb->prefix . 'limpvix_briefings';
            $inserted = $wpdb->insert($briefingsTable, [
                'uuid' => $uuid,
                'client_user_id' => $execution['client_user_id'],
                'status' => 'locked', // Já inicia como locked (contrato recorrente)
                'estimated_m2' => $estimatedM2,
                'estimated_time_minutes' => $estimatedTime,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);

            if (!$inserted) {
                self::log("Erro ao criar briefing: " . $wpdb->last_error);
                return null;
            }

            $briefingId = $wpdb->insert_id;

            // Inserir dados do briefing (location, service_type, etc)
            $briefingDataTable = $wpdb->prefix . 'limpvix_briefing_data';

            // Location
            $wpdb->insert($briefingDataTable, [
                'briefing_uuid' => $uuid,
                'data_key' => 'location',
                'data_value' => json_encode($serviceAddress),
                'created_at' => current_time('mysql')
            ]);

            // Service type
            $wpdb->insert($briefingDataTable, [
                'briefing_uuid' => $uuid,
                'data_key' => 'service_type',
                'data_value' => $execution['service_code'],
                'created_at' => current_time('mysql')
            ]);

            self::log("Briefing gerado: {$uuid} (ID: {$briefingId})");
            return $uuid;

        } catch (\Exception $e) {
            self::log("Erro ao gerar briefing: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Log de atividades (debug)
     */
    private static function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix Contracts Automation] ' . $message);
        }
    }
}
