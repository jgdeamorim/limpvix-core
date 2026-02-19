<?php
/**
 * ScheduleCreationListener - Listener para Criação de Agendas
 *
 * RESPONSABILIDADE:
 * - Escutar evento: limpvix_briefing_locked
 * - Criar agenda recorrente baseada em frequency
 * - Integração com LimpVix Scheduling
 * - Configurar recorrência (semanal, mensal, avulso)
 *
 * HOOK ESCUTADO:
 * - limpvix_briefing_locked (prioridade 20)
 *
 * AÇÕES EXECUTADAS:
 * - Criar primeiro agendamento via LimpVix
 * - Configurar recorrência (se frequency != avulso)
 * - Notificar equipe operacional
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Domain\Briefing\BriefingRepositoryInterface;

defined('ABSPATH') || exit;

class ScheduleCreationListener
{
    /**
     * @var BriefingRepositoryInterface
     */
    private $briefingRepository;

    /**
     * Construtor
     *
     * @param BriefingRepositoryInterface $briefingRepository
     */
    public function __construct(BriefingRepositoryInterface $briefingRepository)
    {
        $this->briefingRepository = $briefingRepository;
    }

    /**
     * Registrar listener
     *
     * @return void
     */
    public function register(): void
    {
        add_action('limpvix_briefing_locked', [$this, 'onBriefingLocked'], 20, 1);
    }

    /**
     * Handler: limpvix_briefing_locked
     *
     * @param array $eventData ['briefing_uuid', 'order_id']
     * @return void
     */
    public function onBriefingLocked(array $eventData): void
    {
        try {
            // 1. Buscar Briefing
            $briefingUuid = $eventData['briefing_uuid'] ?? '';
            $briefing = $this->briefingRepository->findByUuid($briefingUuid);

            if (!$briefing) {
                $this->logError("Briefing não encontrado: {$briefingUuid}");
                return;
            }

            // 2. Verificar se tem métricas (necessário para agendar)
            if (!$briefing->getMetrics()) {
                $this->logWarning("Briefing sem métricas, não é possível agendar: {$briefingUuid}");
                return;
            }

            // 3. Criar agendamento
            $appointmentId = $this->createAppointment($briefing);

            // 4. Se recorrente, configurar recorrência
            if ($briefing->getFrequency() && !$briefing->getFrequency()->isAvulso()) {
                $this->setupRecurrence($appointmentId, $briefing);
            }

            // 5. Notificar equipe
            $this->notifyOperationalTeam($briefing, $appointmentId);

            // 6. Log
            $this->logInfo("Agenda criada: Appointment ID={$appointmentId}, Briefing UUID={$briefingUuid}");

        } catch (\Exception $e) {
            $this->logError('Erro ao criar agenda: ' . $e->getMessage());
        }
    }

    private function createAppointment($briefing): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_contract_executions';

        // Resolve contract_id from briefing order
        $contractId = 0;
        $orderId = $briefing->getOrderId();
        if ($orderId) {
            $contractId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}limpvix_contracts WHERE briefing_uuid = %s LIMIT 1",
                $briefing->getUuid()
            ));
        }

        // Calculate scheduled date (next available based on frequency)
        $scheduledDate = current_time('Y-m-d');
        $frequency = $briefing->getFrequency();
        if ($frequency && !$frequency->isAvulso()) {
            // For recurring: schedule 3 days out to allow matching
            $scheduledDate = (new \DateTimeImmutable('+3 days'))->format('Y-m-d');
        }

        // Calculate execution value from briefing metrics
        $executionValue = null;
        if ($briefing->getMetrics() && method_exists($briefing->getMetrics(), 'getTotalPrice')) {
            $executionValue = $briefing->getMetrics()->getTotalPrice();
        }

        $inserted = $wpdb->insert($table, [
            'contract_id'     => $contractId,
            'briefing_uuid'   => $briefing->getUuid(),
            'schedule_uuid'   => wp_generate_uuid4(),
            'scheduled_date'  => $scheduledDate,
            'status'          => 'scheduled',
            'execution_value' => $executionValue,
            'notes'           => sprintf(
                'Auto-created from briefing. Duration: %d min, Type: %s',
                $briefing->getMetrics()->getTotalMinutes(),
                $briefing->getPropertyType()->getValue()
            ),
        ]);

        if ($inserted === false) {
            throw new \RuntimeException(
                'Failed to create appointment: ' . $wpdb->last_error
            );
        }

        $appointmentId = (int) $wpdb->insert_id;

        // Fire execution scheduled event
        do_action('limpvix_execution_scheduled', [
            'execution_id'  => $appointmentId,
            'contract_id'   => $contractId,
            'briefing_uuid' => $briefing->getUuid(),
            'scheduled_date' => $scheduledDate,
            'trigger'       => 'briefing_locked',
        ]);

        $this->logInfo(sprintf(
            'Agendamento criado: ID=%d, Contract=%d, Duração=%d min, Data=%s',
            $appointmentId,
            $contractId,
            $briefing->getMetrics()->getTotalMinutes(),
            $scheduledDate
        ));

        return $appointmentId;
    }

    /**
     * Configurar recorrência (placeholder)
     *
     * @param int $appointmentId
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @return void
     */
    private function setupRecurrence(int $appointmentId, $briefing): void
    {
        global $wpdb;

        $frequency = $briefing->getFrequency();
        $type = $frequency->getType(); // 'weekly', 'biweekly', 'monthly'
        $executionsPerPeriod = $frequency->getExecutionsPerPeriod();
        $table = $wpdb->prefix . 'limpvix_contract_executions';

        // Resolve contract_id from the first execution
        $contractId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT contract_id FROM {$table} WHERE id = %d",
            $appointmentId
        ));

        // Calculate interval between executions
        $intervalDays = match ($type) {
            'weekly' => 7,
            'biweekly' => 14,
            'monthly' => 30,
            default => 7,
        };

        // Pre-create next 4 recurring executions
        $baseDate = new \DateTimeImmutable(current_time('Y-m-d'));
        $executionsCreated = 0;

        for ($i = 1; $i <= 4; $i++) {
            $scheduledDate = $baseDate->modify('+' . ($intervalDays * $i) . ' days');

            $wpdb->insert($table, [
                'contract_id'    => $contractId,
                'briefing_uuid'  => $briefing->getUuid(),
                'schedule_uuid'  => wp_generate_uuid4(),
                'scheduled_date' => $scheduledDate->format('Y-m-d'),
                'status'         => 'pending',
                'notes'          => sprintf('Recurring execution #%d (%s)', $i, $type),
            ]);

            if ($wpdb->insert_id) {
                $executionsCreated++;
            }
        }

        $this->logInfo(sprintf(
            'Recorrência configurada: Tipo=%s, %d execuções futuras criadas (intervalo %dd)',
            $type, $executionsCreated, $intervalDays
        ));
    }

    /**
     * Notificar equipe operacional
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @param int $appointmentId
     * @return void
     */
    private function notifyOperationalTeam($briefing, int $appointmentId): void
    {
        // Obter email da equipe operacional
        $operationalEmail = get_option('limpvix_operational_email', get_option('admin_email'));

        if (empty($operationalEmail)) {
            return;
        }

        $user = get_userdata($briefing->getUserId());
        $userName = $user ? $user->display_name : 'Cliente ID: ' . $briefing->getUserId();

        $subject = '[LimpVix] Novo Agendamento Criado';

        $message = sprintf(
            "Novo agendamento criado automaticamente:\n\n" .
            "Appointment ID: %d\n" .
            "Cliente: %s\n" .
            "Tipo: %s\n" .
            "Área: %.2f m²\n" .
            "Duração: %d minutos\n" .
            "Frequência: %s\n\n" .
            "Acesse o painel LimpVix para mais detalhes.",
            $appointmentId,
            $userName,
            $briefing->getPropertyType()->getValue(),
            $briefing->getMetrics()->getM2(),
            $briefing->getMetrics()->getTotalMinutes(),
            $briefing->getFrequency() ? $briefing->getFrequency()->getType() : 'avulso'
        );

        wp_mail($operationalEmail, $subject, $message);
    }

    /**
     * Log de informação
     *
     * @param string $message
     * @return void
     */
    private function logInfo(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix][ScheduleCreation][INFO] ' . $message);
        }
    }

    /**
     * Log de warning
     *
     * @param string $message
     * @return void
     */
    private function logWarning(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix][ScheduleCreation][WARNING] ' . $message);
        }
    }

    /**
     * Log de erro
     *
     * @param string $message
     * @return void
     */
    private function logError(string $message): void
    {
        error_log('[LimpVix][ScheduleCreation][ERROR] ' . $message);
    }
}
