<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: CheckIn
 *
 * Representa um check-in de profissional no local do serviço.
 * Inclui timestamp, coordenadas geográficas, mídia e status de validação.
 *
 * IMUTÁVEL.
 */
final class CheckIn
{
    private string $uuid;
    private int $professionalId;
    private \DateTimeImmutable $timestamp;
    private GeoCoordinates $coordinates;
    private MediaCollection $media;
    private bool $withinWindow;
    private bool $withinGeofence;
    private ?int $delayMinutes;
    private ?SlaViolation $violation;

    private function __construct(
        string $uuid,
        int $professionalId,
        \DateTimeImmutable $timestamp,
        GeoCoordinates $coordinates,
        MediaCollection $media,
        bool $withinWindow,
        bool $withinGeofence,
        ?int $delayMinutes,
        ?SlaViolation $violation
    ) {
        if ($professionalId <= 0) {
            throw new \InvalidArgumentException('Professional ID must be positive');
        }

        $this->uuid = $uuid;
        $this->professionalId = $professionalId;
        $this->timestamp = $timestamp;
        $this->coordinates = $coordinates;
        $this->media = $media;
        $this->withinWindow = $withinWindow;
        $this->withinGeofence = $withinGeofence;
        $this->delayMinutes = $delayMinutes;
        $this->violation = $violation;
    }

    /**
     * Factory: Criar check-in validado
     */
    public static function create(
        string $uuid,
        int $professionalId,
        \DateTimeImmutable $timestamp,
        GeoCoordinates $coordinates,
        MediaCollection $media,
        bool $withinWindow,
        bool $withinGeofence,
        ?int $delayMinutes = null,
        ?SlaViolation $violation = null
    ): self {
        return new self(
            $uuid,
            $professionalId,
            $timestamp,
            $coordinates,
            $media,
            $withinWindow,
            $withinGeofence,
            $delayMinutes,
            $violation
        );
    }

    /**
     * Factory: A partir de array (hidratação)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['uuid'] ?? '',
            (int) ($data['professional_id'] ?? 0),
            new \DateTimeImmutable($data['timestamp'] ?? 'now'),
            GeoCoordinates::fromArray($data['coordinates'] ?? []),
            MediaCollection::fromArray($data['media'] ?? ['urls' => []]),
            (bool) ($data['within_window'] ?? false),
            (bool) ($data['within_geofence'] ?? false),
            $data['delay_minutes'] ?? null,
            isset($data['violation']) ? SlaViolation::fromArray($data['violation']) : null
        );
    }

    /**
     * Verifica se o check-in é válido (dentro de janela E geofence)
     */
    public function isValid(): bool
    {
        return $this->withinWindow && $this->withinGeofence;
    }

    /**
     * Verifica se houve violação de SLA
     */
    public function hasViolation(): bool
    {
        return $this->violation !== null;
    }

    /**
     * Verifica se foi atrasado
     */
    public function isLate(): bool
    {
        return $this->delayMinutes !== null && $this->delayMinutes > 0;
    }

    /**
     * Verifica se foi adiantado
     */
    public function isEarly(): bool
    {
        return $this->delayMinutes !== null && $this->delayMinutes < 0;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getCoordinates(): GeoCoordinates
    {
        return $this->coordinates;
    }

    public function getMedia(): MediaCollection
    {
        return $this->media;
    }

    public function isWithinWindow(): bool
    {
        return $this->withinWindow;
    }

    public function isWithinGeofence(): bool
    {
        return $this->withinGeofence;
    }

    public function getDelayMinutes(): ?int
    {
        return $this->delayMinutes;
    }

    public function getViolation(): ?SlaViolation
    {
        return $this->violation;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'professional_id' => $this->professionalId,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'coordinates' => $this->coordinates->toArray(),
            'media' => $this->media->toArray(),
            'within_window' => $this->withinWindow,
            'within_geofence' => $this->withinGeofence,
            'delay_minutes' => $this->delayMinutes,
            'violation' => $this->violation?->toArray(),
            'is_valid' => $this->isValid(),
        ];
    }
}
