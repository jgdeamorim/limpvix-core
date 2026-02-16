<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: TimeSlot
 *
 * Representa um slot de tempo com início e fim.
 *
 * IMUTÁVEL.
 */
final class TimeSlot
{
    private \DateTimeImmutable $start;
    private \DateTimeImmutable $end;

    private function __construct(\DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        if ($end <= $start) {
            throw new \InvalidArgumentException('End time must be after start time');
        }

        $this->start = $start;
        $this->end = $end;
    }

    public static function create(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        return new self($start, $end);
    }

    /**
     * Factory: Criar slot a partir de início e duração em minutos
     */
    public static function fromStartAndDuration(\DateTimeImmutable $start, int $durationMinutes): self
    {
        if ($durationMinutes <= 0) {
            throw new \InvalidArgumentException('Duration must be positive');
        }

        $end = $start->modify("+{$durationMinutes} minutes");

        return new self($start, $end);
    }

    /**
     * Factory: Criar a partir de strings ISO 8601
     */
    public static function fromStrings(string $startIso, string $endIso): self
    {
        $start = new \DateTimeImmutable($startIso);
        $end = new \DateTimeImmutable($endIso);

        return new self($start, $end);
    }

    /**
     * Retorna duração em minutos
     */
    public function getDurationInMinutes(): int
    {
        $diff = $this->end->diff($this->start);
        return ($diff->h * 60) + $diff->i;
    }

    /**
     * Verifica se este slot sobrepõe outro
     */
    public function overlaps(TimeSlot $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }

    /**
     * Verifica se um timestamp está dentro deste slot
     */
    public function contains(\DateTimeImmutable $timestamp): bool
    {
        return $timestamp >= $this->start && $timestamp <= $this->end;
    }

    /**
     * Verifica se este slot está completamente dentro de outro
     */
    public function isWithin(TimeSlot $other): bool
    {
        return $this->start >= $other->start && $this->end <= $other->end;
    }

    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function getEnd(): \DateTimeImmutable
    {
        return $this->end;
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start->format('Y-m-d H:i:s'),
            'end' => $this->end->format('Y-m-d H:i:s'),
            'duration_minutes' => $this->getDurationInMinutes(),
        ];
    }

    public function equals(TimeSlot $other): bool
    {
        return $this->start == $other->start && $this->end == $other->end;
    }
}
