<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Scheduling;

use LimpVix\Domain\Scheduling\Schedule;
use LimpVix\Domain\Scheduling\ValueObjects\TimeWindow;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceComplexity;
use LimpVix\Domain\Scheduling\Policies\AllocationPolicy;
use LimpVix\Domain\Scheduling\Repositories\ScheduleRepositoryInterface;
use LimpVix\Domain\Scheduling\Events\ScheduleCreated;

/**
 * Use Case: CreateSchedule
 *
 * Cria um novo Schedule em status draft a partir de um Order/Briefing.
 * Calcula quantidade de profissionais necessários e janela válida.
 */
final class CreateSchedule
{
    private ScheduleRepositoryInterface $scheduleRepository;

    public function __construct(ScheduleRepositoryInterface $scheduleRepository)
    {
        $this->scheduleRepository = $scheduleRepository;
    }

    /**
     * Executa criação de Schedule
     *
     * @param string $orderUuid
     * @param int $briefingId
     * @param \DateTimeImmutable $requestedTime
     * @param ServiceLocation $location
     * @param int $estimatedDurationMinutes
     * @param ServiceComplexity $complexity
     * @param int $squareMeters
     * @param int $toleranceMinutes Tolerância da janela (padrão: 60min)
     * @return array{schedule_uuid: string, valid_window: array, required_professionals: int}
     */
    public function execute(
        string $orderUuid,
        int $briefingId,
        \DateTimeImmutable $requestedTime,
        ServiceLocation $location,
        int $estimatedDurationMinutes,
        ServiceComplexity $complexity,
        int $squareMeters,
        int $toleranceMinutes = 60
    ): array {
        // 1. Validações básicas
        $this->validateInputs($orderUuid, $briefingId, $estimatedDurationMinutes, $squareMeters);

        // 2. Gerar UUID para o Schedule
        $scheduleUuid = $this->generateUuid();

        // 3. Criar TimeWindow (requested ±tolerance)
        $validWindow = TimeWindow::fromRequestedTime($requestedTime, $toleranceMinutes);

        // 4. Calcular quantidade de profissionais necessários
        $requiredProfessionals = AllocationPolicy::calculateRequiredProfessionals(
            $complexity,
            $estimatedDurationMinutes,
            $squareMeters
        );

        // 5. Criar Schedule em status draft
        $schedule = Schedule::create(
            $scheduleUuid,
            $orderUuid,
            $briefingId,
            $requestedTime,
            $validWindow,
            $estimatedDurationMinutes,
            $location,
            $requiredProfessionals
        );

        // 6. Persistir Schedule
        $this->scheduleRepository->save($schedule);

        // 7. Retornar dados do Schedule criado
        return [
            'schedule_uuid' => $scheduleUuid,
            'valid_window' => $validWindow->toArray(),
            'required_professionals' => $requiredProfessionals,
            'status' => 'draft',
            'event' => ScheduleCreated::create(
                $scheduleUuid,
                $orderUuid,
                $briefingId,
                $requestedTime,
                $requiredProfessionals
            ),
        ];
    }

    private function validateInputs(
        string $orderUuid,
        int $briefingId,
        int $estimatedDurationMinutes,
        int $squareMeters
    ): void {
        if (trim($orderUuid) === '') {
            throw new \InvalidArgumentException('Order UUID cannot be empty');
        }

        if ($briefingId <= 0) {
            throw new \InvalidArgumentException('Briefing ID must be positive');
        }

        if ($estimatedDurationMinutes <= 0) {
            throw new \InvalidArgumentException('Estimated duration must be positive');
        }

        if ($squareMeters <= 0) {
            throw new \InvalidArgumentException('Square meters must be positive');
        }
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
