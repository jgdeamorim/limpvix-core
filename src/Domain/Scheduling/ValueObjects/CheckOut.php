<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: CheckOut
 *
 * Representa um check-out de profissional após conclusão do serviço.
 * Inclui timestamp, duração real, mídia do resultado.
 *
 * IMUTÁVEL.
 */
final class CheckOut
{
    private string $uuid;
    private int $professionalId;
    private string $checkInUuid;
    private \DateTimeImmutable $timestamp;
    private int $actualDurationMinutes;
    private MediaCollection $media;

    private function __construct(
        string $uuid,
        int $professionalId,
        string $checkInUuid,
        \DateTimeImmutable $timestamp,
        int $actualDurationMinutes,
        MediaCollection $media
    ) {
        if ($professionalId <= 0) {
            throw new \InvalidArgumentException('Professional ID must be positive');
        }

        if (trim($checkInUuid) === '') {
            throw new \InvalidArgumentException('Check-in UUID cannot be empty');
        }

        if ($actualDurationMinutes <= 0) {
            throw new \InvalidArgumentException('Actual duration must be positive');
        }

        $this->uuid = $uuid;
        $this->professionalId = $professionalId;
        $this->checkInUuid = $checkInUuid;
        $this->timestamp = $timestamp;
        $this->actualDurationMinutes = $actualDurationMinutes;
        $this->media = $media;
    }

    /**
     * Factory: Criar check-out
     */
    public static function create(
        string $uuid,
        int $professionalId,
        string $checkInUuid,
        \DateTimeImmutable $timestamp,
        int $actualDurationMinutes,
        MediaCollection $media
    ): self {
        return new self(
            $uuid,
            $professionalId,
            $checkInUuid,
            $timestamp,
            $actualDurationMinutes,
            $media
        );
    }

    /**
     * Factory: Criar a partir de check-in e timestamp final
     */
    public static function fromCheckIn(
        string $uuid,
        CheckIn $checkIn,
        \DateTimeImmutable $checkOutTimestamp,
        MediaCollection $media
    ): self {
        $durationSeconds = $checkOutTimestamp->getTimestamp() - $checkIn->getTimestamp()->getTimestamp();
        $durationMinutes = (int) round($durationSeconds / 60);

        return new self(
            $uuid,
            $checkIn->getProfessionalId(),
            $checkIn->getUuid(),
            $checkOutTimestamp,
            $durationMinutes,
            $media
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
            $data['check_in_uuid'] ?? '',
            new \DateTimeImmutable($data['timestamp'] ?? 'now'),
            (int) ($data['actual_duration_minutes'] ?? 0),
            MediaCollection::fromArray($data['media'] ?? ['urls' => []])
        );
    }

    /**
     * Calcula variação percentual em relação à duração estimada
     *
     * Ex: estimado 120min, real 150min → +25%
     */
    public function calculateVariance(int $estimatedDurationMinutes): float
    {
        if ($estimatedDurationMinutes <= 0) {
            return 0.0;
        }

        $difference = $this->actualDurationMinutes - $estimatedDurationMinutes;
        return ($difference / $estimatedDurationMinutes) * 100;
    }

    /**
     * Verifica se excedeu a duração estimada
     */
    public function exceededEstimate(int $estimatedDurationMinutes): bool
    {
        return $this->actualDurationMinutes > $estimatedDurationMinutes;
    }

    /**
     * Verifica se está dentro da margem de tolerância (ex: ±20%)
     */
    public function isWithinTolerance(int $estimatedDurationMinutes, int $tolerancePercent = 20): bool
    {
        $variance = abs($this->calculateVariance($estimatedDurationMinutes));
        return $variance <= $tolerancePercent;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function getCheckInUuid(): string
    {
        return $this->checkInUuid;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getActualDurationMinutes(): int
    {
        return $this->actualDurationMinutes;
    }

    public function getMedia(): MediaCollection
    {
        return $this->media;
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'professional_id' => $this->professionalId,
            'check_in_uuid' => $this->checkInUuid,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'actual_duration_minutes' => $this->actualDurationMinutes,
            'media' => $this->media->toArray(),
        ];
    }
}
