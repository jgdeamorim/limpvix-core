<?php
namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\UseCases\Contract\GetReallocationOptions;
use LimpVix\Application\Services\AdminNotificationService;
use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;
use LimpVix\Infrastructure\Persistence\WpContractRepository;

defined('ABSPATH') || exit;

final class OnScoreThresholdReached
{
    private const CRITICAL_THRESHOLD = 3.0;

    public static function init(): void
    {
        $instance = new self();
        add_action('limpvix_professional_score_updated', [$instance, 'handle'], 10, 1);
    }

    public function handle(array $eventData): void
    {
        $professionalId = $eventData['professional_id'] ?? null;
        $newScore = $eventData['new_score'] ?? null;
        $oldScore = $eventData['old_score'] ?? null;
        $reason = $eventData['reason'] ?? 'unknown';

        if ($professionalId === null || $newScore === null || $oldScore === null) {
            error_log('[LimpVix] OnScoreThreshold: Missing required data');
            return;
        }

        $crossedThreshold = ($oldScore >= self::CRITICAL_THRESHOLD) && ($newScore < self::CRITICAL_THRESHOLD);
        if (!$crossedThreshold) {
            return;
        }

        try {
            $professionalRepo = $GLOBALS['limpvix_professional_repository'] ?? new WpMarketplaceProfessionalRepository();
            $contractRepo = $GLOBALS['limpvix_contract_repository'] ?? new WpContractRepository();

            $notificationService = new AdminNotificationService($contractRepo, $professionalRepo);
            $getOptions = new GetReallocationOptions($contractRepo, $professionalRepo);

            error_log(sprintf(
                '[LimpVix] OnScoreThreshold: Professional #%d crossed threshold (%.2f → %.2f)',
                $professionalId,
                $oldScore,
                $newScore
            ));

            $contracts = $contractRepo->findContractsAllocatedTo($professionalId);
            if (empty($contracts)) {
                error_log("[LimpVix] OnScoreThreshold: No contracts found for Professional #{$professionalId}");
                return;
            }

            $activeContracts = array_filter($contracts, function ($contract) {
                return $contract->getStatus()->isActive();
            });

            if (empty($activeContracts)) {
                return;
            }

            foreach ($activeContracts as $contract) {
                try {
                    $options = $getOptions->execute($contract->getId(), 5);

                    if (!empty($options)) {
                        $notificationService->recommendReallocation(
                            $contract->getId(),
                            $professionalId,
                            'score_threshold',
                            [
                                'old_score' => $oldScore,
                                'new_score' => $newScore,
                                'reason' => $reason,
                                'threshold' => self::CRITICAL_THRESHOLD,
                                'alternatives_count' => count($options),
                                'top_alternative' => $options[0]['name'] ?? 'N/A',
                            ]
                        );

                        error_log(sprintf(
                            '[LimpVix] OnScoreThreshold: Recommended reallocation for Contract #%d',
                            $contract->getId()
                        ));
                    } else {
                        $notificationService->alertNoAlternatives(
                            $contract->getId(),
                            $professionalId,
                            'score_threshold'
                        );
                    }
                } catch (\Exception $e) {
                    error_log(sprintf(
                        '[LimpVix] OnScoreThreshold: Error processing Contract #%d - %s',
                        $contract->getId(),
                        $e->getMessage()
                    ));
                }
            }

        } catch (\Exception $e) {
            error_log('[LimpVix] OnScoreThreshold: Error - ' . $e->getMessage());
        }
    }
}
