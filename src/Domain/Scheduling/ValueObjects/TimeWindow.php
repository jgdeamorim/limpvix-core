<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: TimeWindow
 *
 * Representa uma janela de tempo válida para agendamento.
 * Tipicamente: tempo solicitado ±1 hora.
 *
 * Ex: Cliente pede 09:00, janela válida é 08:00-10:00
 *
 * IMUTÁVEL.
 */
final class TimeWindow
{
    private \DateTimeImmutable $requestedTime;
    private TimeSlot $validSlot;
    private int $toleranceMinutes;

    private function __construct(
        \DateTimeImmutable $requestedTime,
        TimeSlot $validSlot,
        int $toleranceMinutes
    ) {
        if ($toleranceMinutes < 0) {
            throw new \InvalidArgumentException('Tolerance must be non-negative');
        }

        // Validar que a janela engloba o requested time
        if (!$validSlot->contains($requestedTime)) {
            throw new \InvalidArgumentException('Valid slot must contain requested time');
        }

        $this->requestedTime = $requestedTime;
        $this->validSlot = $validSlot;
        $this->toleranceMinutes = $toleranceMinutes;
    }

    /**
     * Factory: Criar janela a partir de requested time e tolerância
     *
     * Ex: requested = 09:00, tolerance = 60 → janela 08:00-10:00
     */
    public static function fromRequestedTime(
        \DateTimeImmutable $requestedTime,
        int $toleranceMinutes = 60
    ): self {
        $start = $requestedTime->modify("-{$toleranceMinutes} minutes");
        $end = $requestedTime->modify("+{$toleranceMinutes} minutes");

        $validSlot = TimeSlot::create($start, $end);

        return new self($requestedTime, $validSlot, $toleranceMinutes);
    }

    /**
     * Factory: Criar janela customizada
     */
    public static function create(
        \DateTimeImmutable $requestedTime,
        TimeSlot $validSlot,
        int $toleranceMinutes
    ): self {
        return new self($requestedTime, $validSlot, $toleranceMinutes);
    }

    /**
     * Verifica se um timestamp está dentro da janela válida
     */
    public function isWithinWindow(\DateTimeImmutable $timestamp): bool
    {
        return $this->validSlot->contains($timestamp);
    }

    /**
     * Calcula atraso/adiantamento em minutos em relação ao requested time
     * Retorna negativo se adiantado, positivo se atrasado
     */
    public function calculateDelayInMinutes(\DateTimeImmutable $actualTime): int
    {
        $diff = $actualTime->getTimestamp() - $this->requestedTime->getTimestamp();
        return (int) round($diff / 60);
    }

    /**
     * Verifica se houve violação de SLA (fora da janela)
     */
    public function hasViolation(\DateTimeImmutable $actualTime): bool
    {
        return !$this->isWithinWindow($actualTime);
    }

    public function getRequestedTime(): \DateTimeImmutable
    {
        return $this->requestedTime;
    }

    public function getValidSlot(): TimeSlot
    {
        return $this->validSlot;
    }

    public function getWindowStart(): \DateTimeImmutable
    {
        return $this->validSlot->getStart();
    }

    public function getWindowEnd(): \DateTimeImmutable
    {
        return $this->validSlot->getEnd();
    }

    public function getToleranceMinutes(): int
    {
        return $this->toleranceMinutes;
    }

    public function toArray(): array
    {
        return [
            'requested_time' => $this->requestedTime->format('Y-m-d H:i:s'),
            'window_start' => $this->validSlot->getStart()->format('Y-m-d H:i:s'),
            'window_end' => $this->validSlot->getEnd()->format('Y-m-d H:i:s'),
            'tolerance_minutes' => $this->toleranceMinutes,
        ];
    }
}
