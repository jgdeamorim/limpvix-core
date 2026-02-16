<?php
/**
 * OnExecutionNoShow Listener
 *
 * Triggered when professional does not show up for scheduled execution.
 *
 * EVENT: limpvix_execution_no_show
 *
 * ACTIONS:
 * 1. Update professional score (-0.5 points)
 * 2. Alert admin about no-show incident
 * 3. If score < 3.0 → Alert admin (low score)
 * 4. If score < 2.5 → Recommend reallocation (critical)
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 0.9.0
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\UseCase\Professional\UpdateProfessionalScore;
use LimpVix\Application\UseCase\Contract\GetReallocationOptions;
use LimpVix\Application\Services\AdminNotificationService;
use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;
use LimpVix\Infrastructure\Persistence\WpContractRepository;

defined('ABSPATH') || exit;

final class OnExecutionNoShow
{
    /**
     * Initialize listener
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();
        add_action('limpvix_execution_no_show', [$instance, 'handle'], 10, 1);
    }

    /**
     * Handle no-show event
     *
     * @param array $eventData Event data from ExecutionNoShow
     * @return void
     */
    public function handle(array $eventData): void
    {
        $executionId = $eventData['execution_id'] ?? null;
        $contractId = $eventData['contract_id'] ?? null;
        $professionalId = $eventData['professional_id'] ?? null;

        if (!$professionalId || !$contractId) {
            error_log('[LimpVix] OnExecutionNoShow: Missing required data (professional_id or contract_id)');
            return;
        }

        try {
            // Initialize repositories (fallback to globals or new instance)
            $professionalRepo = $GLOBALS['limpvix_professional_repository'] ?? new WpMarketplaceProfessionalRepository();
            $contractRepo = $GLOBALS['limpvix_contract_repository'] ?? new WpContractRepository();

            // Initialize dependencies
            $updateScore = new UpdateProfessionalScore($professionalRepo);
            $notificationService = new AdminNotificationService($contractRepo, $professionalRepo);
            $getOptions = new GetReallocationOptions($contractRepo, $professionalRepo);

            // 1. Update professional score (-0.5 for no-show)
            $result = $updateScore->execute(
                $professionalId,
                'no_show',
                [
                    'execution_id' => $executionId,
                    'contract_id' => $contractId,
                ]
            );

            $newScore = $result['new_score'] ?? null;
            $oldScore = $result['old_score'] ?? null;

            if ($newScore === null) {
                error_log('[LimpVix] OnExecutionNoShow: Failed to update score');
                return;
            }

            // 2. Alert admin about no-show
            $notificationService->alertNoShow(
                $executionId,
                $contractId,
                $professionalId
            );

            // 3. Check score threshold - Low score alert
            if ($newScore < 3.0) {
                $notificationService->notifyLowScore(
                    $professionalId,
                    $newScore,
                    'no_show'
                );

                error_log(sprintf(
                    '[LimpVix] OnExecutionNoShow: Professional #%d reached low score: %.2f',
                    $professionalId,
                    $newScore
                ));
            }

            // 4. Critical threshold - Recommend reallocation
            if ($newScore < 2.5) {
                // Get alternative professionals
                $options = $getOptions->execute($contractId, 5);

                if (!empty($options)) {
                    $notificationService->recommendReallocation(
                        $contractId,
                        $professionalId,
                        'critical_low_score',
                        [
                            'old_score' => $oldScore,
                            'new_score' => $newScore,
                            'trigger' => 'no_show',
                            'execution_id' => $executionId,
                            'alternatives_count' => count($options),
                        ]
                    );

                    error_log(sprintf(
                        '[LimpVix] OnExecutionNoShow: Reallocation recommended for Contract #%d (Professional #%d score: %.2f)',
                        $contractId,
                        $professionalId,
                        $newScore
                    ));
                } else {
                    // No alternatives available
                    $notificationService->alertNoAlternatives(
                        $contractId,
                        $professionalId,
                        'no_show_critical_score'
                    );
                }
            }

            error_log(sprintf(
                '[LimpVix] OnExecutionNoShow: Processed no-show for Professional #%d (Execution #%d). Score: %.2f → %.2f',
                $professionalId,
                $executionId,
                $oldScore,
                $newScore
            ));

        } catch (\Exception $e) {
            error_log('[LimpVix] OnExecutionNoShow: Error processing no-show - ' . $e->getMessage());
        }
    }
}
