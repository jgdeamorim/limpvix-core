<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\Policies;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\Schedule;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceComplexity;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;

/**
 * Policy: AllocationPolicy
 *
 * Regras de negócio para alocação de profissionais.
 * Calcula quantidade necessária e score de alocação.
 */
final class AllocationPolicy
{
    // Configurações de score (weights)
    private const PROXIMITY_WEIGHT = 0.40; // 40%
    private const AVAILABILITY_WEIGHT = 0.30; // 30%
    private const RATING_WEIGHT = 0.20; // 20%
    private const LOAD_WEIGHT = 0.10; // 10%

    // Thresholds
    private const MULTIPLE_PROFESSIONALS_DURATION_THRESHOLD = 300; // 5 horas em minutos

    /**
     * Calcula quantos profissionais são necessários para um serviço
     *
     * Regras:
     * - Pós-obra: sempre 2+
     * - Duração > 5h: dividir por profissionais
     * - Área grande (>150m²) + complexo: 2+
     */
    public static function calculateRequiredProfessionals(
        ServiceComplexity $complexity,
        int $estimatedDurationMinutes,
        int $squareMeters
    ): int {
        // Pós-obra sempre múltiplos
        if ($complexity->requiresMultipleProfessionals()) {
            return max(2, (int) ceil($estimatedDurationMinutes / 300)); // 1 profissional a cada 5h
        }

        // Duração > 5h → dividir
        if ($estimatedDurationMinutes > self::MULTIPLE_PROFESSIONALS_DURATION_THRESHOLD) {
            return (int) ceil($estimatedDurationMinutes / self::MULTIPLE_PROFESSIONALS_DURATION_THRESHOLD);
        }

        // Área grande + complexo → 2 profissionais
        if ($squareMeters > 150 && !$complexity->isBasic()) {
            return 2;
        }

        return 1;
    }

    /**
     * Calcula score de alocação de um profissional para um schedule
     *
     * Score de 0-100 baseado em:
     * - Proximidade: 40%
     * - Disponibilidade: 30%
     * - Rating: 20%
     * - Carga atual: 10%
     *
     * @param Professional $professional
     * @param Schedule $schedule
     * @param int $currentDailyLoad Carga atual do profissional no dia (em minutos)
     * @return float Score de 0-100
     */
    public static function calculateAllocationScore(
        Professional $professional,
        Schedule $schedule,
        int $currentDailyLoad = 0
    ): float {
        // 1. Proximidade (0-40 pontos)
        $proximityScore = self::calculateProximityScore(
            $professional,
            $schedule->getLocation()
        );

        // 2. Disponibilidade (0-30 pontos)
        $availabilityScore = self::calculateAvailabilityScore(
            $professional,
            $schedule->getRequestedTime(),
            $schedule->getEstimatedDurationMinutes(),
            $currentDailyLoad
        );

        // 3. Rating/Experiência (0-20 pontos)
        $ratingScore = self::calculateRatingScore($professional);

        // 4. Carga (0-10 pontos) - quanto menos carga, maior o score
        $loadScore = self::calculateLoadScore(
            $currentDailyLoad,
            $professional->getMaxDailyHours()
        );

        return $proximityScore + $availabilityScore + $ratingScore + $loadScore;
    }

    /**
     * Calcula score de proximidade (0-40 pontos)
     *
     * - Dentro de 5km: 40 pontos
     * - 5-10km: 30 pontos
     * - 10-15km: 20 pontos
     * - 15-20km: 10 pontos
     * - >20km: 0 pontos
     */
    private static function calculateProximityScore(
        Professional $professional,
        ServiceLocation $location
    ): float {
        $distanceKm = $professional->distanceToLocationInKm($location);

        if ($distanceKm <= 5) {
            return 40;
        }

        if ($distanceKm <= 10) {
            return 30;
        }

        if ($distanceKm <= 15) {
            return 20;
        }

        if ($distanceKm <= 20) {
            return 10;
        }

        return 0;
    }

    /**
     * Calcula score de disponibilidade (0-30 pontos)
     *
     * Baseado em:
     * - Está disponível no dia: +15 pontos
     * - Tem capacidade para a duração: +15 pontos
     */
    private static function calculateAvailabilityScore(
        Professional $professional,
        \DateTimeImmutable $requestedTime,
        int $estimatedDurationMinutes,
        int $currentDailyLoad
    ): float {
        $score = 0;

        // Está disponível no dia?
        if ($professional->isAvailableOn($requestedTime)) {
            $score += 15;
        }

        // Tem capacidade para a duração?
        $maxDailyMinutes = $professional->getMaxDailyHours() * 60;
        $remainingCapacity = $maxDailyMinutes - $currentDailyLoad;

        if ($remainingCapacity >= $estimatedDurationMinutes) {
            $score += 15;
        } else {
            // Pontos proporcionais à capacidade restante
            $score += 15 * ($remainingCapacity / $estimatedDurationMinutes);
        }

        return $score;
    }

    /**
     * Calcula score de rating (0-20 pontos)
     *
     * Baseado em rating médio (0-5) e experiência
     */
    private static function calculateRatingScore(Professional $professional): float
    {
        // Rating médio (0-5) → 0-10 pontos
        $ratingPoints = ($professional->getAverageRating() / 5) * 10;

        // Experiência (0-50+ serviços) → 0-10 pontos
        $experiencePoints = min(10, $professional->getCompletedServices() / 5);

        return $ratingPoints + $experiencePoints;
    }

    /**
     * Calcula score de carga (0-10 pontos)
     *
     * Quanto MENOS carga, maior o score
     */
    private static function calculateLoadScore(int $currentDailyLoad, int $maxDailyHours): float
    {
        $maxDailyMinutes = $maxDailyHours * 60;

        if ($currentDailyLoad >= $maxDailyMinutes) {
            return 0; // Totalmente carregado
        }

        // Proporcional à capacidade livre
        $freeCapacity = ($maxDailyMinutes - $currentDailyLoad) / $maxDailyMinutes;

        return $freeCapacity * 10;
    }

    /**
     * Verifica se profissional é elegível para alocação
     *
     * Critérios:
     * - Ativo
     * - Região cobre localização
     * - Skills compatíveis
     * - Disponível no horário
     */
    public static function isEligible(
        Professional $professional,
        Schedule $schedule,
        ServiceComplexity $complexity
    ): bool {
        if (!$professional->isActive()) {
            return false;
        }

        if (!$professional->canServeLocation($schedule->getLocation())) {
            return false;
        }

        if (!$professional->hasSkillFor($complexity)) {
            return false;
        }

        if (!$professional->isAvailableOn($schedule->getRequestedTime())) {
            return false;
        }

        return true;
    }
}
