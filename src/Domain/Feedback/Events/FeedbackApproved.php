<?php
declare(strict_types=1);

namespace LimpVix\Domain\Feedback\Events;

defined('ABSPATH') || exit;

/**
 * FeedbackApproved Domain Event
 *
 * Triggered when admin approves feedback.
 *
 * LISTENERS:
 * - RecalculateProfessionalScore (always)
 * - ReleasePayoutHold (if rating <= 2 and payout was held)
 *
 * Part of: Flow 4 - Feedback System
 *
 * @package LimpVix\Domain\Feedback\Events
 */
final class FeedbackApproved
{
    public function __construct(
        public readonly int $feedbackId,
        public readonly int $orderId,
        public readonly int $professionalId,
        public readonly int $rating,
        public readonly int $validatedBy
    ) {}
}
