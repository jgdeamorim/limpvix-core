<?php
declare(strict_types=1);

namespace LimpVix\Domain\Feedback\Events;

defined('ABSPATH') || exit;

/**
 * FeedbackRejected Domain Event
 *
 * Triggered when admin rejects feedback.
 *
 * LISTENERS:
 * - (None currently - rejected feedback does not affect score or payout)
 *
 * FUTURE:
 * - NotifyClient (explain why feedback was rejected)
 * - AuditLog (track admin moderation decisions)
 *
 * Part of: Flow 4 - Feedback System
 *
 * @package LimpVix\Domain\Feedback\Events
 */
final class FeedbackRejected
{
    public function __construct(
        public readonly int $feedbackId,
        public readonly int $orderId,
        public readonly int $professionalId,
        public readonly int $validatedBy,
        public readonly string $reason
    ) {}
}
