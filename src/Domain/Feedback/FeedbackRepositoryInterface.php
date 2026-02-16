<?php
declare(strict_types=1);

namespace LimpVix\Domain\Feedback;

defined('ABSPATH') || exit;

/**
 * Feedback Repository Interface
 *
 * Contract for feedback persistence.
 *
 * IMPLEMENTATION NOTES:
 * - save() must handle UNIQUE constraint on order_id
 * - findByOrderId() returns null if not found
 * - findByProfessionalId() supports score calculation queries
 *
 * Part of: Flow 4 - Feedback System
 *
 * @package LimpVix\Domain\Feedback
 */
interface FeedbackRepositoryInterface
{
    /**
     * Save feedback (insert or update)
     *
     * @throws \RuntimeException if UNIQUE constraint violated (duplicate order_id)
     */
    public function save(Feedback $feedback): void;

    /**
     * Find feedback by ID
     */
    public function findById(int $id): ?Feedback;

    /**
     * Find feedback by order ID
     *
     * IMPORTANT: Returns null if order has no feedback yet
     */
    public function findByOrderId(int $orderId): ?Feedback;

    /**
     * Find all feedback for a professional
     *
     * Used for score calculation.
     *
     * @return Feedback[]
     */
    public function findByProfessionalId(int $professionalId, int $limit = 100, int $offset = 0): array;

    /**
     * Find pending feedback (validation_status = 'pending')
     *
     * Used for admin moderation queue.
     *
     * @return Feedback[]
     */
    public function findPendingFeedback(int $limit = 50, int $offset = 0): array;

    /**
     * Count approved feedback for professional
     *
     * Used for score calculation (validated_jobs_ratio).
     */
    public function countApprovedByProfessional(int $professionalId): int;

    /**
     * Get average rating for professional
     *
     * Only considers approved feedback.
     */
    public function getAverageRatingForProfessional(int $professionalId): float;

    /**
     * Find approved feedback for a professional
     *
     * Returns only feedback with validation_status = 'approved',
     * ordered by validated_at DESC (most recent first).
     * Used for score calculation with temporal decay.
     *
     * @param int $professionalId
     * @param int $limit Max feedbacks to return (default 30)
     * @return Feedback[]
     */
    public function findApprovedByProfessional(int $professionalId, int $limit = 30): array;
}
