<?php
declare(strict_types=1);

namespace LimpVix\Application\UseCases\Feedback;

use LimpVix\Domain\Feedback\FeedbackRepositoryInterface;
use LimpVix\Common\Result;

defined('ABSPATH') || exit;

/**
 * ApproveFeedback Use Case
 *
 * RESPONSIBILITY:
 * - Admin approves customer feedback
 * - Marks feedback as validated
 * - Dispatches FeedbackApproved event
 * - Triggers professional score recalculation
 * - Releases payout hold if rating ≤ 2
 *
 * SIDE EFFECTS:
 * - FeedbackApproved event → RecalculateProfessionalScore
 * - FeedbackApproved event → ReleasePayoutHold (if rating ≤ 2)
 *
 * Part of: Flow 4.5 - Admin Approval Flow
 *
 * @package LimpVix\Application\UseCases\Feedback
 */
final class ApproveFeedback
{
    public function __construct(
        private readonly FeedbackRepositoryInterface $feedbackRepository
    ) {}

    /**
     * Execute use case
     *
     * @param int $feedbackId Feedback ID to approve
     * @param int $validatedBy Admin user ID who is approving
     * @return Result<void>
     */
    public function execute(int $feedbackId, int $validatedBy): Result
    {
        // 1. Find feedback
        $feedback = $this->feedbackRepository->findById($feedbackId);

        if ($feedback === null) {
            return Result::fail(sprintf('Feedback #%d not found', $feedbackId));
        }

        // 2. Check if already approved
        if ($feedback->isApproved()) {
            return Result::fail(sprintf('Feedback #%d already approved', $feedbackId));
        }

        // 3. Approve feedback (domain logic)
        try {
            $feedback->approve($validatedBy);
        } catch (\DomainException $e) {
            return Result::fail($e->getMessage());
        }

        // 4. Persist
        try {
            $this->feedbackRepository->save($feedback);
        } catch (\RuntimeException $e) {
            return Result::fail(sprintf('Failed to save feedback: %s', $e->getMessage()));
        }

        // 5. Dispatch domain events
        // FeedbackApproved event will trigger:
        // - RecalculateProfessionalScore (always)
        // - ReleasePayoutHold (if rating ≤ 2)
        $events = $feedback->releaseEvents();
        foreach ($events as $event) {
            do_action('limpvix_domain_event', $event);
        }

        return Result::ok(null);
    }
}
