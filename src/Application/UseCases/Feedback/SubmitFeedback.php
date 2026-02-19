<?php
declare(strict_types=1);

namespace LimpVix\Application\UseCases\Feedback;

use LimpVix\Domain\Feedback\Feedback;
use LimpVix\Domain\Feedback\FeedbackRepositoryInterface;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Shared\Result;

defined('ABSPATH') || exit;

/**
 * SubmitFeedback Use Case
 *
 * BUSINESS RULES:
 * - Feedback can only be submitted for completed executions (CHECKED_OUT with evidence)
 * - One feedback per order (enforced by DB UNIQUE constraint)
 * - Rating must be 1-5
 * - Rating < 3 automatically requires admin validation
 *
 * SIDE EFFECTS:
 * - Dispatches FeedbackCreated event
 * - May trigger payout hold if rating ≤ 2
 * - May trigger score recalculation if rating >= 3
 *
 * Part of: Flow 4 - Feedback System
 *
 * @package LimpVix\Application\UseCases\Feedback
 */
final class SubmitFeedback
{
    public function __construct(
        private readonly FeedbackRepositoryInterface $feedbackRepository,
        private readonly ExecutionRepositoryInterface $executionRepository
    ) {}

    /**
     * Execute use case
     *
     * @param int $orderId Order UUID or ID
     * @param int $professionalId Professional who executed the service
     * @param int $clientId Client who is submitting feedback
     * @param int $rating Rating 1-5
     * @param string|null $comment Optional comment
     * @return Result<Feedback>
     */
    public function execute(
        int $orderId,
        int $professionalId,
        int $clientId,
        int $rating,
        ?string $comment = null
    ): Result {
        // 1. Validate service is completed (P0.1: CHECKED_OUT with evidence = validated)
        $execution = $this->executionRepository->findByOrderId($orderId);

        if ($execution === null) {
            return Result::fail(sprintf('Execution not found for order #%d', $orderId));
        }

        if (!$execution->getStatus()->isServiceCompleted()) {
            return Result::fail(
                sprintf(
                    'Cannot submit feedback: Service must be completed (current status: %s)',
                    $execution->getStatus()->value
                )
            );
        }

        // 2. Check for duplicate feedback (one per order)
        $existingFeedback = $this->feedbackRepository->findByOrderId($orderId);

        if ($existingFeedback !== null) {
            return Result::fail(sprintf('Feedback already exists for order #%d', $orderId));
        }

        // 3. Create Feedback aggregate
        try {
            $feedback = Feedback::create(
                orderId: $orderId,
                professionalId: $professionalId,
                clientId: $clientId,
                rating: $rating,
                comment: $comment
            );
        } catch (\InvalidArgumentException $e) {
            return Result::fail($e->getMessage());
        }

        // 4. Persist feedback
        try {
            $this->feedbackRepository->save($feedback);
        } catch (\RuntimeException $e) {
            // UNIQUE constraint violation or other DB error
            return Result::fail(sprintf('Failed to save feedback: %s', $e->getMessage()));
        }

        // 5. Dispatch domain events (handled by infrastructure layer)
        $events = $feedback->releaseEvents();
        foreach ($events as $event) {
            do_action('limpvix_domain_event', $event);
        }

        return Result::ok($feedback);
    }
}
