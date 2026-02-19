<?php
/**
 * SendOffers - Use Case for sending contract offers to matched professionals
 *
 * PRAGMATIC IMPLEMENTATION:
 * Works directly with database to access service_address since Contract aggregate
 * doesn't currently expose this field. This can be refactored when Contract is updated.
 */

namespace LimpVix\Application\UseCases\Briefing;

use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Application\Services\Matching\ProfessionalMatcher;
use LimpVix\Domain\Service\CapabilityRegistry;

defined('ABSPATH') || exit;

final class SendOffers
{
    private ProfessionalRepositoryInterface $professionalRepo;
    private ContractRepositoryInterface $contractRepo;
    private ProfessionalMatcher $matcher;
    private \wpdb $wpdb;

    public function __construct(
        ProfessionalRepositoryInterface $professionalRepo,
        ContractRepositoryInterface $contractRepo,
        ?ProfessionalMatcher $matcher = null
    ) {
        global $wpdb;
        $this->professionalRepo = $professionalRepo;
        $this->contractRepo = $contractRepo;
        $this->matcher = $matcher ?? ProfessionalMatcher::fromConfig();
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

        // 4. Get required capabilities (new system) with fallback to legacy skills
        $capabilityResult = $this->getRequiredCapabilities($contractId, $contract->getServiceCode());
        $requiredSkills = $capabilityResult['skills'];
        $useCapabilities = $capabilityResult['use_capabilities'];
        $capabilitySlugs = $capabilityResult['capability_slugs'];

        // 5. Get scheduled start date (use contract start_date with default time 10:00)
        // Note: start_date is DATE type (no time), so we set 10:00 as default matching time
        // Real execution time will be defined later in Execution aggregate
        $scheduledDateTime = $contract->getStartDate()->setTime(10, 0);

        // 6. Find eligible professionals using repository pagination
        error_log("[SendOffers] DEBUG: Calling findEligibleFor with lat=$latitude, lng=$longitude");
        error_log("[SendOffers] DEBUG: Required skills: " . implode(',', $requiredSkills));
        if ($useCapabilities) {
            error_log("[SendOffers] DEBUG: Capabilities: " . implode(',', $capabilitySlugs));
        }
        error_log("[SendOffers] DEBUG: DateTime: " . $scheduledDateTime->format('Y-m-d H:i:s'));

        $eligibleProfessionals = $this->professionalRepo->findEligibleFor(
            $latitude,
            $longitude,
            $requiredSkills,
            $scheduledDateTime,
            $offerCount * 5, // Fetch 5x more to ensure we have enough after scoring
            0 // offset
        );

        // 6b. If using capabilities, filter further by capability match
        if ($useCapabilities && !empty($capabilitySlugs)) {
            $eligibleProfessionals = array_filter(
                $eligibleProfessionals,
                fn($prof) => $prof->hasRequiredCapabilities($capabilitySlugs)
            );
            $eligibleProfessionals = array_values($eligibleProfessionals);
        }

        error_log("[SendOffers] DEBUG: findEligibleFor returned " . count($eligibleProfessionals) . " professionals");

        if (empty($eligibleProfessionals)) {
            throw new \RuntimeException(
                'No eligible professionals found for this contract. ' .
                'Criteria: location (' . $latitude . ',' . $longitude . '), ' .
                'skills: ' . implode(', ', $requiredSkills) .
                ($useCapabilities ? ', capabilities: ' . implode(', ', $capabilitySlugs) : '')
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
     * Get required capabilities for matching
     *
     * New system (FASE 3): Uses CapabilityRegistry to compute required capabilities
     * from complexity + additionals. Falls back to legacy required_skills if
     * capability tables are not populated.
     *
     * @param int $contractId
     * @param string $serviceCode
     * @return array{skills: string[], use_capabilities: bool, capability_slugs: string[]}
     */
    private function getRequiredCapabilities(int $contractId, string $serviceCode): array
    {
        // Try new capability system first
        $complexityId = $this->getComplexityIdForContract($contractId, $serviceCode);

        if ($complexityId) {
            $additionalIds = $this->getAdditionalIdsForContract($contractId);
            $capabilities = CapabilityRegistry::computeRequired($complexityId, $additionalIds);

            if (!empty($capabilities)) {
                $slugs = array_map(fn($cap) => $cap->getSlug(), $capabilities);
                error_log("[SendOffers] Using capability-based matching: " . implode(', ', $slugs));

                return [
                    'skills' => ['limpeza_basica'], // Minimal skills for initial repo filter
                    'use_capabilities' => true,
                    'capability_slugs' => $slugs,
                ];
            }
        }

        // Fallback: legacy required_skills from service_catalog
        $skills = $this->getRequiredSkillsFromServiceCode($serviceCode);
        return [
            'skills' => $skills,
            'use_capabilities' => false,
            'capability_slugs' => [],
        ];
    }

    /**
     * Get complexity_id for a contract's service
     *
     * Looks up the service_complexities table based on the contract's service_code.
     * Returns the first active complexity for that service.
     *
     * @param int $contractId
     * @param string $serviceCode
     * @return int|null
     */
    private function getComplexityIdForContract(int $contractId, string $serviceCode): ?int
    {
        // Check if complexity tables exist
        $complexityTable = $this->wpdb->prefix . 'limpvix_service_complexities';
        $tableExists = $this->wpdb->get_var(
            "SHOW TABLES LIKE '{$complexityTable}'"
        );

        if (!$tableExists) {
            return null;
        }

        $catalogTable = $this->wpdb->prefix . 'limpvix_service_catalog';

        // Get first active complexity for this service
        $complexityId = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT sc.id FROM {$complexityTable} sc
                 JOIN {$catalogTable} cat ON sc.service_id = cat.id
                 WHERE cat.service_code = %s AND sc.is_active = 1
                 ORDER BY sc.id ASC LIMIT 1",
                $serviceCode
            )
        );

        return $complexityId ? (int) $complexityId : null;
    }

    /**
     * Get additional_ids selected for a contract's briefing
     *
     * @param int $contractId
     * @return int[]
     */
    private function getAdditionalIdsForContract(int $contractId): array
    {
        $contractsTable = $this->wpdb->prefix . 'limpvix_contracts';
        $briefingAddTable = $this->wpdb->prefix . 'limpvix_briefing_additionals';

        // Get briefing_uuid from contract
        $briefingUuid = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT briefing_uuid FROM {$contractsTable} WHERE id = %d",
                $contractId
            )
        );

