<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\EventListeners;

use LimpVix\Domain\Feedback\Events\FeedbackApproved;
use LimpVix\Domain\Finance\PayoutRepositoryInterface;
use LimpVix\Application\UseCases\Financial\ExecutePayout;

defined('ABSPATH') || exit;

/**
 * ReleasePayoutHoldOnFeedbackApproved Event Listener
 *
 * TRIGGER: FeedbackApproved event
 *
 * LOGIC:
 * - When admin approves feedback with rating ≤ 2
 * - Find payout for that order
 * - If payout status is "on_hold"
 * - Release hold and execute payout
 *
 * INTEGRATION:
 * - Listens to: limpvix_domain_event (FeedbackApproved)
 * - Calls: ExecutePayout use case
 *
 * Part of: Flow 4.4 - Payout Hold Integration
 *
 * @package LimpVix\Infrastructure\EventListeners
 */
final class ReleasePayoutHoldOnFeedbackApproved
{
    public function __construct(
        private readonly PayoutRepositoryInterface $payoutRepository,
        private readonly ExecutePayout $executePayout
    ) {}

    /**
     * Handle FeedbackApproved event
     *
     * @param FeedbackApproved $event
     */
    public function handle(FeedbackApproved $event): void
    {
        // Only process if rating was critical (≤ 2)
        // Higher ratings never cause hold, so no need to release
        if ($event->rating > 2) {
            return;
        }

        // Find payout for this order
        $payout = $this->payoutRepository->getByOrder($event->orderId);

        if ($payout === null) {
            // No payout found for this order (might not exist yet)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[ReleasePayoutHold] No payout found for order #%d (feedback #%d approved)',
                    $event->orderId,
                    $event->feedbackId
                ));
            }
            return;
        }

        // Check if payout is on_hold
        if ($payout['status'] !== 'on_hold') {
            // Payout already processed or in different state
            return;
        }

        // Release hold and execute payout
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[ReleasePayoutHold] Releasing payout #%d for order #%d after feedback #%d approval by admin #%d',
                $payout['id'],
                $event->orderId,
                $event->feedbackId,
                $event->validatedBy
            ));
        }

        // Execute payout (will now pass feedback check since feedback is approved)
        $result = $this->executePayout->execute((int) $payout['id']);

        if ($result->isFail()) {
            // Log failure but don't throw (event listener should not break flow)
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[ReleasePayoutHold] ERROR: Failed to execute payout #%d after feedback approval: %s',
                    $payout['id'],
                    $result->error()
                ));
            }
        } else {
            // Success - payout released and processed
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[ReleasePayoutHold] SUCCESS: Payout #%d released and executed after feedback #%d approval',
                    $payout['id'],
                    $event->feedbackId
                ));
            }
        }
    }

    /**
     * Register event listener
     */
    public static function register(
        PayoutRepositoryInterface $payoutRepository,
        ExecutePayout $executePayout
    ): void {
        $listener = new self($payoutRepository, $executePayout);

        add_action('limpvix_domain_event', function ($event) use ($listener) {
            if ($event instanceof FeedbackApproved) {
                $listener->handle($event);
            }
        }, 10, 1);
    }
}
