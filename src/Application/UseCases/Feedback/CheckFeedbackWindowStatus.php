<?php
declare(strict_types=1);

/**
 * CheckFeedbackWindowStatus Use Case (GAP #1)
 *
 * Decision engine para determinar se payout pode ser autorizado
 * baseado no status da feedback window e feedback submission.
 *
 * BUSINESS RULES:
 * 1. Feedback submitted + score ≥3.0 → Allow payout immediately
 * 2. Feedback submitted + score <3.0 → Block payout (require manual review)
 * 3. Window expired WITHOUT feedback → Allow payout (auto-approve)
 * 4. Window active, NO feedback yet → Block payout (wait for feedback or expiration)
 *
 * @package LimpVix\Application\UseCases\Feedback
 * @since GAP #1
 */

namespace LimpVix\Application\UseCases\Feedback;

use LimpVix\Application\Common\Result;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Infrastructure\Persistence\WpStructuredFeedbackRepository;

defined('ABSPATH') || exit;

class CheckFeedbackWindowStatus
{
    private const NEGATIVE_FEEDBACK_THRESHOLD = 3.0;
    private const FEATURE_FLAG = 'LIMPVIX_ENABLE_FEEDBACK_WINDOW';

    private ExecutionRepositoryInterface $executionRepository;
    private WpStructuredFeedbackRepository $feedbackRepository;

    public function __construct(
        ExecutionRepositoryInterface $executionRepository,
        WpStructuredFeedbackRepository $feedbackRepository
    ) {
        $this->executionRepository = $executionRepository;
        $this->feedbackRepository = $feedbackRepository;
    }

    /**
     * Execute decision logic for feedback window
     *
     * @param string $orderUuid
     * @return Result<array{
     *     can_authorize_payout: bool,
     *     reason: string,
     *     requires_manual_review: bool,
     *     feedback_score: float|null,
     *     message: string
     * }>
     */
    public function execute(string $orderUuid): Result
    {
        // Feature flag check (rollback mechanism)
        if (defined(self::FEATURE_FLAG) && !constant(self::FEATURE_FLAG)) {
            return Result::ok([
                'can_authorize_payout' => true,
                'reason' => 'feature_disabled',
                'requires_manual_review' => false,
                'feedback_score' => null,
                'message' => 'Feedback window feature is disabled'
            ]);
        }

        // 1. Get execution by order UUID
        $execution = $this->executionRepository->findByOrderUuid($orderUuid);

        if (!$execution) {
            return Result::fail('Execution not found for order: ' . $orderUuid);
        }

        // 2. Check if feedback window is set (backward compatibility)
        $windowExpiresAt = $execution->getFeedbackWindowExpiresAt();

        if ($windowExpiresAt === null) {
            // No feedback window set (old orders) - allow payout
            return Result::ok([
                'can_authorize_payout' => true,
                'reason' => 'no_feedback_window',
                'requires_manual_review' => false,
                'feedback_score' => null,
                'message' => 'No feedback window set (backward compatibility)'
            ]);
        }

        // 3. Check if feedback was submitted
        $feedback = $this->feedbackRepository->findByOrderUuid($orderUuid);

        if ($feedback) {
            // CASE 1 & 2: Feedback submitted - check score
            $score = $this->extractFeedbackScore($feedback);

            if ($score >= self::NEGATIVE_FEEDBACK_THRESHOLD) {
                // CASE 1: Positive feedback (score ≥3.0) - allow payout
                return Result::ok([
                    'can_authorize_payout' => true,
                    'reason' => 'positive_feedback',
                    'requires_manual_review' => false,
                    'feedback_score' => $score,
                    'message' => sprintf('Positive feedback submitted (score: %.1f)', $score)
                ]);
            } else {
                // CASE 2: Negative feedback (score <3.0) - block and require manual review
                return Result::ok([
                    'can_authorize_payout' => false,
                    'reason' => 'negative_feedback',
                    'requires_manual_review' => true,
                    'feedback_score' => $score,
                    'message' => sprintf('Negative feedback submitted (score: %.1f) - manual review required', $score)
                ]);
            }
        }

        // 4. No feedback submitted - check if window expired
        $now = new \DateTimeImmutable();

        if ($now >= $windowExpiresAt) {
            // CASE 3: Window expired without feedback - auto-approve payout
            return Result::ok([
                'can_authorize_payout' => true,
                'reason' => 'window_expired_no_feedback',
                'requires_manual_review' => false,
                'feedback_score' => null,
                'message' => 'Feedback window expired without submission - auto-approving payout'
            ]);
        }

        // CASE 4: Window active, no feedback yet - block and wait
        $timeRemaining = $windowExpiresAt->getTimestamp() - $now->getTimestamp();
        $hoursRemaining = round($timeRemaining / 3600, 1);

        return Result::ok([
            'can_authorize_payout' => false,
            'reason' => 'window_active_waiting',
            'requires_manual_review' => false,
            'feedback_score' => null,
            'message' => sprintf('Feedback window still active (%.1f hours remaining) - waiting for customer feedback', $hoursRemaining)
        ]);
    }

    /**
     * Extract average score from feedback
     *
     * @param array $feedback Feedback row from database
     * @return float Average score
     */
    private function extractFeedbackScore(array $feedback): float
    {
        // If overall_rating exists, use it
        if (isset($feedback['overall_rating']) && $feedback['overall_rating'] !== null) {
            return (float) $feedback['overall_rating'];
        }

        // Otherwise, calculate from structured scores
        $scores = [];

        if (isset($feedback['communication_score']) && $feedback['communication_score'] !== null) {
            $scores[] = (float) $feedback['communication_score'];
        }

        if (isset($feedback['quality_score']) && $feedback['quality_score'] !== null) {
            $scores[] = (float) $feedback['quality_score'];
        }

        if (isset($feedback['punctuality_score']) && $feedback['punctuality_score'] !== null) {
            $scores[] = (float) $feedback['punctuality_score'];
        }

        if (isset($feedback['professionalism_score']) && $feedback['professionalism_score'] !== null) {
            $scores[] = (float) $feedback['professionalism_score'];
        }

        if (empty($scores)) {
            // No scores available, assume neutral (3.0)
            return 3.0;
        }

        return array_sum($scores) / count($scores);
    }
}
