<?php
/**
 * OnConsecutivePoorFeedback Listener
 *
 * Triggered when customer submits feedback for execution.
 * Detects pattern of consecutive poor feedbacks and recommends reallocation.
 *
 * EVENT: limpvix_feedback_submitted
 *
 * THRESHOLDS:
 * - Rating < 3.0 = Poor feedback
 * - 3+ consecutive poor feedbacks = Pattern detected
 * - Within 30 days window
 *
 * ACTIONS:
 * 1. Check if feedback is poor (< 3.0)
 * 2. Query recent feedback history
 * 3. If 3+ consecutive poor → Recommend reallocation
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

final class OnConsecutivePoorFeedback
{
    private const THRESHOLD_RATING = 3.0;
    private const CONSECUTIVE_COUNT = 3;
    private const DAYS_WINDOW = 30;

    /**
     * Initialize listener
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();
        add_action('limpvix_feedback_submitted', [$instance, 'handle'], 10, 1);
    }

    /**
     * Handle feedback submitted event
     *
     * @param array $eventData Event data from FeedbackSubmittedEvent
     * @return void
     */
    public function handle(array $eventData): void
    {
        $feedbackId = $eventData['feedback_id'] ?? null;
        $professionalId = $eventData['professional_id'] ?? null;
        $rating = $eventData['final_score'] ?? null;
        $contractId = $eventData['contract_id'] ?? null;

        if ($professionalId === null || $rating === null) {
            error_log('[LimpVix] OnConsecutivePoorFeedback: Missing required data');
            return;
        }

        // Only trigger on poor feedback
        if ($rating >= self::THRESHOLD_RATING) {
            return; // Good feedback, ignore
        }

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
                error_log('[LimpVix] OnConsecutivePoorFeedback: Repositories not available');
                return;
            }

            error_log(sprintf(
                '[LimpVix] OnConsecutivePoorFeedback: Poor feedback detected for Professional #%d (rating: %.1f)',
                $professionalId,
                $rating
            ));

            // Query consecutive poor feedbacks
            $poorFeedbacks = $professionalRepository->findConsecutivePoorFeedbacks(
                $professionalId,
                self::THRESHOLD_RATING,
                self::CONSECUTIVE_COUNT,
                self::DAYS_WINDOW
            );

            $poorCount = count($poorFeedbacks);

            if ($poorCount < self::CONSECUTIVE_COUNT) {
                // Not enough consecutive poor feedbacks yet
                error_log(sprintf(
                    '[LimpVix] OnConsecutivePoorFeedback: Professional #%d has %d poor feedbacks (threshold: %d)',
                    $professionalId,
                    $poorCount,
                    self::CONSECUTIVE_COUNT
                ));
                return;
            }

            // Pattern detected! Recommend reallocation
            error_log(sprintf(
                '[LimpVix] OnConsecutivePoorFeedback: Pattern detected! Professional #%d has %d consecutive poor feedbacks',
                $professionalId,
                $poorCount
            ));

            // Find all active contracts for this professional
            $contracts = $contractRepository->findContractsAllocatedTo($professionalId);

            if (empty($contracts)) {
                error_log("[LimpVix] OnConsecutivePoorFeedback: No contracts found for Professional #{$professionalId}");
                return;
            }

            // Filter active contracts
            $activeContracts = array_filter($contracts, function ($contract) {
                return $contract->getStatus()->isActive();
            });

            if (empty($activeContracts)) {
                return;
            }

            // Recommend reallocation for each active contract
            foreach ($activeContracts as $contract) {
                try {
                    $options = $getOptions->execute($contract->getId(), 5);

                    if (!empty($options)) {
                        $notificationService->recommendReallocation(
                            $contract->getId(),
                            $professionalId,
                            'consecutive_poor_feedback',
                            [
                                'poor_feedback_count' => $poorCount,
                                'latest_rating' => $rating,
                                'threshold_rating' => self::THRESHOLD_RATING,
                                'days_window' => self::DAYS_WINDOW,
                                'alternatives_count' => count($options),
                            ]
                        );

                        error_log(sprintf(
                            '[LimpVix] OnConsecutivePoorFeedback: Recommended reallocation for Contract #%d',
                            $contract->getId()
                        ));
                    } else {
                        $notificationService->alertNoAlternatives(
                            $contract->getId(),
                            $professionalId,
                            'consecutive_poor_feedback'
                        );
                    }
                } catch (\Exception $e) {
                    error_log(sprintf(
                        '[LimpVix] OnConsecutivePoorFeedback: Error processing Contract #%d - %s',
                        $contract->getId(),
                        $e->getMessage()
                    ));
                }
            }

            error_log(sprintf(
                '[LimpVix] OnConsecutivePoorFeedback: Processed %d active contracts for Professional #%d',
                count($activeContracts),
                $professionalId
            ));

        } catch (\Exception $e) {
            error_log('[LimpVix] OnConsecutivePoorFeedback: Error - ' . $e->getMessage());
        }
    }
}
