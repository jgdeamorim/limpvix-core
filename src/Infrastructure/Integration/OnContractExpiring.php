<?php
/**
 * OnContractExpiring Listener
 *
 * Triggered by daily cron job.
 * Reviews contracts expiring soon and recommends different professional
 * for renewal if current professional has poor performance.
 *
 * TRIGGER: limpvix_daily_cron (scheduled event)
 *
 * THRESHOLDS:
 * - Expiring within 7 days
 * - Professional score < 3.5 = Poor performance
 *
 * ACTIONS:
 * 1. Find contracts expiring in 7 days
 * 2. For each contract:
 *    - Check professional performance
 *    - If poor → Recommend renewal with different professional
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.9.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\UseCase\Contract\GetReallocationOptions;
use LimpVix\Application\Services\AdminNotificationService;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;

defined('ABSPATH') || exit;

final class OnContractExpiring
{
    private const EXPIRY_WARNING_DAYS = 7;
    private const POOR_PERFORMANCE_THRESHOLD = 3.5;

    /**
     * Initialize listener
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        // Register daily cron handler
        add_action('limpvix_daily_cron', [$instance, 'handle']);
    }

    /**
     * Handle daily cron check for expiring contracts
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            // Lazy initialization of dependencies
            $notificationService = new AdminNotificationService(
                $GLOBALS['limpvix_contract_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpContractRepository(),
                $GLOBALS['limpvix_professional_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository()
            );

            $getOptions = new GetReallocationOptions(
                $GLOBALS['limpvix_contract_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpContractRepository(),
                $GLOBALS['limpvix_professional_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository()
            );

            $contractRepository = $GLOBALS['limpvix_contract_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpContractRepository();
            $professionalRepository = $GLOBALS['limpvix_professional_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository();

            if (!$contractRepository || !$professionalRepository) {
                error_log('[LimpVix] OnContractExpiring: Repositories not available');
                return;
            }

            $expiryDate = (new \DateTimeImmutable())
                ->modify('+' . self::EXPIRY_WARNING_DAYS . ' days');

            error_log(sprintf(
                '[LimpVix] OnContractExpiring: Checking contracts expiring before %s',
                $expiryDate->format('Y-m-d')
            ));

            // Find contracts expiring soon
            $expiringContracts = $contractRepository->findExpiringContracts($expiryDate);

            if (empty($expiringContracts)) {
                error_log('[LimpVix] OnContractExpiring: No expiring contracts found');
                return;
            }

            error_log(sprintf(
                '[LimpVix] OnContractExpiring: Found %d contracts expiring soon',
                count($expiringContracts)
            ));

            $reviewedCount = 0;
            $recommendedCount = 0;

            foreach ($expiringContracts as $contract) {
                try {
                    $contractId = $contract->getId();
                    $professionalId = $contract->getAllocatedProfessionalId();

                    if (!$professionalId) {
                        continue; // No professional allocated
                    }

                    // Get professional performance
                    $professional = $professionalRepository->findById($professionalId);

                    if (!$professional) {
                        error_log(sprintf(
                            '[LimpVix] OnContractExpiring: Professional #%d not found for Contract #%d',
                            $professionalId,
                            $contractId
                        ));
                        continue;
                    }

                    $reviewedCount++;

                    $currentScore = $professional->getScore();

                    // Check if performance is poor
                    if ($currentScore >= self::POOR_PERFORMANCE_THRESHOLD) {
                        // Good performance - no action needed
                        continue;
                    }

                    // Poor performance - recommend different professional for renewal
                    error_log(sprintf(
                        '[LimpVix] OnContractExpiring: Poor performance detected for Contract #%d (Professional #%d score: %.2f)',
                        $contractId,
                        $professionalId,
                        $currentScore
                    ));

                    // Get alternative professionals
                    $options = $getOptions->execute($contractId, 3);

                    if (!empty($options)) {
                        $notificationService->recommendRenewalWithDifferentProfessional(
                            $contractId,
                            $professionalId,
                            [
                                'current_score' => $currentScore,
                                'threshold' => self::POOR_PERFORMANCE_THRESHOLD,
                                'expires_at' => $contract->getEndDate()->format('Y-m-d'),
                                'days_until_expiry' => $this->calculateDaysUntilExpiry($contract->getEndDate()),
                                'alternatives_count' => count($options),
                                'top_alternatives' => array_slice($options, 0, 3),
                            ]
                        );

                        $recommendedCount++;

                        error_log(sprintf(
                            '[LimpVix] OnContractExpiring: Recommended renewal with different professional for Contract #%d',
                            $contractId
                        ));
                    }

                } catch (\Exception $e) {
                    error_log(sprintf(
                        '[LimpVix] OnContractExpiring: Error processing Contract #%d - %s',
                        $contract->getId(),
                        $e->getMessage()
                    ));
                }
            }

            error_log(sprintf(
                '[LimpVix] OnContractExpiring: Completed. Reviewed: %d, Recommended: %d (Total expiring: %d)',
                $reviewedCount,
                $recommendedCount,
                count($expiringContracts)
            ));

        } catch (\Exception $e) {
            error_log('[LimpVix] OnContractExpiring: Error - ' . $e->getMessage());
        }
    }

    /**
     * Calculate days until contract expiry
     *
     * @param \DateTimeImmutable $endDate Contract end date
     * @return int Days until expiry
     */
    private function calculateDaysUntilExpiry(\DateTimeImmutable $endDate): int
    {
        $now = new \DateTimeImmutable();
        $interval = $now->diff($endDate);
        return (int) $interval->days;
    }
}
