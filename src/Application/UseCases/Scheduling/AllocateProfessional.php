<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Scheduling;

use LimpVix\Domain\Scheduling\Schedule;
use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceComplexity;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;
use LimpVix\Domain\Scheduling\Repositories\ScheduleRepositoryInterface;
use LimpVix\Domain\Scheduling\Repositories\ProfessionalRepositoryInterface;
use LimpVix\Domain\Scheduling\Events\ProfessionalAllocated;
use LimpVix\Domain\Scheduling\Events\AllocationFailed;
use LimpVix\Application\Services\Scheduling\AllocationEngine;

/**
 * Use Case: AllocateProfessional
 *
 * Aloca profissionais a um Schedule usando algoritmo inteligente de score.
 * Este é o Use Case mais crítico do módulo Scheduling.
 *
 * Algoritmo:
 * 1. Buscar profissionais elegíveis (região + skills + disponibilidade)
 * 2. Calcular score de cada profissional (0-100)
 * 3. Selecionar top N profissionais
 * 4. Validar janela coincidente se múltiplos
 * 5. Bloquear agenda de todos
 * 6. Registrar alocação
 */
final class AllocateProfessional
{
    private ScheduleRepositoryInterface $scheduleRepository;
    private ProfessionalRepositoryInterface $professionalRepository;
    private AllocationEngine $allocationEngine;

    public function __construct(
        ScheduleRepositoryInterface $scheduleRepository,
        ProfessionalRepositoryInterface $professionalRepository,
        AllocationEngine $allocationEngine
    ) {
        $this->scheduleRepository = $scheduleRepository;
        $this->professionalRepository = $professionalRepository;
        $this->allocationEngine = $allocationEngine;
    }

    /**
     * Executa alocação de profissionais
     *
     * @param string $scheduleUuid
     * @param ServiceComplexity $complexity
     * @return array{success: bool, allocated_professionals: array, events: array}
     */
    public function execute(string $scheduleUuid, ServiceComplexity $complexity): array
    {
        // 1. Buscar Schedule
        $schedule = $this->scheduleRepository->findByUuid($scheduleUuid);

        if ($schedule === null) {
            return $this->failureResponse($scheduleUuid, 'Schedule not found');
        }

        // 2. Validar que Schedule está em draft
        if (!$schedule->isDraft()) {
            return $this->failureResponse(
                $scheduleUuid,
                sprintf('Schedule is not in draft status: %s', $schedule->getStatus())
            );
        }

        // 3. Buscar profissionais elegíveis usando AllocationEngine
        $bestProfessionals = $this->allocationEngine->findBestProfessionals(
            $schedule->getLocation(),
            $complexity,
            $schedule->getValidWindow(),
            $schedule->getRequiredProfessionals()
        );

        // 4. Verificar se encontrou profissionais suficientes
        if (count($bestProfessionals) < $schedule->getRequiredProfessionals()) {
            return $this->failureResponse(
                $scheduleUuid,
                sprintf(
                    'Insufficient professionals: found %d, required %d',
                    count($bestProfessionals),
                    $schedule->getRequiredProfessionals()
                ),
                ['available_count' => count($bestProfessionals)]
            );
        }

        // 5. Alocar cada profissional ao Schedule
        $allocatedProfessionals = [];
        $events = [];

        foreach ($bestProfessionals as $professionalData) {
            /** @var Professional $professional */
            $professional = $professionalData['professional'];
            $score = $professionalData['score'];

            // Criar TimeSlot para alocação
            $slot = TimeSlot::fromStartAndDuration(
                $schedule->getRequestedTime(),
                $schedule->getEstimatedDurationMinutes()
            );

            // Alocar no Schedule
            $schedule->allocateProfessional($professional->getStaffId(), $slot);

            // Registrar alocação
            $allocatedProfessionals[] = [
                'professional_id' => $professional->getStaffId(),
                'name' => $professional->getName(),
                'score' => $score,
                'slot' => $slot->toArray(),
            ];

            // Criar evento
            $events[] = ProfessionalAllocated::create(
                $scheduleUuid,
                $professional->getStaffId(),
                $slot->getStart(),
                $slot->getEnd(),
                $score
            );
        }

        // 6. Salvar Schedule atualizado
        $this->scheduleRepository->save($schedule);

        // 7. Retornar sucesso
        return [
            'success' => true,
            'schedule_uuid' => $scheduleUuid,
            'status' => $schedule->getStatus(),
            'allocated_professionals' => $allocatedProfessionals,
            'events' => $events,
        ];
    }

    private function failureResponse(
        string $scheduleUuid,
        string $reason,
        array $context = []
    ): array {
        return [
            'success' => false,
            'schedule_uuid' => $scheduleUuid,
            'reason' => $reason,
            'context' => $context,
            'events' => [
                AllocationFailed::create($scheduleUuid, $reason, $context),
            ],
        ];
    }
}
