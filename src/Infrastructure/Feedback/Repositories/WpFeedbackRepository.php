<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Feedback\Repositories;

use LimpVix\Domain\Feedback\Feedback;
use LimpVix\Domain\Feedback\FeedbackRepositoryInterface;

defined('ABSPATH') || exit;

/**
 * WpFeedbackRepository - WordPress implementation
 *
 * Implements FeedbackRepositoryInterface using WordPress database.
 *
 * Part of: Flow 4 - Feedback System
 *
 * @package LimpVix\Infrastructure\Feedback\Repositories
 */
final class WpFeedbackRepository implements FeedbackRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_feedback';
    }

    public function save(Feedback $feedback): void
    {
        $data = $feedback->toArray();

        if ($feedback->getId() === 0) {
            // Insert
            $result = $this->wpdb->insert($this->table, $data);

            if ($result === false) {
                throw new \RuntimeException(
                    sprintf('Failed to insert feedback: %s', $this->wpdb->last_error)
                );
            }

            // Set generated ID
            $feedback->setId((int) $this->wpdb->insert_id);
        } else {
            // Update
            $result = $this->wpdb->update(
                $this->table,
                $data,
                ['id' => $feedback->getId()]
            );

            if ($result === false) {
                throw new \RuntimeException(
                    sprintf('Failed to update feedback #%d: %s', $feedback->getId(), $this->wpdb->last_error)
                );
            }
        }
    }

    public function findById(int $id): ?Feedback
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ? Feedback::reconstitute($row) : null;
    }

    public function findByOrderId(int $orderId): ?Feedback
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE order_id = %d", $orderId),
            ARRAY_A
        );

        return $row ? Feedback::reconstitute($row) : null;
    }

    public function findByProfessionalId(int $professionalId, int $limit = 100, int $offset = 0): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE professional_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $professionalId,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return array_map(fn($row) => Feedback::reconstitute($row), $results);
    }

    public function findPendingFeedback(int $limit = 50, int $offset = 0): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE validation_status = 'pending' ORDER BY created_at ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return array_map(fn($row) => Feedback::reconstitute($row), $results);
    }

    public function countApprovedByProfessional(int $professionalId): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE professional_id = %d AND validation_status = 'approved'",
                $professionalId
            )
        );
    }

    public function getAverageRatingForProfessional(int $professionalId): float
    {
        $avg = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT AVG(rating) FROM {$this->table} WHERE professional_id = %d AND validation_status = 'approved'",
                $professionalId
            )
        );

        return $avg !== null ? (float) $avg : 0.0;
    }

    public function findApprovedByProfessional(int $professionalId, int $limit = 30): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} 
                 WHERE professional_id = %d 
                 AND validation_status = 'approved' 
                 ORDER BY validated_at DESC 
                 LIMIT %d",
                $professionalId,
                $limit
            ),
            ARRAY_A
        );

        return array_map(fn($row) => Feedback::reconstitute($row), $results);
    }
}
