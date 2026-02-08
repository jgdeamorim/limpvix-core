<?php
declare(strict_types=1);

namespace LimpVix\Domain\Execution\ValueObjects;

/**
 * TimeWindow - Janela Temporal de Check-in (Sprint 1 - Dia 3)
 *
 * Value Object imutável representando janela válida para check-in.
 * Exemplo: agendado para 09:00, janela ±60min = 08:00-10:00
 *
 * PRINCÍPIOS:
 * - Imutável (readonly)
 * - Auto-validação
 * - Sem side effects
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 */
final readonly class TimeWindow
{
    public \DateTimeImmutable $startTime;
    public \DateTimeImmutable $endTime;

    /**
     * Construtor
     *
     * @param \DateTimeImmutable $scheduledTime Horário agendado
     * @param int $windowMinutes Janela em minutos (default: 60)
     */
    public function __construct(
        public \DateTimeImmutable $scheduledTime,
        public int $windowMinutes = 60
    ) {
        if ($windowMinutes <= 0) {
            throw new \InvalidArgumentException(
                sprintf('Window minutes must be positive: %d', $windowMinutes)
            );
        }

        $this->startTime = $scheduledTime->modify("-{$windowMinutes} minutes");
        $this->endTime = $scheduledTime->modify("+{$windowMinutes} minutes");
    }

    /**
     * Verifica se timestamp está dentro da janela
     *
     * @param \DateTimeImmutable $timestamp
     * @return bool
     */
    public function isWithin(\DateTimeImmutable $timestamp): bool
    {
        return $timestamp >= $this->startTime && $timestamp <= $this->endTime;
    }

    /**
     * Calcula delay em minutos (negativo = adiantado, positivo = atrasado)
     *
     * @param \DateTimeImmutable $actualTime
     * @return int Delay em minutos
     */
    public function calculateDelayMinutes(\DateTimeImmutable $actualTime): int
    {
        $diff = $actualTime->getTimestamp() - $this->scheduledTime->getTimestamp();
        return (int) round($diff / 60);
    }

    /**
     * Representação string
     *
     * @return string
     */
    public function toString(): string
    {
        return sprintf(
            '%s - %s (scheduled: %s, window: ±%d min)',
            $this->startTime->format('H:i'),
            $this->endTime->format('H:i'),
            $this->scheduledTime->format('H:i'),
            $this->windowMinutes
        );
    }
}
