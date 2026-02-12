<?php
/**
 * SendOffers - Use Case for sending contract offers to matched professionals
 *
 * PRAGMATIC IMPLEMENTATION:
 * Works directly with database to access service_address since Contract aggregate
 * doesn't currently expose this field. This can be refactored when Contract is updated.
 */

namespace LimpVix\Application\UseCase\Briefing;

use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\ContractId;

defined('ABSPATH') || exit;

final class SendOffers
{
    private ProfessionalRepositoryInterface $professionalRepo;
    private ContractRepositoryInterface $contractRepo;
    private \wpdb $wpdb;

    public function __construct(
        ProfessionalRepositoryInterface $professionalRepo,
        ContractRepositoryInterface $contractRepo
    ) {
        global $wpdb;
        $this->professionalRepo = $professionalRepo;
        $this->contractRepo = $contractRepo;
        $this->wpdb = $wpdb;
    }

    /**
     * Execute SendOffers for a contract
     *
     * @param int $contractId Contract ID
     * @param int $offerCount Number of offers to send (default 10)
     * @return array Result with offers_sent count and offers array
     * @throws \RuntimeException if contract not found or has issues
     */
    public function execute(int $contractId, int $offerCount = 10): array
    {
        // 1. Get contract
        $contract = $this->contractRepo->findById(ContractId::fromInt($contractId));

        if (!$contract) {
            throw new \RuntimeException("Contract #{$contractId} not found");
        }

        // 2. Get service_address from database (since Contract aggregate doesn't expose it yet)
        $serviceAddress = $this->getServiceAddress($contractId);

        if (!$serviceAddress || empty($serviceAddress['coordinates'])) {
            throw new \RuntimeException(
                "Contract #{$contractId} has no service address or coordinates. " .
                "Cannot match professionals without location."
            );
        }

        $latitude = (float) ($serviceAddress['coordinates']['latitude'] ?? 0);
        $longitude = (float) ($serviceAddress['coordinates']['longitude'] ?? 0);

        if ($latitude === 0.0 || $longitude === 0.0) {
            throw new \RuntimeException(
                "Contract #{$contractId} has invalid coordinates (0,0). " .
                "Please update the contract with valid location."
            );
        }

        // 3. Check if already has pending offers
        $table = $this->wpdb->prefix . 'limpvix_contract_offers';
        $existingOffers = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE contract_id = %d AND status = 'pending'",
            $contractId
        ));

        if ($existingOffers > 0) {
            throw new \RuntimeException(
                "Contract #{$contractId} already has {$existingOffers} pending offers. " .
                "Cancel or expire existing offers before sending new ones."
            );
        }

        // 4. Get required skills from service_code
        $requiredSkills = $this->getRequiredSkillsFromServiceCode($contract->getServiceCode());

        // 5. Get scheduled start date (use contract start_date)
        $scheduledDateTime = $contract->getStartDate();

        // 6. Find eligible professionals using repository pagination
        $eligibleProfessionals = $this->professionalRepo->findEligibleFor(
            $latitude,
            $longitude,
            $requiredSkills,
            $scheduledDateTime,
            $offerCount * 5, // Fetch 5x more to ensure we have enough after scoring
            0 // offset
        );

        if (empty($eligibleProfessionals)) {
            throw new \RuntimeException(
                'No eligible professionals found for this contract. ' .
                'Criteria: location (' . $latitude . ',' . $longitude . '), ' .
                'skills: ' . implode(', ', $requiredSkills)
            );
        }

        // 7. Score and rank professionals
        $scoredProfessionals = $this->scoreAndRankProfessionals(
            $eligibleProfessionals,
            $latitude,
            $longitude
        );

        // Take top N
        $topProfessionals = array_slice($scoredProfessionals, 0, $offerCount);

        // 8. Create offers
        $offers = $this->createOffers($contractId, $contract->getMonthlyValue(), $topProfessionals);

        // 9. Send notifications (async via WordPress actions)
        foreach ($offers as $offer) {
            do_action('limpvix_send_offer_notification', $offer['professional_id'], $offer['offer_id']);
        }

        // 10. Record domain event
        do_action('limpvix_offers_sent', $contractId, count($offers), $offers);

        return [
            'contract_id' => $contractId,
            'offers_sent' => count($offers),
            'expires_at' => $this->getExpiresAt(),
            'offers' => $offers
        ];
    }

    /**
     * Get service_address from database
     */
    private function getServiceAddress(int $contractId): ?array
    {
        $contractsTable = $this->wpdb->prefix . 'limpvix_contracts';

        $result = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT service_address FROM {$contractsTable} WHERE id = %d",
            $contractId
        ));

        if (!$result) {
            return null;
        }

        return json_decode($result, true);
    }

    /**
     * Map service_code to required skills
     *
     * @TODO: This mapping should come from a configuration table or service catalog
     */
    private function getRequiredSkillsFromServiceCode(string $serviceCode): array
    {
        // Basic mapping - enhance this based on your service catalog
        // Using Portuguese skill names to match database
        $skillsMap = [
            'residential_basic' => ['limpeza_residencial'],
            'residential_standard' => ['limpeza_residencial', 'limpeza_vidros'],
            'residential_premium' => ['limpeza_residencial', 'limpeza_vidros', 'limpeza_pesada'],
            'commercial_basic' => ['limpeza_comercial'],
            'commercial_standard' => ['limpeza_comercial', 'manutencao_piso'],
            'commercial_premium' => ['limpeza_comercial', 'manutencao_piso', 'sanitizacao'],
        ];

        return $skillsMap[$serviceCode] ?? ['limpeza_residencial']; // Default fallback
    }

    /**
     * Score and rank professionals based on multiple criteria
     *
     * Scoring system (0-100 points):
     * - Proximity: 40 points (closer is better)
     * - Professional score: 30 points
     * - Acceptance rate: 20 points
     * - Experience (completed services): 10 points
     */
    private function scoreAndRankProfessionals(
        array $professionals,
        float $targetLat,
        float $targetLng
    ): array {
        $scored = [];

        foreach ($professionals as $professional) {
            $score = 0.0;

            // 1. Proximity score (0-40 points)
            $distance = $this->calculateDistance(
                $targetLat,
                $targetLng,
                $professional->getServiceRegion()->getCenterLatitude(),
                $professional->getServiceRegion()->getCenterLongitude()
            );

            // Max points at 0km, 0 points at 20km+
            $proximityScore = max(0, 40 - ($distance * 2));
            $score += $proximityScore;

            // 2. Professional score (0-30 points)
            $profScore = $professional->getScore(); // 0-100
            $score += ($profScore / 100) * 30;

            // 3. Acceptance rate (0-20 points)
            $acceptanceRate = $professional->getAcceptanceRate(); // 0.0-1.0
            $score += $acceptanceRate * 20;

            // 4. Experience (0-10 points)
            $completedServices = $professional->getCompletedServices();
            $experienceScore = min(10, $completedServices / 10); // 10 services = 1 point
            $score += $experienceScore;

            $scored[] = [
                'professional' => $professional,
                'match_score' => round($score, 2),
                'distance_km' => round($distance, 2),
                'breakdown' => [
                    'proximity' => round($proximityScore, 2),
                    'professional_score' => round(($profScore / 100) * 30, 2),
                    'acceptance_rate' => round($acceptanceRate * 20, 2),
                    'experience' => round($experienceScore, 2),
                ]
            ];
        }

        // Sort by match_score DESC
        usort($scored, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

        return $scored;
    }

    /**
     * Calculate distance between two points using Haversine formula
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

        return $earthRadius * $c;
    }

    /**
     * Create contract offers in database
     */
    private function createOffers(
        int $contractId,
        float $proposedAmount,
        array $scoredProfessionals
    ): array {
        $table = $this->wpdb->prefix . 'limpvix_contract_offers';
        $offers = [];
        $expiresAt = $this->getExpiresAt();

        foreach ($scoredProfessionals as $scored) {
            $professional = $scored['professional'];

            $this->wpdb->insert(
                $table,
                [
                    'contract_id' => $contractId,
                    'professional_id' => $professional->getId(),
                    'proposed_amount' => $proposedAmount,
                    'status' => 'pending',
                    'expires_at' => $expiresAt,
                    'match_score' => $scored['match_score'],
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%f', '%s', '%s', '%f', '%s']
            );

            if ($this->wpdb->insert_id) {
                $offers[] = [
                    'offer_id' => $this->wpdb->insert_id,
                    'professional_id' => $professional->getId(),
                    'professional_name' => $professional->getFullName(),
                    'match_score' => $scored['match_score'],
                    'distance_km' => $scored['distance_km'],
                    'breakdown' => $scored['breakdown'],
                ];
            }
        }

        return $offers;
    }

    /**
     * Get expiration datetime (24 hours from now)
     */
    private function getExpiresAt(): string
    {
        return (new \DateTime())->modify('+24 hours')->format('Y-m-d H:i:s');
    }

    /**
     * Expire pending offers for a contract
     *
     * Useful when re-sending offers or when contract is cancelled
     */
    public function expirePendingOffers(int $contractId): int
    {
        $table = $this->wpdb->prefix . 'limpvix_contract_offers';

        $result = $this->wpdb->update(
            $table,
            [
                'status' => 'expired',
                'responded_at' => current_time('mysql')
            ],
            [
                'contract_id' => $contractId,
                'status' => 'pending'
            ],
            ['%s', '%s'],
            ['%d', '%s']
        );

        return $result ?: 0;
    }
}
