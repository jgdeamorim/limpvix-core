<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\EventListeners;

use LimpVix\Domain\Feedback\Events\FeedbackApproved;
use LimpVix\Application\UseCases\Feedback\CalculateProfessionalScore;

defined('ABSPATH') || exit;

/**
 * UpdateProfessionalScoreOnFeedbackApproved Event Listener
 *
 * RESPONSIBILITY:
 * - Listen to FeedbackApproved event
 * - Trigger CalculateProfessionalScore use case
 * - Update professional's score based on all approved feedback
 *
 * INTEGRATION:
 * - Hook: limpvix_domain_event (FeedbackApproved)
 * - Triggered by: ApproveFeedback use case
 *
 * Part of: Flow 4.6 - RecalculateProfessionalScore
 *
 * @package LimpVix\Infrastructure\EventListeners
 */
final class UpdateProfessionalScoreOnFeedbackApproved
{
    private CalculateProfessionalScore $calculateScore;

    public function __construct(CalculateProfessionalScore $calculateScore)
    {
        $this->calculateScore = $calculateScore;
    }

    /**
     * Register event listener
     *
     * @param CalculateProfessionalScore $calculateScore
     * @return void
     */
    public static function register(CalculateProfessionalScore $calculateScore): void
    {
        $listener = new self($calculateScore);

        add_action('limpvix_domain_event', function ($event) use ($listener) {
            if ($event instanceof FeedbackApproved) {
                $listener->handle($event);
            }
        }, 10, 1);
    }

    /**
     * Handle FeedbackApproved event
     *
     * @param FeedbackApproved $event
     * @return void
     */
    public function handle(FeedbackApproved $event): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[UpdateProfessionalScore] Recalculating score for professional #%d after feedback #%d approval (rating: %d)',
                $event->professionalId,
                $event->feedbackId,
                $event->rating
            ));
        }

        // Recalculate professional score
        $result = $this->calculateScore->execute($event->professionalId);

        if ($result->isFail()) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[UpdateProfessionalScore] ERROR: Failed to update score for professional #%d: %s',
                    $event->professionalId,
                    $result->error()
                ));
            }
            return;
        }

        $newScore = $result->value();

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[UpdateProfessionalScore] SUCCESS: Professional #%d score updated to %.2f',
                $event->professionalId,
                $newScore
            ));
        }
    }
}
