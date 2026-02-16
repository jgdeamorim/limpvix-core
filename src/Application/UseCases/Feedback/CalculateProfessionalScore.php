<?php
declare(strict_types=1);

namespace LimpVix\Application\UseCases\Feedback;

use LimpVix\Domain\Feedback\FeedbackRepositoryInterface;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use LimpVix\Common\Result;

defined('ABSPATH') || exit;

/**
 * CalculateProfessionalScore Use Case
 *
 * RESPONSIBILITY:
 * - Calculate professional score based on approved feedback
 * - Uses weighted average with exponential decay (recent feedback weighs more)
 * - Updates Professional aggregate score
 *
 * ALGORITHM:
 * - Fetch last 30 approved feedbacks for professional
 * - Apply exponential decay: weight = 0.95^days_old
 * - Score = sum(rating * weight) / sum(weights)
 * - Output range: 0-5 (matching Professional.updateScore() validation)
 *
 * TRIGGERED BY:
 * - FeedbackApproved event (via UpdateProfessionalScoreOnFeedbackApproved listener)
 *
 * Part of: Flow 4.6 - RecalculateProfessionalScore
 *
 * @package LimpVix\Application\UseCases\Feedback
 */
final class CalculateProfessionalScore
{
    private const MAX_FEEDBACKS = 30;
    private const DECAY_RATE = 0.95; // 5% decay per day

    public function __construct(
        private readonly FeedbackRepositoryInterface $feedbackRepository,
        private readonly ProfessionalRepositoryInterface $professionalRepository
    ) {}

    /**
     * Execute use case
     *
     * @param int $professionalId Professional ID to recalculate score
     * @return Result<float> New score (0-5)
     */
    public function execute(int $professionalId): Result
    {
        // 1. Find professional
        $professional = $this->professionalRepository->findById($professionalId);

        if ($professional === null) {
            return Result::fail(sprintf('Professional #%d not found', $professionalId));
        }

        // 2. Get approved feedbacks (last 30, ordered by validation date DESC)
        $approvedFeedbacks = $this->feedbackRepository->findApprovedByProfessional(
            $professionalId,
            self::MAX_FEEDBACKS
        );

        // 3. Calculate weighted score
        $score = $this->calculateWeightedScore($approvedFeedbacks);

        // 4. Update professional score
        try {
            $professional->updateScore($score);
        } catch (\DomainException $e) {
            return Result::fail($e->getMessage());
        }

        // 5. Persist
        try {
            $this->professionalRepository->save($professional);
        } catch (\RuntimeException $e) {
            return Result::fail(sprintf('Failed to save professional: %s', $e->getMessage()));
        }

        return Result::ok($score);
    }

    /**
     * Calculate weighted score with exponential decay
     *
     * Recent feedback weighs more than old feedback.
     * Formula: score = sum(rating * weight) / sum(weights)
     * Weight = DECAY_RATE ^ days_since_feedback
     *
     * @param array $feedbacks Array of Feedback aggregates (approved only)
     * @return float Score 0-5
     */
    private function calculateWeightedScore(array $feedbacks): float
    {
        if (empty($feedbacks)) {
            return 5.0; // Default score for new professionals (max score)
        }

        $now = new \DateTimeImmutable();
        $totalWeightedRating = 0.0;
        $totalWeight = 0.0;

        foreach ($feedbacks as $feedback) {
            // Days since feedback was validated
            $validatedAt = $feedback->getValidatedAt();
            if ($validatedAt === null) {
                continue; // Skip if not validated (shouldn't happen for approved feedbacks)
            }

            $daysOld = $now->diff($validatedAt)->days;

            // Exponential decay: older feedback has less weight
            $weight = pow(self::DECAY_RATE, $daysOld);

            // Rating is already 1-5
            $rating = (float) $feedback->getRating();

            $totalWeightedRating += $rating * $weight;
            $totalWeight += $weight;
        }

        // Avoid division by zero
        if ($totalWeight === 0.0) {
            return 5.0;
        }

        $score = $totalWeightedRating / $totalWeight;

        // Clamp to 0-5 range and round to 2 decimals
        return round(max(0.0, min(5.0, $score)), 2);
    }
}
