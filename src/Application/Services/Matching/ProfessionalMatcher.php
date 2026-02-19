<?php
declare(strict_types=1);

namespace LimpVix\Application\Services\Matching;

defined('ABSPATH') || exit;

/**
 * ProfessionalMatcher - Unified scoring engine (G-MATCHING-DUAL)
 *
 * Single Source of Truth for professional matching/scoring.
 * Used by both SendOffers (async broadcast) and AllocationEngine (sync allocation).
 *
 * Scoring (0-100 points):
 * - Proximity:     40 points (closer = higher)
 * - Availability:  30 points (professional score + acceptance rate)
 * - Rating:        20 points (average rating + experience)
 * - Load:          10 points (less daily load = higher)
 *
 * @package LimpVix\Application\Services\Matching
 */
final class ProfessionalMatcher
{
    private float $proximityWeight;
    private float $availabilityWeight;
    private float $ratingWeight;
    private float $loadWeight;
    private float $maxDistanceKm;

    public function __construct(
        float $proximityWeight = 40.0,
        float $availabilityWeight = 30.0,
        float $ratingWeight = 20.0,
        float $loadWeight = 10.0,
        float $maxDistanceKm = 20.0
    ) {
        $this->proximityWeight = $proximityWeight;
        $this->availabilityWeight = $availabilityWeight;
        $this->ratingWeight = $ratingWeight;
        $this->loadWeight = $loadWeight;
        $this->maxDistanceKm = $maxDistanceKm;
    }

    /**
     * Calculate match score for a professional
     *
     * @param float $distanceKm Distance in kilometers
     * @param float $professionalScore Professional's score (0-100)
     * @param float $acceptanceRate Acceptance rate (0.0-1.0)
     * @param float $averageRating Average rating (0-5)
     * @param int   $completedServices Number of completed services
     * @param int   $currentDailyLoadMinutes Current daily load in minutes
     * @param int   $maxDailyMinutes Maximum daily capacity in minutes (default 480 = 8h)
     * @return MatchScore
     */
    public function calculateScore(
        float $distanceKm,
        float $professionalScore,
        float $acceptanceRate,
        float $averageRating,
        int $completedServices,
        int $currentDailyLoadMinutes = 0,
        int $maxDailyMinutes = 480
    ): MatchScore {
        // 1. Proximity (0 - proximityWeight points)
        $proximityScore = $distanceKm >= $this->maxDistanceKm
            ? 0.0
            : $this->proximityWeight * (1.0 - ($distanceKm / $this->maxDistanceKm));

        // 2. Availability (0 - availabilityWeight points)
        // Split: 60% professional score + 40% acceptance rate
        $profScoreNorm = min(1.0, $professionalScore / 100.0);
        $availabilityScore = $this->availabilityWeight * (
            ($profScoreNorm * 0.6) + ($acceptanceRate * 0.4)
        );

        // 3. Rating + Experience (0 - ratingWeight points)
        // Split: 50% rating + 50% experience
        $ratingNorm = min(1.0, $averageRating / 5.0);
        $experienceNorm = min(1.0, $completedServices / 50.0); // 50 services = max
        $ratingScore = $this->ratingWeight * (
            ($ratingNorm * 0.5) + ($experienceNorm * 0.5)
        );

        // 4. Load (0 - loadWeight points)
        // Less load = higher score
        $loadScore = 0.0;
        if ($maxDailyMinutes > 0 && $currentDailyLoadMinutes < $maxDailyMinutes) {
            $freeCapacity = ($maxDailyMinutes - $currentDailyLoadMinutes) / $maxDailyMinutes;
            $loadScore = $this->loadWeight * $freeCapacity;
        }

        $totalScore = $proximityScore + $availabilityScore + $ratingScore + $loadScore;

        return new MatchScore(
            total: round($totalScore, 2),
            proximity: round($proximityScore, 2),
            availability: round($availabilityScore, 2),
            rating: round($ratingScore, 2),
            load: round($loadScore, 2),
            distanceKm: round($distanceKm, 2)
        );
    }

    /**
     * Factory: create from admin-configured weights (limpvix_scheduling_config)
     */
    public static function fromConfig(): self
    {
        $config = get_option('limpvix_scheduling_config', []);
        $defaults = [
            'score_proximity_weight' => 40,
            'score_availability_weight' => 30,
            'score_rating_weight' => 20,
            'score_load_weight' => 10,
        ];
        $config = array_merge($defaults, $config);

        return new self(
            proximityWeight: (float) $config['score_proximity_weight'],
            availabilityWeight: (float) $config['score_availability_weight'],
            ratingWeight: (float) $config['score_rating_weight'],
            loadWeight: (float) $config['score_load_weight'],
            maxDistanceKm: (float) get_option('limpvix_prof_max_service_radius', 20)
        );
    }

    /**
     * Haversine distance calculation between two coordinates
     */
    public static function haversineDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $earthRadius = 6371.0; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
