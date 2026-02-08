<?php

declare(strict_types=1);

namespace LimpVix\Application\Services\Scheduling;

use LimpVix\Domain\Scheduling\Professional;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;

/**
 * Application Service: ProximityScorer
 *
 * Calcula score de proximidade para alocação de profissionais.
 * Quanto mais próximo, maior o score (0-40 pontos).
 *
 * Thresholds:
 * - 0-5km: 40 pontos (excelente)
 * - 5-10km: 30 pontos (bom)
 * - 10-15km: 20 pontos (aceitável)
 * - 15-20km: 10 pontos (distante)
 * - >20km: 0 pontos (muito distante)
 */
final class ProximityScorer
{
    private const MAX_SCORE = 40;

    // Thresholds em km
    private const THRESHOLD_EXCELLENT = 5;
    private const THRESHOLD_GOOD = 10;
    private const THRESHOLD_ACCEPTABLE = 15;
    private const THRESHOLD_DISTANT = 20;

    /**
     * Calcula score de proximidade (0-40 pontos)
     *
     * @param Professional $professional
     * @param ServiceLocation $location
     * @return float Score de 0-40
     */
    public function calculateScore(
        Professional $professional,
        ServiceLocation $location
    ): float {
        $distanceKm = $professional->distanceToLocationInKm($location);

        return $this->scoreByDistance($distanceKm);
    }

    /**
     * Converte distância em score
     *
     * @param float $distanceKm
     * @return float Score de 0-40
     */
    public function scoreByDistance(float $distanceKm): float
    {
        if ($distanceKm <= self::THRESHOLD_EXCELLENT) {
            return self::MAX_SCORE; // 40 pontos
        }

        if ($distanceKm <= self::THRESHOLD_GOOD) {
            return 30; // 30 pontos
        }

        if ($distanceKm <= self::THRESHOLD_ACCEPTABLE) {
            return 20; // 20 pontos
        }

        if ($distanceKm <= self::THRESHOLD_DISTANT) {
            return 10; // 10 pontos
        }

        return 0; // 0 pontos (muito distante)
    }

    /**
     * Calcula score detalhado com breakdown
     *
     * @param Professional $professional
     * @param ServiceLocation $location
     * @return array{score: float, distance_km: float, category: string}
     */
    public function calculateDetailedScore(
        Professional $professional,
        ServiceLocation $location
    ): array {
        $distanceKm = $professional->distanceToLocationInKm($location);
        $score = $this->scoreByDistance($distanceKm);
        $category = $this->categorizeDistance($distanceKm);

        return [
            'score' => $score,
            'distance_km' => round($distanceKm, 2),
            'category' => $category,
            'max_score' => self::MAX_SCORE,
        ];
    }

    /**
     * Categoriza distância em texto descritivo
     *
     * @param float $distanceKm
     * @return string
     */
    public function categorizeDistance(float $distanceKm): string
    {
        if ($distanceKm <= self::THRESHOLD_EXCELLENT) {
            return 'excellent'; // 0-5km
        }

        if ($distanceKm <= self::THRESHOLD_GOOD) {
            return 'good'; // 5-10km
        }

        if ($distanceKm <= self::THRESHOLD_ACCEPTABLE) {
            return 'acceptable'; // 10-15km
        }

        if ($distanceKm <= self::THRESHOLD_DISTANT) {
            return 'distant'; // 15-20km
        }

        return 'too_far'; // >20km
    }

    /**
     * Calcula tempo estimado de deslocamento
     *
     * Assume velocidade média de 30km/h em área urbana
     *
     * @param float $distanceKm
     * @return int Minutos de deslocamento
     */
    public function estimateTravelTime(float $distanceKm): int
    {
        $averageSpeedKmh = 30;
        $hours = $distanceKm / $averageSpeedKmh;

        return (int) round($hours * 60);
    }

    /**
     * Verifica se profissional está dentro de raio aceitável
     *
     * @param Professional $professional
     * @param ServiceLocation $location
     * @param float $maxDistanceKm Distância máxima aceitável (padrão: 20km)
     * @return bool
     */
    public function isWithinAcceptableRange(
        Professional $professional,
        ServiceLocation $location,
        float $maxDistanceKm = self::THRESHOLD_DISTANT
    ): bool {
        $distanceKm = $professional->distanceToLocationInKm($location);

        return $distanceKm <= $maxDistanceKm;
    }
}
