<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: SlaViolation
 *
 * Representa uma violação de SLA (Service Level Agreement).
 * Registra tipo, gravidade e detalhes da violação.
 *
 * IMUTÁVEL.
 */
final class SlaViolation
{
    private const TYPE_TIME_WINDOW = 'time_window_violation';
    private const TYPE_GEOFENCE = 'geofence_violation';
    private const TYPE_MISSING_MEDIA = 'missing_media';
    private const TYPE_NO_CHECKOUT = 'no_checkout';

    private const SEVERITY_LOW = 'low';
    private const SEVERITY_MEDIUM = 'medium';
    private const SEVERITY_HIGH = 'high';
    private const SEVERITY_CRITICAL = 'critical';

    private string $type;
    private string $severity;
    private \DateTimeImmutable $detectedAt;
    private array $details;

    private function __construct(
        string $type,
        string $severity,
        \DateTimeImmutable $detectedAt,
        array $details = []
    ) {
        $this->validateType($type);
        $this->validateSeverity($severity);

        $this->type = $type;
        $this->severity = $severity;
        $this->detectedAt = $detectedAt;
        $this->details = $details;
    }

    /**
     * Factory: Violação de janela de tempo (check-in fora da janela)
     */
    public static function timeWindowViolation(
        int $delayMinutes,
        \DateTimeImmutable $detectedAt
    ): self {
        $severity = match (true) {
            $delayMinutes <= 15 => self::SEVERITY_LOW,
            $delayMinutes <= 30 => self::SEVERITY_MEDIUM,
            $delayMinutes <= 60 => self::SEVERITY_HIGH,
            default => self::SEVERITY_CRITICAL,
        };

        return new self(
            self::TYPE_TIME_WINDOW,
            $severity,
            $detectedAt,
            ['delay_minutes' => $delayMinutes]
        );
    }

    /**
     * Factory: Violação de geofence (check-in fora do raio)
     */
    public static function geofenceViolation(
        float $distanceMeters,
        \DateTimeImmutable $detectedAt
    ): self {
        $severity = match (true) {
            $distanceMeters <= 200 => self::SEVERITY_LOW,
            $distanceMeters <= 500 => self::SEVERITY_MEDIUM,
            $distanceMeters <= 1000 => self::SEVERITY_HIGH,
            default => self::SEVERITY_CRITICAL,
        };

        return new self(
            self::TYPE_GEOFENCE,
            $severity,
            $detectedAt,
            ['distance_meters' => $distanceMeters]
        );
    }

    /**
     * Factory: Mídia ausente no check-in/out
     */
    public static function missingMedia(\DateTimeImmutable $detectedAt): self
    {
        return new self(
            self::TYPE_MISSING_MEDIA,
            self::SEVERITY_HIGH,
            $detectedAt
        );
    }

    /**
     * Factory: Check-out não realizado
     */
    public static function noCheckout(\DateTimeImmutable $detectedAt): self
    {
        return new self(
            self::TYPE_NO_CHECKOUT,
            self::SEVERITY_CRITICAL,
            $detectedAt
        );
    }

    /**
     * Factory: A partir de array (hidratação)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['type'] ?? self::TYPE_TIME_WINDOW,
            $data['severity'] ?? self::SEVERITY_MEDIUM,
            new \DateTimeImmutable($data['detected_at'] ?? 'now'),
            $data['details'] ?? []
        );
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    public function isTimeWindowViolation(): bool
    {
        return $this->type === self::TYPE_TIME_WINDOW;
    }

    public function isGeofenceViolation(): bool
    {
        return $this->type === self::TYPE_GEOFENCE;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'detected_at' => $this->detectedAt->format('Y-m-d H:i:s'),
            'details' => $this->details,
        ];
    }

    private function validateType(string $type): void
    {
        $validTypes = [
            self::TYPE_TIME_WINDOW,
            self::TYPE_GEOFENCE,
            self::TYPE_MISSING_MEDIA,
            self::TYPE_NO_CHECKOUT,
        ];

        if (!in_array($type, $validTypes, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid violation type: %s', $type)
            );
        }
    }

    private function validateSeverity(string $severity): void
    {
        $validSeverities = [
            self::SEVERITY_LOW,
            self::SEVERITY_MEDIUM,
            self::SEVERITY_HIGH,
            self::SEVERITY_CRITICAL,
        ];

        if (!in_array($severity, $validSeverities, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid severity: %s', $severity)
            );
        }
    }
}
