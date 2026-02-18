<?php
/**
 * GetReallocationOptions - Use Case (ENHANCED)
 *
 * Admin use case to fetch eligible professionals for reallocation.
 *
 * FLOW:
 * 1. Fetch contract and validate state
 * 2. Find eligible professionals (skills, region, availability)
 * 3. VALIDATE skills required (enhanced)
 * 4. VALIDATE service radius distance (enhanced)
 * 5. VALIDATE availability in contract period (enhanced)
 * 6. Calculate match score for each professional
 * 7. Sort by match score DESC
 * 8. Return top N options
 *
 * MATCH SCORE ALGORITHM (0-100):
 * - Proximity: 40 points (closer = better)
 * - Professional Score: 30 points (higher rating = better)
 * - Acceptance Rate: 20 points (more reliable = better)
 * - Experience: 10 points (more services = better)
 *
 * VALIDATIONS (COMPLETE):
 * - Contract exists and can be reallocated
 * - Professional has required skills ✅ NEW
 * - Professional within service radius ✅ NEW
 * - Professional available in contract period ✅ NEW
 * - Returns empty array if no eligible professionals
 *
 * @package LimpVix\Application\UseCases\Contract
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCases\Contract;

use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;

defined('ABSPATH') || exit;

final class GetReallocationOptions
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private ProfessionalRepositoryInterface $professionalRepository
    ) {}

    /**
     * Get list of eligible professionals for reallocation
     *
     * @param int $contractId Contract to reallocate
     * @param int $limit Maximum number of options to return
     * @return array List of professionals with match scores
     * @throws \RuntimeException if validation fails
     */
    public function execute(int $contractId, int $limit = 10): array
    {
        // 1. Fetch contract
        $contract = $this->contractRepository->findById($contractId);

        if (!$contract) {
            throw new \RuntimeException("Contract #{$contractId} not found");
        }

        // 2. Validate contract can be reallocated
        if (!$contract->canBeReallocated()) {
            throw new \RuntimeException(
                sprintf(
                    "Contract #{$contractId} cannot be reallocated (status: %s, allocated: %s)",
                    $contract->getStatus()->getValue(),
                    $contract->isProfessionalAllocated() ? 'yes' : 'no'
                )
            );
        }

        // 3. Get contract details for matching
        $serviceLatitude = $contract->getServiceLocation()->getLatitude();
        $serviceLongitude = $contract->getServiceLocation()->getLongitude();
        $requiredSkills = $contract->getRequiredSkills();
        $serviceDateTime = $contract->getNextExecutionDate() ?? $contract->getStartDate();
        $currentProfessionalId = $contract->getAllocatedProfessionalId();

        // 4. Find eligible professionals (initial filter)
        $eligibleProfessionals = $this->professionalRepository->findEligibleFor(
            $serviceLatitude,
            $serviceLongitude,
            $requiredSkills,
            $serviceDateTime,
            50, // Get 50 candidates for additional filtering
            0   // Offset 0
        );

        // 5. Filter out current professional
        $eligibleProfessionals = array_filter(
            $eligibleProfessionals,
            fn($prof) => $prof->getId() !== $currentProfessionalId
        );

        // ============================================================
        // ENHANCED VALIDATIONS (NEW)
        // ============================================================

        // 6. VALIDATION #1: Skills Required
        // Ensure professional has ALL required skills for this service
        $eligibleProfessionals = array_filter(
            $eligibleProfessionals,
            function($prof) use ($requiredSkills) {
                if (empty($requiredSkills)) {
                    return true; // No specific skills required
                }

                // Check if professional has all required skills
                return $prof->hasRequiredSkills($requiredSkills);
            }
        );

        // 7. VALIDATION #2: Service Radius Distance
        // Filter professionals within their service radius
        $eligibleProfessionals = array_filter(
            $eligibleProfessionals,
            function($prof) use ($serviceLatitude, $serviceLongitude) {
                $distance = $this->calculateDistance(
                    $serviceLatitude,
                    $serviceLongitude,
                    $prof->getServiceRegion()->getLatitude(),
                    $prof->getServiceRegion()->getLongitude()
                );

                // Check if service location is within professional's radius
                $serviceRadiusKm = $prof->getServiceRadiusKm();

                return $distance <= $serviceRadiusKm;
            }
        );

        // 8. VALIDATION #3: Availability in Contract Period
        // Ensure professional is available during contract execution times
        $eligibleProfessionals = array_filter(
            $eligibleProfessionals,
            function($prof) use ($serviceDateTime) {
                if (!$serviceDateTime) {
                    return true; // No specific date/time required yet
                }

                // Check if professional is available at contract execution time
                return $prof->isAvailableAt($serviceDateTime);
            }
        );

        // ============================================================
        // END ENHANCED VALIDATIONS
        // ============================================================

        // 9. Calculate match score for each validated professional
        $scoredProfessionals = array_map(function ($professional) use ($contract, $serviceLatitude, $serviceLongitude, $requiredSkills) {
            $matchScore = $this->calculateMatchScore(
                $professional,
                $serviceLatitude,
                $serviceLongitude
            );

            $distance = $this->calculateDistance(
                $serviceLatitude,
                $serviceLongitude,
                $professional->getServiceRegion()->getLatitude(),
                $professional->getServiceRegion()->getLongitude()
            );

            return [
                'professional_id' => $professional->getId(),
                'name' => $professional->getName(),
                'email' => $professional->getEmail(),
                'phone' => $professional->getPhone(),
                'score' => $professional->getScore(),
                'acceptance_rate' => $professional->getAcceptanceRate(),
                'completed_services' => $professional->getCompletedServices(),
                'distance_km' => $distance,
                'match_score' => $matchScore,
                'pix_key' => $professional->getPixKey(),
                'is_active' => $professional->isActive(),
                'is_verified' => $professional->isVerified(),
                'service_radius_km' => $professional->getServiceRadiusKm(),

                // Additional validation info for admin
                'has_required_skills' => $professional->hasRequiredSkills($requiredSkills),
                'within_service_radius' => $distance <= $professional->getServiceRadiusKm(),
                'is_available' => $professional->isAvailableAt($contract->getNextExecutionDate() ?? $contract->getStartDate()),
            ];
        }, $eligibleProfessionals);

        // 10. Sort by match score DESC
        usort($scoredProfessionals, function ($a, $b) {
            return $b['match_score'] <=> $a['match_score'];
        });

        // 11. Return top N
        return array_slice($scoredProfessionals, 0, $limit);
    }

    /**
     * Calculate match score for a professional (0-100)
     *
     * SCORING:
     * - Proximity: 40 points (0-40) - closer is better, -2 points per km
     * - Professional Score: 30 points (0-30) - proportional to score/100
     * - Acceptance Rate: 20 points (0-20) - proportional to acceptance rate
     * - Experience: 10 points (0-10) - min(10, completed_services / 10)
     *
     * @param object $professional Professional entity
     * @param float $serviceLat Service location latitude
     * @param float $serviceLng Service location longitude
     * @return float Match score (0-100)
     */
    private function calculateMatchScore(
        $professional,
        float $serviceLat,
        float $serviceLng
    ): float {
        $score = 0;

        // 1. Proximity (0-40 points): -2 points per km
        $distance = $this->calculateDistance(
            $serviceLat,
            $serviceLng,
            $professional->getServiceRegion()->getLatitude(),
            $professional->getServiceRegion()->getLongitude()
        );
        $proximityScore = max(0, 40 - ($distance * 2));
        $score += $proximityScore;

        // 2. Professional Score (0-30 points)
        $professionalScore = ($professional->getScore() / 100) * 30;
        $score += $professionalScore;

        // 3. Acceptance Rate (0-20 points)
        $acceptanceScore = $professional->getAcceptanceRate() * 20;
        $score += $acceptanceScore;

        // 4. Experience (0-10 points): 1 point per 10 completed services, max 10
        $experienceScore = min(10, $professional->getCompletedServices() / 10);
        $score += $experienceScore;

        return round($score, 2);
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     *
     * @param float $lat1 Latitude 1
     * @param float $lng1 Longitude 1
     * @param float $lat2 Latitude 2
     * @param float $lng2 Longitude 2
     * @return float Distance in kilometers
     */
    private function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }
}
