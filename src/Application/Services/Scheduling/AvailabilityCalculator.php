<?php

declare(strict_types=1);

namespace LimpVix\Application\Services\Scheduling;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\TimeSlot;

/**
 * Application Service: AvailabilityCalculator
 *
 * Calcula score de disponibilidade para alocação de profissionais.
 * Considera disponibilidade no dia + capacidade restante (0-30 pontos).
 *
 * Componentes:
 * - Disponível no dia: 15 pontos
 * - Capacidade restante: 0-15 pontos (proporcional)
 */
final class AvailabilityCalculator
{
    private const MAX_SCORE = 30;
    private const DAY_AVAILABLE_SCORE = 15;
    private const CAPACITY_MAX_SCORE = 15;

    /**
     * Calcula score de disponibilidade (0-30 pontos)
     *
     * @param Professional $professional
     * @param \DateTimeImmutable $requestedTime
     * @param int $currentDailyLoad Carga atual em minutos
     * @return float Score de 0-30
     */
    public function calculateScore(
        Professional $professional,
        \DateTimeImmutable $requestedTime,
        int $currentDailyLoad
    ): float {
        $score = 0;

        // 1. Está disponível no dia? (+15 pontos)
        if ($professional->isAvailableOn($requestedTime)) {
            $score += self::DAY_AVAILABLE_SCORE;
        } else {
            // Se não está disponível no dia, score é 0
            return 0;
        }

        // 2. Tem capacidade restante? (0-15 pontos proporcional)
        $capacityScore = $this->calculateCapacityScore(
            $professional->getMaxDailyHours(),
            $currentDailyLoad
        );

        $score += $capacityScore;

        return $score;
    }

    /**
     * Calcula score de capacidade restante (0-15 pontos)
     *
     * @param int $maxDailyHours
     * @param int $currentDailyLoad Em minutos
     * @return float Score de 0-15
     */
    public function calculateCapacityScore(
        int $maxDailyHours,
        int $currentDailyLoad
    ): float {
        $maxDailyMinutes = $maxDailyHours * 60;

        if ($currentDailyLoad >= $maxDailyMinutes) {
            return 0; // Totalmente carregado
        }

        // Calcular capacidade livre como percentual
        $freeCapacity = ($maxDailyMinutes - $currentDailyLoad) / $maxDailyMinutes;

        // Converter para score (0-15 pontos)
        return $freeCapacity * self::CAPACITY_MAX_SCORE;
    }

    /**
     * Calcula score detalhado com breakdown
     *
     * @param Professional $professional
     * @param \DateTimeImmutable $requestedTime
     * @param int $currentDailyLoad
     * @return array
     */
    public function calculateDetailedScore(
        Professional $professional,
        \DateTimeImmutable $requestedTime,
        int $currentDailyLoad
    ): array {
        $isAvailable = $professional->isAvailableOn($requestedTime);
        $maxDailyMinutes = $professional->getMaxDailyHours() * 60;
        $freeCapacity = $maxDailyMinutes - $currentDailyLoad;
        $utilizationPercent = ($currentDailyLoad / $maxDailyMinutes) * 100;

        $dayScore = $isAvailable ? self::DAY_AVAILABLE_SCORE : 0;
        $capacityScore = $isAvailable
            ? $this->calculateCapacityScore($professional->getMaxDailyHours(), $currentDailyLoad)
            : 0;

        $totalScore = $dayScore + $capacityScore;

        return [
            'total_score' => $totalScore,
            'day_available_score' => $dayScore,
            'capacity_score' => $capacityScore,
            'is_available' => $isAvailable,
            'current_load_minutes' => $currentDailyLoad,
            'max_daily_minutes' => $maxDailyMinutes,
            'free_capacity_minutes' => max(0, $freeCapacity),
            'utilization_percent' => min(100, round($utilizationPercent, 1)),
            'max_score' => self::MAX_SCORE,
        ];
    }

    /**
     * Verifica se profissional pode aceitar mais carga
     *
     * @param Professional $professional
     * @param int $currentDailyLoad
     * @param int $additionalMinutes
     * @return bool
     */
    public function canAcceptAdditionalLoad(
        Professional $professional,
        int $currentDailyLoad,
        int $additionalMinutes
    ): bool {
        $maxDailyMinutes = $professional->getMaxDailyHours() * 60;
        $totalLoad = $currentDailyLoad + $additionalMinutes;

        return $totalLoad <= $maxDailyMinutes;
    }

    /**
     * Calcula utilização percentual do profissional
     *
     * @param int $maxDailyHours
     * @param int $currentDailyLoad
     * @return float Percentual de 0-100
     */
    public function calculateUtilization(
        int $maxDailyHours,
        int $currentDailyLoad
    ): float {
        $maxDailyMinutes = $maxDailyHours * 60;

        if ($maxDailyMinutes === 0) {
            return 0;
        }

        return min(100, ($currentDailyLoad / $maxDailyMinutes) * 100);
    }

    /**
     * Encontra horários livres em um dia
     *
     * @param Professional $professional
     * @param \DateTimeImmutable $date
     * @param TimeSlot[] $allocatedSlots Slots já alocados
     * @return TimeSlot[] Slots livres
     */
    public function findFreeSlots(
        Professional $professional,
        \DateTimeImmutable $date,
        array $allocatedSlots
    ): array {
        $availability = $professional->getAvailability();
        $dayOfWeek = strtolower($date->format('l'));

        if (!$availability->isAvailableOn($dayOfWeek)) {
            return [];
        }

        $dailySlots = $availability->getSlotsFor($dayOfWeek);
        $freeSlots = [];

        foreach ($dailySlots as $slot) {
            $isFree = true;

            // Verificar se slot não sobrepõe nenhum alocado
            foreach ($allocatedSlots as $allocatedSlot) {
                if ($slot->overlaps($allocatedSlot)) {
                    $isFree = false;
                    break;
                }
            }

            if ($isFree) {
                $freeSlots[] = $slot;
            }
        }

        return $freeSlots;
    }
}
