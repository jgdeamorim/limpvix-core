<?php
declare(strict_types=1);

namespace LimpVix\Application\UseCases\Feedback;

use LimpVix\Domain\Feedback\FeedbackRepositoryInterface;
use LimpVix\Common\Result;

defined('ABSPATH') || exit;

/**
 * RejectFeedback Use Case
 *
 * RESPONSIBILITY:
 * - Admin rejects customer feedback
 * - Marks feedback as rejected
 * - Dispatches FeedbackRejected event
 * - Does NOT affect professional score
 * - Does NOT release payout hold
 *
 * USE CASES:
 * - Inappropriate feedback (spam, offensive language)
 * - Fraudulent feedback (fake review)
 * - Feedback not related to service quality
 *
 * Part of: Flow 4.5 - Admin Approval Flow
 *
 * @package LimpVix\Application\UseCases\Feedback
 */
final class RejectFeedback
{
    public function __construct(
        private readonly FeedbackRepositoryInterface $feedbackRepository
    ) {}

    /**
     * Execute use case
     *
     * @param int $feedbackId Feedback ID to reject
     * @param int $validatedBy Admin user ID who is rejecting
     * @param string $reason Rejection reason (required for audit)
     * @return Result<void>
     */
    public function execute(int $feedbackId, int $validatedBy, string $reason): Result
    {
        // 1. Validate reason
        if (empty(trim($reason))) {
            return Result::fail('Rejection reason is required');
        }

        // 2. Find feedback
        $feedback = $this->feedbackRepository->findById($feedbackId);

        if ($feedback === null) {
            return Result::fail(sprintf('Feedback #%d not found', $feedbackId));
        }

        // 3. Check if already rejected
        if ($feedback->isRejected()) {
            return Result::fail(sprintf('Feedback #%d already rejected', $feedbackId));
        }

        // 4. Check if already approved (cannot reject approved feedback)
        if ($feedback->isApproved()) {
            return Result::fail(sprintf('Feedback #%d is already approved and cannot be rejected', $feedbackId));
        }

        // 5. Reject feedback (domain logic)
        try {
            $feedback->reject($validatedBy, $reason);
        } catch (\DomainException $e) {
            return Result::fail($e->getMessage());
        }

        // 6. Persist
        try {
            $this->feedbackRepository->save($feedback);
        } catch (\RuntimeException $e) {
            return Result::fail(sprintf('Failed to save feedback: %s', $e->getMessage()));
        }

        // 7. Dispatch domain events
        // FeedbackRejected event (currently no listeners, but logged for audit)
        $events = $feedback->releaseEvents();
        foreach ($events as $event) {
            do_action('limpvix_domain_event', $event);
        }

        return Result::ok(null);
    }
}
