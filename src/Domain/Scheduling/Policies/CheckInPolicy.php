<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Policies;

use LimpVix\Domain\Scheduling\ValueObjects\CheckIn;
use LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates;
use LimpVix\Domain\Scheduling\ValueObjects\MediaCollection;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;
use LimpVix\Domain\Scheduling\ValueObjects\SlaViolation;
use LimpVix\Domain\Scheduling\ValueObjects\TimeWindow;

/**
 * Policy: CheckInPolicy
 *
 * Regras de negócio para validação de check-in.
 */
final class CheckInPolicy
{
    private const DEFAULT_GEOFENCE_RADIUS_METERS = 150;
    private const MIN_MEDIA_REQUIRED = 1; // 1 foto OU 1 vídeo

    /**
     * Valida se check-in pode ser aceito
     *
     * Critérios:
     * - Dentro da janela de tempo
     * - Dentro do geofence (150m)
     * - Mídia válida (pelo menos 1 item)
     *
     * @return array{valid: bool, violations: SlaViolation[]}
     */
    public static function validate(
        TimeWindow $window,
        ServiceLocation $expectedLocation,
        GeoCoordinates $actualCoordinates,
        MediaCollection $media,
        \DateTimeImmutable $checkInTime,
        int $geofenceRadiusMeters = self::DEFAULT_GEOFENCE_RADIUS_METERS
    ): array {
        $violations = [];

        // 1. Validar janela de tempo
        $withinWindow = $window->isWithinWindow($checkInTime);

        if (!$withinWindow) {
            $delayMinutes = $window->calculateDelayInMinutes($checkInTime);
            $violations[] = SlaViolation::timeWindowViolation($delayMinutes, $checkInTime);
        }

        // 2. Validar geofence
        $withinGeofence = self::validateGeofence(
            $expectedLocation,
            $actualCoordinates,
            $geofenceRadiusMeters
        );

        if (!$withinGeofence) {
            $distanceMeters = $expectedLocation->getCoordinates()->distanceToInMeters($actualCoordinates);
            $violations[] = SlaViolation::geofenceViolation($distanceMeters, $checkInTime);
        }

        // 3. Validar mídia
        if (!self::validateMedia($media)) {
            $violations[] = SlaViolation::missingMedia($checkInTime);
        }

        $isValid = empty($violations);

        return [
            'valid' => $isValid,
            'within_window' => $withinWindow,
            'within_geofence' => $withinGeofence,
            'violations' => $violations,
        ];
    }

    /**
     * Valida geofence (raio de X metros)
     */
    public static function validateGeofence(
        ServiceLocation $expectedLocation,
        GeoCoordinates $actualCoordinates,
        int $radiusMeters
    ): bool {
        return $actualCoordinates->isWithinRadius(
            $expectedLocation->getCoordinates(),
            $radiusMeters
        );
    }

    /**
     * Valida mídia do check-in
     * Requer pelo menos 1 foto OU 1 vídeo
     */
    public static function validateMedia(MediaCollection $media): bool
    {
        return $media->hasAtLeast(self::MIN_MEDIA_REQUIRED);
    }

    /**
     * Determina status de SLA baseado no check-in
     *
     * @return ?SlaViolation Null se não houve violação
     */
    public static function determineSlaStatus(
        TimeWindow $window,
        \DateTimeImmutable $checkInTime
    ): ?SlaViolation {
        if (!$window->hasViolation($checkInTime)) {
            return null;
        }

        $delayMinutes = $window->calculateDelayInMinutes($checkInTime);

        return SlaViolation::timeWindowViolation($delayMinutes, $checkInTime);
    }

    /**
     * Verifica se check-in pode ser aceito mesmo com violações
     * (ex: admin override)
     */
    public static function canAcceptWithOverride(CheckIn $checkIn): bool
    {
        // Check-in sempre pode ser aceito se tiver mídia válida
        // Violações são apenas tracked, não bloqueiam
        return $checkIn->getMedia()->count() > 0;
    }

    /**
     * Calcula gravidade de violação
     *
     * @return string 'low'|'medium'|'high'|'critical'
     */
    public static function calculateViolationSeverity(array $violations): string
    {
        if (empty($violations)) {
            return 'none';
        }

        $maxSeverity = 'low';

        foreach ($violations as $violation) {
            if ($violation->isCritical()) {
                return 'critical';
            }

            if ($violation->getSeverity() === 'high' && $maxSeverity !== 'critical') {
                $maxSeverity = 'high';
            } elseif ($violation->getSeverity() === 'medium' && $maxSeverity === 'low') {
                $maxSeverity = 'medium';
            }
        }

        return $maxSeverity;
    }
}
