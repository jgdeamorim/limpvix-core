-- Migration 000: Create Migrations Tracking Table
-- Purpose: Track executed migrations to prevent re-runs and ensure schema consistency
-- Date: 2026-02-13

CREATE TABLE IF NOT EXISTS wp_limpvix_migrations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration_name VARCHAR(255) NOT NULL UNIQUE,
    batch INT NOT NULL DEFAULT 1,
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_migration_name (migration_name),
    INDEX idx_executed_at (executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Insert this migration itself as executed
INSERT IGNORE INTO wp_limpvix_migrations (migration_name, batch, executed_at)
VALUES ('000_create_migrations_table', 1, NOW());
