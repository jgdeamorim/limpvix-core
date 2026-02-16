<?php
/**
 * EstimatedMetrics - Value Object (DTO)
 *
 * Encapsula métricas calculadas do Briefing:
 * - m² estimado
 * - Duração estimada (minutos)
 * - Buffer operacional (minutos)
 *
 * Imutável após criação.
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class EstimatedMetrics
{
    /**
     * @var float m² estimado
     */
    private $m2;

    /**
     * @var int Duração estimada em minutos
     */
    private $durationMinutes;

    /**
     * @var int Buffer operacional em minutos
     */
    private $bufferMinutes;

    /**
     * Construtor
     *
     * @param float $m2 m² estimado (>= 0)
     * @param int $durationMinutes Duração em minutos (>= 0)
     * @param int $bufferMinutes Buffer em minutos (>= 0)
     * @throws \InvalidArgumentException
     */
    public function __construct(float $m2, int $durationMinutes, int $bufferMinutes = 30)
    {
        if ($m2 < 0) {
            throw new \InvalidArgumentException("m² não pode ser negativo");
        }

        if ($durationMinutes < 0) {
            throw new \InvalidArgumentException("Duração não pode ser negativa");
        }

        if ($bufferMinutes < 0) {
            throw new \InvalidArgumentException("Buffer não pode ser negativo");
        }

        $this->m2 = $m2;
        $this->durationMinutes = $durationMinutes;
        $this->bufferMinutes = $bufferMinutes;
    }

    /**
     * Factory: A partir de array
     *
     * @param array $data ['m2' => float, 'duration_minutes' => int, 'buffer_minutes' => int]
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (float) ($data['m2'] ?? 0),
            (int) ($data['duration_minutes'] ?? 0),
            (int) ($data['buffer_minutes'] ?? 30)
        );
    }

    /**
     * Obter m²
     *
     * @return float
     */
    public function getM2(): float
    {
        return $this->m2;
    }

    /**
     * Obter duração em minutos
     *
     * @return int
     */
    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    /**
     * Obter buffer em minutos
     *
     * @return int
     */
    public function getBufferMinutes(): int
    {
        return $this->bufferMinutes;
    }

    /**
     * Obter duração total (duração + buffer)
     *
     * @return int
     */
    public function getTotalDurationMinutes(): int
    {
        return $this->durationMinutes + $this->bufferMinutes;
    }

    /**
     * Obter duração em horas (decimal)
     *
     * @return float
     */
    public function getDurationHours(): float
    {
        return round($this->durationMinutes / 60, 2);
    }

    /**
     * Obter duração total em horas (decimal)
     *
     * @return float
     */
    public function getTotalDurationHours(): float
    {
        return round($this->getTotalDurationMinutes() / 60, 2);
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'm2' => $this->m2,
            'duration_minutes' => $this->durationMinutes,
            'buffer_minutes' => $this->bufferMinutes,
            'total_duration_minutes' => $this->getTotalDurationMinutes(),
            'duration_hours' => $this->getDurationHours(),
            'total_duration_hours' => $this->getTotalDurationHours()
        ];
    }

    /**
     * Comparar com outras métricas
     *
     * @param EstimatedMetrics $other
     * @return bool
     */
    public function equals(EstimatedMetrics $other): bool
    {
        return abs($this->m2 - $other->m2) < 0.01 // float comparison
            && $this->durationMinutes === $other->durationMinutes
            && $this->bufferMinutes === $other->bufferMinutes;
    }

    /**
     * Representação em string
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            "%.1fm² | %dmin (+ %dmin buffer) = %dmin total",
            $this->m2,
            $this->durationMinutes,
            $this->bufferMinutes,
            $this->getTotalDurationMinutes()
        );
    }
}
