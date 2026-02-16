<?php
declare(strict_types=1);

namespace LimpVix\Domain\Execution\ValueObjects;

/**
 * SlaViolation - Registro de Violação de SLA (Sprint 1 - Dia 3)
 *
 * Value Object imutável representando violação de SLA detectada.
 * Registra motivo, timestamp e dados relevantes.
 *
 * PRINCÍPIOS:
 * - Imutável (readonly)
 * - Rastreável (timestamp + reason)
 * - Sem side effects
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 */
final readonly class SlaViolation
{
    public const REASON_LATE_CHECKIN = 'late_checkin';
    public const REASON_OUT_OF_GEOFENCE = 'out_of_geofence';
    public const REASON_MISSING_EVIDENCE = 'missing_evidence';
    public const REASON_EARLY_CHECKIN = 'early_checkin';

    /**
     * Construtor
     *
     * @param string $reason Motivo da violação
     * @param \DateTimeImmutable $detectedAt Timestamp da detecção
     * @param array<string, mixed> $metadata Metadados adicionais
     */
    public function __construct(
        public string $reason,
        public \DateTimeImmutable $detectedAt,
        public array $metadata = []
    ) {
        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('SLA violation reason cannot be empty');
        }
    }

    /**
     * Factory: Late check-in
     *
     * @param int $delayMinutes Atraso em minutos
     * @return self
     */
    public static function lateCheckIn(int $delayMinutes): self
    {
        return new self(
            self::REASON_LATE_CHECKIN,
            new \DateTimeImmutable(),
            ['delay_minutes' => $delayMinutes]
        );
    }

    /**
     * Factory: Out of geofence
     *
     * @param float $distanceMeters Distância do local esperado
     * @return self
     */
    public static function outOfGeofence(float $distanceMeters): self
    {
        return new self(
            self::REASON_OUT_OF_GEOFENCE,
            new \DateTimeImmutable(),
            ['distance_meters' => $distanceMeters]
        );
    }

    /**
     * Factory: Early check-in
     *
     * @param int $earlyMinutes Minutos de antecedência
     * @return self
     */
    public static function earlyCheckIn(int $earlyMinutes): self
    {
        return new self(
            self::REASON_EARLY_CHECKIN,
            new \DateTimeImmutable(),
            ['early_minutes' => abs($earlyMinutes)]
        );
    }

    /**
     * Verifica se é late check-in
     *
     * @return bool
     */
    public function isLateCheckIn(): bool
    {
        return $this->reason === self::REASON_LATE_CHECKIN;
    }

    /**
     * Verifica se é out of geofence
     *
     * @return bool
     */
    public function isOutOfGeofence(): bool
    {
        return $this->reason === self::REASON_OUT_OF_GEOFENCE;
    }

    /**
     * Representação string
     *
     * @return string
     */
    public function toString(): string
    {
        $meta = !empty($this->metadata) 
            ? json_encode($this->metadata) 
            : 'no metadata';
        
        return sprintf(
            'SLA Violation: %s (detected at %s) - %s',
            $this->reason,
            $this->detectedAt->format('Y-m-d H:i:s'),
            $meta
        );
    }
}
