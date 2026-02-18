<?php
/**
 * OnProfessionalSuspended Listener
 *
 * Triggered when professional is suspended (temporarily or permanently).
 *
 * EVENT: limpvix_professional_suspended
 *
 * ACTIONS (CRITICAL - AUTO-REALLOCATION):
 * 1. Find ALL contracts (active + paused)
 * 2. For each contract:
 *    - Get best alternative professional
 *    - Auto-reallocate if alternative exists
 *    - Alert admin if no alternatives
 * 3. Notify all stakeholders
 *
 * This is the MOST CRITICAL listener - it performs automatic reallocation
 * without admin approval because suspended professional cannot work.
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.9.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\UseCases\Contract\ReallocateProfessional;
use LimpVix\Application\UseCases\Contract\GetReallocationOptions;
use LimpVix\Application\Services\AdminNotificationService;
use LimpVix\Domain\Contract\ContractRepositoryInterface;

defined('ABSPATH') || exit;

final class OnProfessionalSuspended
{
    /**
     * Initialize listener
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();
        add_action('limpvix_professional_suspended', [$instance, 'handle'], 10, 1);
    }

    /**
     * Handle professional suspended event
     *
     * @param array $eventData Event data from ProfessionalSuspended
     * @return void
     */
    public function handle(array $eventData): void
    {
        $professionalId = $eventData['professional_id'] ?? null;
        $reason = $eventData['reason'] ?? 'unknown';
        $suspendedBy = $eventData['suspended_by'] ?? null;
        $isPermanent = $eventData['is_permanent'] ?? false;

        if ($professionalId === null) {
            error_log('[LimpVix] OnProfessionalSuspended: Missing professional_id');
            return;
        }

        try {
            // Lazy initialization of dependencies
            $contractRepo = $GLOBALS['limpvix_contract_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpContractRepository();
            $professionalRepo = $GLOBALS['limpvix_professional_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository();
            $executionRepo = new \LimpVix\Infrastructure\Persistence\WpExecutionRepository();

            if (!$contractRepo || !$professionalRepo) {
                error_log('[LimpVix] OnProfessionalSuspended: Repositories not available');
                return;
            }

            $reallocateProfessional = new ReallocateProfessional(
                $contractRepo,
                $professionalRepo,
                $executionRepo
            );

            $getOptions = new GetReallocationOptions(
                $contractRepo,
                $professionalRepo
            );

            $notificationService = new AdminNotificationService(
                $contractRepo,
                $professionalRepo
            );

            error_log(sprintf(
                '[LimpVix] OnProfessionalSuspended: Processing suspension for Professional #%d (reason: %s, permanent: %s)',
                $professionalId,
                $reason,
                $isPermanent ? 'yes' : 'no'
            ));

            // Find ALL contracts (active + paused) for this professional
            $contracts = $contractRepo->findContractsAllocatedTo($professionalId);

            if (empty($contracts)) {
                error_log("[LimpVix] OnProfessionalSuspended: No contracts found for Professional #{$professionalId}");
                return;
            }

            // Filter contracts that need reallocation (active or paused)
            $affectedContracts = array_filter($contracts, function ($contract) {
                return $contract->getStatus()->isActive() || $contract->getStatus()->isPaused();
            });

            if (empty($affectedContracts)) {
                error_log("[LimpVix] OnProfessionalSuspended: No active/paused contracts for Professional #{$professionalId}");
                return;
            }

            error_log(sprintf(
                '[LimpVix] OnProfessionalSuspended: Found %d contracts to reallocate',
                count($affectedContracts)
            ));

            $successCount = 0;
            $failCount = 0;

            // Auto-reallocate each contract
            foreach ($affectedContracts as $contract) {
                try {
                    $contractId = $contract->getId();

                    // Get best alternative professional
                    $options = $getOptions->execute($contractId, 1);

                    if (empty($options)) {
                        // No alternatives available - CRITICAL
                        error_log(sprintf(
                            '[LimpVix] OnProfessionalSuspended: NO ALTERNATIVES for Contract #%d',
                            $contractId
                        ));

                        $notificationService->alertNoAlternatives(
                            $contractId,
                            $professionalId,
                            'professional_suspended'
                        );

                        $failCount++;
                        continue;
                    }

                    // Get best option (first in ranked list)
                    $bestOption = $options[0];
                    $newProfessionalId = $bestOption['professional_id'];

                    // AUTO-REALLOCATE (no admin approval needed - professional is suspended)
                    $result = $reallocateProfessional->execute(
                        $contractId,
                        $newProfessionalId,
                        'professional_suspended',
                        $suspendedBy
                    );

                    if ($result['success']) {
                        $successCount++;

                        error_log(sprintf(
                            '[LimpVix] OnProfessionalSuspended: AUTO-REALLOCATED Contract #%d: Professional #%d → #%d',
                            $contractId,
                            $professionalId,
                            $newProfessionalId
                        ));

                        // Notify admin about auto-reallocation
                        $notificationService->recommendReallocation(
                            $contractId,
                            $professionalId,
                            'professional_suspended_auto_reallocated',
                            [
                                'old_professional_id' => $professionalId,
                                'new_professional_id' => $newProfessionalId,
                                'new_professional_name' => $bestOption['name'],
                                'new_professional_score' => $bestOption['score'],
                                'match_score' => $bestOption['match_score'],
                                'suspension_reason' => $reason,
                                'is_permanent' => $isPermanent,
                                'auto_reallocated' => true,
                            ]
                        );
                    } else {
                        $failCount++;
                        error_log(sprintf(
                            '[LimpVix] OnProfessionalSuspended: Failed to reallocate Contract #%d',
                            $contractId
                        ));
                    }

                } catch (\Exception $e) {
                    $failCount++;
                    error_log(sprintf(
                        '[LimpVix] OnProfessionalSuspended: Error reallocating Contract #%d - %s',
                        $contract->getId(),
                        $e->getMessage()
                    ));
                }
            }

            error_log(sprintf(
                '[LimpVix] OnProfessionalSuspended: Completed. Success: %d, Failed: %d (Total: %d contracts)',
                $successCount,
                $failCount,
                count($affectedContracts)
            ));

        } catch (\Exception $e) {
            error_log('[LimpVix] OnProfessionalSuspended: Error - ' . $e->getMessage());
        }
    }
}
