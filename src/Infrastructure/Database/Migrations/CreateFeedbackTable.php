<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Database\Migrations;

defined('ABSPATH') || exit;

/**
 * Migration: Create Feedback Table
 *
 * PURPOSE:
 * - Structured feedback system for order executions
 * - Professional score calculation basis
 * - Payout hold logic based on rating
 * - Admin moderation workflow
 *
 * ARCHITECTURE:
 * - NO soft delete (feedback is historical evidence)
 * - UNIQUE constraint on order_id (one feedback per order)
 * - Rating validation (1-5)
 * - Integration with payout hold period
 *
 * Part of: Flow 4 - Feedback System
 *
 * @package LimpVix\Infrastructure\Database\Migrations
 */
final class CreateFeedbackTable
{
    private const TABLE_NAME = 'limpvix_feedback';
    private const VERSION = '025';

    public function getName(): string
    {
        return 'Create Feedback Table';
    }

    public function getVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Execute migration - Create feedback table
     */
    public function up(): void
    {
        global $wpdb;

        $tableName = $wpdb->prefix . self::TABLE_NAME;
        $charsetCollate = $wpdb->get_charset_collate();

        // Check if table already exists (idempotency)
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tableName}'") === $tableName) {
            error_log("Migration {$this->getVersion()}: Table {$tableName} already exists, skipping creation");
            return;
        }

        $sql = "CREATE TABLE {$tableName} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            
            -- References
            order_id INT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_orders',
            professional_id INT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_professionals',
            client_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to wp_users',
            
            -- Feedback data
            rating TINYINT UNSIGNED NOT NULL COMMENT 'Rating 1-5 stars',
            comment TEXT NULL COMMENT 'Client feedback text',
            
            -- Admin moderation
            evidence_required BOOLEAN DEFAULT FALSE COMMENT 'Admin needs to validate evidence',
            validated_by_admin BOOLEAN DEFAULT FALSE COMMENT 'Admin approved feedback',
            validation_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending' COMMENT 'Moderation status',
            validated_by INT UNSIGNED NULL COMMENT 'User ID who validated',
            
            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Feedback submission date',
            validated_at DATETIME NULL COMMENT 'Admin validation date',
            
            -- Constraints
            UNIQUE KEY unique_order_feedback (order_id) COMMENT 'One feedback per order',
            
            -- Indexes
            INDEX idx_professional_id (professional_id) COMMENT 'Score calculation queries',
            INDEX idx_client_id (client_id) COMMENT 'Client feedback history',
            INDEX idx_rating (rating) COMMENT 'Rating distribution queries',
            INDEX idx_validation_status (validation_status) COMMENT 'Admin moderation queue',
            INDEX idx_created_at (created_at) COMMENT 'Chronological queries',
            
            -- Constraints validation
            CONSTRAINT chk_rating_range CHECK (rating BETWEEN 1 AND 5)
        ) {$charsetCollate} COMMENT='Structured feedback for order executions';";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Verify table creation
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tableName}'") !== $tableName) {
            throw new \RuntimeException("Failed to create table {$tableName}");
        }

        // Verify UNIQUE constraint was created
        $uniqueConstraints = $wpdb->get_results(
            "SHOW INDEX FROM {$tableName} WHERE Key_name = 'unique_order_feedback'"
        );

        if (empty($uniqueConstraints)) {
            error_log("WARNING: UNIQUE constraint on order_id may not have been created properly");
        }

        error_log("Migration {$this->getVersion()}: Table {$tableName} created successfully");
    }

    /**
     * Rollback migration - Drop feedback table
     *
     * WARNING: This will permanently delete all feedback data
     */
    public function down(): void
    {
        global $wpdb;

        $tableName = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists before dropping
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tableName}'") !== $tableName) {
            error_log("Migration {$this->getVersion()}: Table {$tableName} does not exist, nothing to rollback");
            return;
        }

        // Drop table
        $wpdb->query("DROP TABLE IF EXISTS {$tableName}");

        // Verify table was dropped
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tableName}'") === $tableName) {
            throw new \RuntimeException("Failed to drop table {$tableName}");
        }

        error_log("Migration {$this->getVersion()}: Table {$tableName} dropped successfully");
    }
}
