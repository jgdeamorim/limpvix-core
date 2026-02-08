<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Scheduling;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;
use LimpVix\Domain\Scheduling\Policies\AvailabilityPolicy;
use LimpVix\Domain\Scheduling\Repositories\ProfessionalRepositoryInterface;
use LimpVix\Domain\Scheduling\Repositories\ScheduleRepositoryInterface;

/**
 * Use Case: FindAvailableSlots
 *
 * Busca slots disponíveis de um profissional em uma data,
 * considerando disponibilidade semanal e schedules já alocados.
 */
final class FindAvailableSlots
{
    private ProfessionalRepositoryInterface $professionalRepository;
    private ScheduleRepositoryInterface $scheduleRepository;

    public function __construct(
        ProfessionalRepositoryInterface $professionalRepository,
        ScheduleRepositoryInterface $scheduleRepository
    ) {
        $this->professionalRepository = $professionalRepository;
        $this->scheduleRepository = $scheduleRepository;
    }

    /**
     * Executa busca de slots disponíveis
     *
     * @param int $professionalId
     * @param \DateTimeImmutable $date
     * @param int $durationMinutes
     * @return array{professional_id: int, date: string, available_slots: array, total_slots: int}
     */
    public function execute(
        int $professionalId,
        \DateTimeImmutable $date,
        int $durationMinutes
    ): array {
        // 1. Buscar Professional
        $professional = $this->professionalRepository->findByStaffId($professionalId);

        if ($professional === null) {
            return [
                'professional_id' => $professionalId,
                'date' => $date->format('Y-m-d'),
                'available_slots' => [],
                'total_slots' => 0,
                'error' => 'Professional not found',
            ];
        }

        // 2. Buscar schedules já alocados para este profissional nesta data
        $existingSchedules = $this->scheduleRepository->findByProfessionalAndDate(
            $professionalId,
            $date
        );

        // 3. Extrair TimeSlots dos schedules existentes
        $existingSlotsInDay = [];
        foreach ($existingSchedules as $schedule) {
            $allocatedProfessionals = $schedule->getAllocatedProfessionals();
            if (isset($allocatedProfessionals[$professionalId])) {
                $existingSlotsInDay[] = $allocatedProfessionals[$professionalId];
            }
        }

        // 4. Buscar slots disponíveis usando AvailabilityPolicy
        $availableSlots = AvailabilityPolicy::findAvailableSlots(
            $professional,
            $date,
            $durationMinutes,
            $existingSlotsInDay
        );

        // 5. Converter slots para array
        $slotsArray = array_map(
            fn(TimeSlot $slot) => $slot->toArray(),
            $availableSlots
        );

        // 6. Retornar resultado
        return [
            'professional_id' => $professionalId,
            'professional_name' => $professional->getName(),
            'date' => $date->format('Y-m-d'),
            'requested_duration_minutes' => $durationMinutes,
            'available_slots' => $slotsArray,
            'total_slots' => count($slotsArray),
            'existing_allocations' => count($existingSlotsInDay),
            'daily_load_minutes' => AvailabilityPolicy::calculateDailyLoad($existingSlotsInDay),
            'max_daily_minutes' => $professional->getMaxDailyHours() * 60,
        ];
    }

    /**
     * Busca slots para múltiplos profissionais
     *
     * @param array $professionalIds
     * @param \DateTimeImmutable $date
     * @param int $durationMinutes
     * @return array
     */
    public function executeForMultiple(
        array $professionalIds,
        \DateTimeImmutable $date,
        int $durationMinutes
    ): array {
        $results = [];

        foreach ($professionalIds as $professionalId) {
            $results[] = $this->execute($professionalId, $date, $durationMinutes);
        }

        return [
            'date' => $date->format('Y-m-d'),
            'professionals' => $results,
            'total_professionals' => count($results),
        ];
    }
}
