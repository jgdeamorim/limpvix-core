<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Scheduling;

use LimpVix\Domain\Scheduling\Schedule;
use LimpVix\Domain\Scheduling\ValueObjects\CheckIn;
use LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates;
use LimpVix\Domain\Scheduling\ValueObjects\MediaCollection;
use LimpVix\Domain\Scheduling\Policies\CheckInPolicy;
use LimpVix\Domain\Scheduling\Repositories\ScheduleRepositoryInterface;
use LimpVix\Domain\Scheduling\Events\CheckInPerformed;
use LimpVix\Domain\Scheduling\Events\SlaViolationDetected;
use LimpVix\Application\Services\Scheduling\GeolocationValidator;

/**
 * Use Case: PerformCheckIn
 *
 * Realiza check-in de profissional com validações:
 * - Schedule está alocado
 * - Dentro da janela válida
 * - Dentro do geofence (150m)
 * - Mídia válida
 *
 * Side effects:
 * - Bloqueia cancelamento
 * - Detecta SLA violation
 * - Transiciona Schedule para in_progress
 */
final class PerformCheckIn
{
    private ScheduleRepositoryInterface $scheduleRepository;
    private GeolocationValidator $geolocationValidator;

    public function __construct(
        ScheduleRepositoryInterface $scheduleRepository,
        GeolocationValidator $geolocationValidator
    ) {
        $this->scheduleRepository = $scheduleRepository;
        $this->geolocationValidator = $geolocationValidator;
    }

    /**
     * Executa check-in
     *
     * @param string $scheduleUuid
     * @param int $professionalId
     * @param GeoCoordinates $coordinates
     * @param MediaCollection $media
     * @param \DateTimeImmutable|null $timestamp
     * @return array{success: bool, check_in_uuid: string|null, violations: array, events: array}
     */
    public function execute(
        string $scheduleUuid,
        int $professionalId,
        GeoCoordinates $coordinates,
        MediaCollection $media,
        ?\DateTimeImmutable $timestamp = null
    ): array {
        $timestamp = $timestamp ?? new \DateTimeImmutable();

        // 1. Buscar Schedule
        $schedule = $this->scheduleRepository->findByUuid($scheduleUuid);

        if ($schedule === null) {
            return $this->failureResponse('Schedule not found');
        }

        // 2. Validar que Schedule está alocado
        if (!$schedule->isAllocated()) {
            return $this->failureResponse(
                sprintf('Schedule is not allocated: %s', $schedule->getStatus())
            );
        }

        // 3. Validar que profissional está alocado ao Schedule
        $allocatedProfessionals = $schedule->getAllocatedProfessionals();
        if (!isset($allocatedProfessionals[$professionalId])) {
            return $this->failureResponse('Professional is not allocated to this schedule');
        }

        // 4. Validar check-in usando CheckInPolicy
        $validation = CheckInPolicy::validate(
            $schedule->getValidWindow(),
            $schedule->getLocation(),
            $coordinates,
            $media,
            $timestamp
        );

        // 5. Criar CheckIn
        $checkInUuid = $this->generateUuid();

        $checkIn = CheckIn::create(
            $checkInUuid,
            $professionalId,
            $timestamp,
            $coordinates,
            $media,
            $validation['within_window'],
            $validation['within_geofence'],
            $validation['within_window']
                ? null
                : $schedule->getValidWindow()->calculateDelayInMinutes($timestamp),
            !empty($validation['violations']) ? $validation['violations'][0] : null
        );

        // 6. Registrar check-in no Schedule
        $schedule->performCheckIn($checkIn);

        // 7. Salvar Schedule
        $this->scheduleRepository->save($schedule);

        // 8. Criar eventos
        $events = [];

        $events[] = CheckInPerformed::create(
            $scheduleUuid,
            $checkInUuid,
            $professionalId,
            $validation['within_window'],
            $validation['within_geofence'],
            !empty($validation['violations'])
        );

        // Se houver violações, criar eventos de SLA
        foreach ($validation['violations'] as $violation) {
            $events[] = SlaViolationDetected::create(
                $scheduleUuid,
                $violation->getType(),
                $violation->getSeverity(),
                $violation->getDetails()
            );
        }

        // 9. Retornar sucesso
        return [
            'success' => true,
            'check_in_uuid' => $checkInUuid,
            'schedule_uuid' => $scheduleUuid,
            'status' => $schedule->getStatus(),
            'within_window' => $validation['within_window'],
            'within_geofence' => $validation['within_geofence'],
            'violations' => array_map(fn($v) => $v->toArray(), $validation['violations']),
            'events' => $events,
        ];
    }

    private function failureResponse(string $reason): array
    {
        return [
            'success' => false,
            'check_in_uuid' => null,
            'reason' => $reason,
            'violations' => [],
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
