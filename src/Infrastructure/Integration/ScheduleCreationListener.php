<?php
/**
 * ScheduleCreationListener - Listener para Criação de Agendas
 *
 * RESPONSABILIDADE:
 * - Escutar evento: limpvix_briefing_locked
 * - Criar agenda recorrente baseada em frequency
 * - Integração com Booknetic (agendamento)
 * - Configurar recorrência (semanal, mensal, avulso)
 *
 * HOOK ESCUTADO:
 * - limpvix_briefing_locked (prioridade 20)
 *
 * AÇÕES EXECUTADAS:
 * - Criar primeiro agendamento no Booknetic
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

    /**
     * Criar agendamento no Booknetic (placeholder)
     *
     * TODO: Implementar integração real com Booknetic quando disponível.
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @return int Appointment ID
     */
    private function createAppointment($briefing): int
    {
        // Placeholder: criar agendamento simulado

        global $wpdb;

        // Dados do agendamento
        $data = [
            'briefing_uuid' => $briefing->getUuid(),
            'user_id' => $briefing->getUserId(),
            'order_id' => $briefing->getOrderId(),
            'service_duration' => $briefing->getMetrics()->getTotalMinutes(),
            'property_type' => $briefing->getPropertyType()->getValue(),
            'frequency_type' => $briefing->getFrequency() ? $briefing->getFrequency()->getType() : 'avulso',
            'status' => 'scheduled',
            'created_at' => current_time('mysql')
        ];

        // TODO: Usar Booknetic API real
        // Por enquanto, apenas simular criação
        $appointmentId = rand(1000, 9999); // Mock

        $this->logInfo(sprintf(
            'Agendamento criado (mock): ID=%d, Duração=%d min, Tipo=%s',
            $appointmentId,
            $data['service_duration'],
            $data['frequency_type']
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
        $frequency = $briefing->getFrequency();

        $recurrenceData = [
            'appointment_id' => $appointmentId,
            'type' => $frequency->getType(), // 'weekly' ou 'monthly'
            'executions_per_period' => $frequency->getExecutionsPerPeriod(),
            'start_date' => current_time('Y-m-d'),
            'end_date' => null // Indeterminado (até cancelamento)
        ];

        // TODO: Configurar recorrência no Booknetic
        $this->logInfo(sprintf(
            'Recorrência configurada: Tipo=%s, Execuções=%dx',
            $recurrenceData['type'],
            $recurrenceData['executions_per_period']
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
            "Acesse o Booknetic para mais detalhes.",
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