        if (!$briefingUuid) {
            return [];
        }

        // Get additional_ids from briefing_additionals
        $ids = $this->wpdb->get_col(
            $this->wpdb->prepare(
                "SELECT additional_id FROM {$briefingAddTable} WHERE briefing_uuid = %s",
                $briefingUuid
            )
        );

        return array_map('intval', $ids);
    }

    /**
     * Legacy: Map service_code to required skills
     *
     * Fallback when capability tables are not populated.
     * GAP D: Uses database-driven mapping from wp_limpvix_service_catalog.required_skills
     *
     * @param string $serviceCode
     * @return string[]
     */
    private function getRequiredSkillsFromServiceCode(string $serviceCode): array
    {
        $table = $this->wpdb->prefix . 'limpvix_service_catalog';

        $requiredSkills = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT required_skills FROM {$table} WHERE service_code = %s AND is_active = 1",
                $serviceCode
            )
        );

        if ($requiredSkills) {
            $skills = json_decode($requiredSkills, true);

            if (is_array($skills) && !empty($skills)) {
                return $skills;
            }
        }

        return ['limpeza_basica']; // Default fallback
    }

    /**
     * Score and rank professionals using unified ProfessionalMatcher (G-MATCHING-DUAL)
     *
     * Delegates to ProfessionalMatcher for consistent scoring across
     * SendOffers (async) and AllocationEngine (sync).
     */
    private function scoreAndRankProfessionals(
        array $professionals,
        float $targetLat,
        float $targetLng
    ): array {
        $scored = [];

        foreach ($professionals as $professional) {
            $distance = ProfessionalMatcher::haversineDistance(
                $targetLat,
                $targetLng,
                $professional->getServiceRegion()->getCenterLatitude(),
                $professional->getServiceRegion()->getCenterLongitude()
            );

            $matchScore = $this->matcher->calculateScore(
                distanceKm: $distance,
                professionalScore: (float) $professional->getScore(),
                acceptanceRate: $professional->getAcceptanceRate(),
                averageRating: $professional->getAverageRating(),
                completedServices: $professional->getCompletedServices()
            );

            $scored[] = [
                'professional' => $professional,
                'match_score' => $matchScore->total,
                'distance_km' => $matchScore->distanceKm,
                'breakdown' => $matchScore->toArray(),
            ];
        }

        usort($scored, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

        return $scored;
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
