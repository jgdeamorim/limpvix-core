<?php
declare(strict_types=1);

namespace LimpVix\Domain\Feedback\Events;

defined('ABSPATH') || exit;

/**
 * FeedbackCreated Domain Event
 *
 * Triggered when client submits feedback for an order.
 *
 * LISTENERS:
 * - RecalculateProfessionalScore (if rating >= 3 and not evidenceRequired)
 * - HoldPayoutIfCriticalRating (if rating <= 2)
 *
 * Part of: Flow 4 - Feedback System
 *
 * @package LimpVix\Domain\Feedback\Events
 */
final class FeedbackCreated
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $professionalId,
        public readonly int $clientId,
        public readonly int $rating,
        public readonly bool $evidenceRequired
    ) {}
}
