<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Scheduling;

use LimpVix\Domain\Scheduling\Schedule;
use LimpVix\Domain\Scheduling\ValueObjects\CheckOut;
use LimpVix\Domain\Scheduling\ValueObjects\MediaCollection;
use LimpVix\Domain\Scheduling\Policies\CheckOutPolicy;
use LimpVix\Domain\Scheduling\Repositories\ScheduleRepositoryInterface;
use LimpVix\Domain\Scheduling\Events\CheckOutPerformed;
use LimpVix\Domain\Scheduling\Events\ServiceCompleted;

/**
 * Use Case: PerformCheckOut
 *
 * Realiza check-out de profissional com validações:
 * - Check-in foi feito
 * - Mídia válida (fotos do resultado)
 *
 * Side effects:
 * - Calcula duração real
 * - Marca Schedule como completed
 * - Libera feedback para cliente
 * - Autoriza payout
 */
final class PerformCheckOut
{
    private ScheduleRepositoryInterface $scheduleRepository;

    public function __construct(ScheduleRepositoryInterface $scheduleRepository)
    {
        $this->scheduleRepository = $scheduleRepository;
    }

    /**
     * Executa check-out
     *
     * @param string $scheduleUuid
     * @param int $professionalId
     * @param MediaCollection $media
     * @param \DateTimeImmutable|null $timestamp
     * @return array{success: bool, check_out_uuid: string|null, actual_duration_minutes: int|null, events: array}
     */
    public function execute(
        string $scheduleUuid,
        int $professionalId,
        MediaCollection $media,
        ?\DateTimeImmutable $timestamp = null
    ): array {
        $timestamp = $timestamp ?? new \DateTimeImmutable();

        // 1. Buscar Schedule
        $schedule = $this->scheduleRepository->findByUuid($scheduleUuid);

        if ($schedule === null) {
            return $this->failureResponse('Schedule not found');
        }

        // 2. Validar que Schedule está in_progress
        if (!$schedule->isInProgress()) {
            return $this->failureResponse(
                sprintf('Schedule is not in progress: %s', $schedule->getStatus())
            );
        }

        // 3. Validar que check-in foi feito
        $checkIn = $schedule->getCheckIn();

        if ($checkIn === null) {
            return $this->failureResponse('Check-in not performed');
        }

        // 4. Validar que profissional do checkout é o mesmo do check-in
        if ($checkIn->getProfessionalId() !== $professionalId) {
            return $this->failureResponse('Professional ID does not match check-in');
        }

        // 5. Validar checkout usando CheckOutPolicy
        $validation = CheckOutPolicy::validate(
            $checkIn,
            $media,
            (int) round(
                ($timestamp->getTimestamp() - $checkIn->getTimestamp()->getTimestamp()) / 60
            ),
            $schedule->getEstimatedDurationMinutes()
        );

        if (!$validation['valid']) {
            return $this->failureResponse(
                'Check-out validation failed: ' . implode(', ', $validation['errors']),
                $validation['errors']
            );
        }

        // 6. Criar CheckOut
        $checkOutUuid = $this->generateUuid();

        $checkOut = CheckOut::fromCheckIn(
            $checkOutUuid,
            $checkIn,
            $timestamp,
            $media
        );

        // 7. Registrar check-out no Schedule
        $schedule->performCheckOut($checkOut);

        // 8. Marcar Schedule como completed
        $schedule->markAsCompleted();

        // 9. Salvar Schedule
        $this->scheduleRepository->save($schedule);

        // 10. Criar eventos
        $events = [];

        $events[] = CheckOutPerformed::create(
            $scheduleUuid,
            $checkOutUuid,
            $professionalId,
            $checkOut->getActualDurationMinutes()
        );

        $events[] = ServiceCompleted::create(
            $scheduleUuid,
            $schedule->getOrderUuid(),
            $checkOut->getActualDurationMinutes(),
            $schedule->getEstimatedDurationMinutes(),
            $schedule->hasSlaViolation()
        );

        // 11. Retornar sucesso
        return [
            'success' => true,
            'check_out_uuid' => $checkOutUuid,
            'schedule_uuid' => $scheduleUuid,
            'status' => $schedule->getStatus(),
            'actual_duration_minutes' => $checkOut->getActualDurationMinutes(),
            'estimated_duration_minutes' => $schedule->getEstimatedDurationMinutes(),
            'variance_percent' => $checkOut->calculateVariance(
                $schedule->getEstimatedDurationMinutes()
            ),
            'had_sla_violation' => $schedule->hasSlaViolation(),
            'events' => $events,
        ];
    }

    private function failureResponse(string $reason, array $errors = []): array
    {
        return [
            'success' => false,
            'check_out_uuid' => null,
            'actual_duration_minutes' => null,
            'reason' => $reason,
            'errors' => $errors,
            'events' => [],
        ];
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
