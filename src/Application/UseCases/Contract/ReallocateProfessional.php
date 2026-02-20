<?php
/**
 * ReallocateProfessional - Use Case
 *
 * Admin-initiated reallocation of professional for a contract.
 *
 * FLOW:
 * 1. Fetch contract and validate state
 * 2. Fetch new professional and validate eligibility
 * 3. Check for active executions (blocks reallocation)
 * 4. Get pending executions (will be updated)
 * 5. Execute reallocation on contract (domain method)
 * 6. Save contract
 * 7. Record in allocation history
 * 8. Update pending executions
 * 9. Dispatch domain events (for notifications)
 *
 * VALIDATIONS:
 * - Contract exists and is allocated
 * - New professional exists and is active
 * - No executions in progress (in_progress status)
 * - New professional is eligible (skills, region, availability)
 *
 * SIDE EFFECTS:
 * - Updates contract.allocated_professional_id
 * - Records in wp_limpvix_professional_allocations_history (2 entries: deallocation + allocation)
 * - Updates execution.professional_id for all pending executions
 * - Emits ProfessionalReallocated domain event
 *
 * @package LimpVix\Application\UseCases\Contract
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCases\Contract;

use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use LimpVix\Domain\Service\CapabilityRegistry;
use LimpVix\Infrastructure\Persistence\WpExecutionRepository;

defined('ABSPATH') || exit;

final class ReallocateProfessional
{
    public function __construct(
        private ContractRepositoryInterface $contractRepository,
        private ProfessionalRepositoryInterface $professionalRepository,
        private WpExecutionRepository $executionRepository
    ) {}

    /**
     * Execute reallocation
     *
     * @param int $contractId Contract to reallocate
     * @param int $newProfessionalId New professional
     * @param string $reason Reason for reallocation
     * @param int|null $adminUserId Admin who initiated
     * @return array Result
     * @throws \RuntimeException if validation fails
     */
    public function execute(
        int $contractId,
        int $newProfessionalId,
        string $reason,
        ?int $adminUserId = null
    ): array {
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

        // 3. Fetch new professional
        $newProfessional = $this->professionalRepository->findById($newProfessionalId);

        if (!$newProfessional) {
            throw new \RuntimeException("Professional #{$newProfessionalId} not found");
        }

        // 4. Validate new professional is active
        if (!$newProfessional->isActive()) {
            throw new \RuntimeException(
                "Professional #{$newProfessionalId} is not active (status: inactive)"
            );
        }

        // ✅ KYC VALIDATION (GAP FIX - 2026-02-16)
        // Garantir que novo profissional pode aceitar alocação:
        // - KYC aprovado e não expirado
        // - Profissional ativo e verificado
        // - Não suspenso
        if (!$newProfessional->canAcceptOffers()) {
            throw new \RuntimeException(
                "Professional #{$newProfessionalId} não pode aceitar alocação. " .
                'Verifique: KYC aprovado, não expirado, ativo e não suspenso.'
            );
        }

        // Eligibility checks: skills match + region compatibility
        $this->validateEligibility($contract, $newProfessional);

        // 5. Check for active executions (in_progress) - BLOCKS reallocation
        $activeExecutions = $this->executionRepository->findActiveExecutionsForContract($contractId);

        if (!empty($activeExecutions)) {
            throw new \RuntimeException(
                sprintf(
                    "Cannot reallocate: Contract has %d active execution(s) in progress. " .
                    "Wait for completion or cancel them first.",
                    count($activeExecutions)
                )
            );
        }

        // 6. Get pending/scheduled executions (will be updated)
        $pendingExecutions = $this->executionRepository->findPendingExecutionsForContract($contractId);

        // 7. Store old professional ID (before reallocation)
        $oldProfessionalId = $contract->getAllocatedProfessionalId();

        // 8. Execute reallocation (domain method - validates and records event)
        try {
            $contract->reallocateProfessional($newProfessionalId, $reason, $adminUserId);
        } catch (\DomainException $e) {
            throw new \RuntimeException(
                "Domain validation failed: " . $e->getMessage(),
                0,
                $e
            );
        }

        // 9. Save contract (transaction start)
        $this->contractRepository->save($contract);

        // 10. Record in allocation history (append-only audit trail)
        // Entry 1: Deallocation of old professional
        $this->contractRepository->recordAllocationHistory([
            'contract_id' => $contractId,
            'professional_id' => $oldProfessionalId,
            'deallocated_at' => date('Y-m-d H:i:s'),
            'deallocation_reason' => $reason,
            'deallocation_notes' => sprintf(
                'Reallocated to professional #%d by admin #%d',
                $newProfessionalId,
                $adminUserId ?? 0
            ),
        ]);

        // Entry 2: Allocation of new professional
        $this->contractRepository->recordAllocationHistory([
            'contract_id' => $contractId,
            'professional_id' => $newProfessionalId,
            'allocated_at' => date('Y-m-d H:i:s'),
            'allocation_reason' => 'reallocation',
            'allocation_notes' => sprintf(
                'Reallocated from professional #%d. Reason: %s',
                $oldProfessionalId,
                $reason
            ),
        ]);

        // 11. Update pending executions (cascade change to executions)
        $updatedExecutionsCount = 0;
        foreach ($pendingExecutions as $execution) {
            $updated = $this->executionRepository->updateProfessional(
                $execution['id'],
                $newProfessionalId
            );

            if ($updated) {
                $updatedExecutionsCount++;
            }
        }

        // 12. Dispatch domain events (contract already recorded ProfessionalReallocated)
        // Events will be released by repository/event dispatcher

        // 13. Return success result
        return [
            'success' => true,
            'contract_id' => $contractId,
            'old_professional_id' => $oldProfessionalId,
            'new_professional_id' => $newProfessionalId,
            'reason' => $reason,
            'pending_executions_updated' => $updatedExecutionsCount,
            'message' => sprintf(
                'Professional successfully reallocated from #%d to #%d. %d pending execution(s) updated.',
                $oldProfessionalId,
                $newProfessionalId,
                $updatedExecutionsCount
            ),
        ];
    }

    /**
     * Validate professional eligibility for this contract
     *
     * Checks:
     * - Required skills for the service type
     * - Service region compatibility
     *
     * @param mixed $contract
     * @param mixed $newProfessional
     * @throws \RuntimeException if ineligible
     */
    private function validateEligibility($contract, $newProfessional): void
    {
        // Check required capabilities for the contract's service
        $serviceCode = $contract->getServiceCode();
        $requiredSkills = $this->getRequiredSkillsForService($serviceCode, $contract->getComplexitySlug());

        if (!empty($requiredSkills)) {
            $professionalSkills = $newProfessional->getSkills();
            $missingSkills = [];

            foreach ($requiredSkills as $skill) {
                if (!$professionalSkills->hasSkill($skill)) {
                    $missingSkills[] = $skill;
                }
            }

            if (!empty($missingSkills)) {
                throw new \RuntimeException(sprintf(
                    'Professional #%d lacks required skills for service "%s": %s',
                    $newProfessional->getId(),
                    $serviceCode,
                    implode(', ', $missingSkills)
                ));
            }
        }

        // Check service region compatibility
        $professionalRegion = $newProfessional->getServiceRegion();
        if ($professionalRegion && method_exists($contract, 'getRegion')) {
            $contractRegion = $contract->getRegion();
            if ($contractRegion && !$professionalRegion->coversRegion($contractRegion)) {
                throw new \RuntimeException(sprintf(
                    'Professional #%d service region does not cover contract region "%s"',
                    $newProfessional->getId(),
                    $contractRegion
                ));
            }
        }
    }

    /**
     * Get required capability slugs for a service via CapabilityRegistry.
     *
     * Looks up the first active complexity for the contract's service_code,
     * then uses CapabilityRegistry::computeRequired() to get capability slugs.
     * Falls back to ['cleaning_basic'] if no capabilities are found.
     *
     * @param string $serviceCode
     * @return string[]
     */
    private function getRequiredSkillsForService(string $serviceCode, ?string $complexitySlug = null): array
    {
        global $wpdb;

        $complexityTable = $wpdb->prefix . 'limpvix_service_complexities';
        $catalogTable = $wpdb->prefix . 'limpvix_service_catalog';

        // If contract has complexity_slug, use it for exact match
        if ($complexitySlug) {
            $complexityId = $wpdb->get_var($wpdb->prepare(
                "SELECT sc.id FROM {$complexityTable} sc
                 JOIN {$catalogTable} cat ON sc.service_id = cat.id
                 WHERE cat.service_code = %s AND sc.slug = %s AND sc.is_active = 1",
                $serviceCode,
                $complexitySlug
            ));
        } else {
            // Fallback: first active complexity (legacy contracts without complexity_slug)
            $complexityId = $wpdb->get_var($wpdb->prepare(
                "SELECT sc.id FROM {$complexityTable} sc
                 JOIN {$catalogTable} cat ON sc.service_id = cat.id
                 WHERE cat.service_code = %s AND sc.is_active = 1
                 ORDER BY sc.id ASC LIMIT 1",
                $serviceCode
            ));
        }

        if ($complexityId) {
            $capabilities = CapabilityRegistry::computeRequired((int) $complexityId, []);

            if (!empty($capabilities)) {
                return array_map(fn($cap) => $cap->getSlug(), $capabilities);
            }
        }

        return ['cleaning_basic'];
    }
}
