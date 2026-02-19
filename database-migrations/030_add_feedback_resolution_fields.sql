-- Migration 030: Create wp_limpvix_feedback table + Resolution fields
-- Date: 2026-02-18
-- Purpose:
--   1. Creates the main feedback table (wp_limpvix_feedback) used by WpFeedbackRepository.
--   2. Includes feedback resolution columns that allow admin to register how a
--      blocking feedback was handled before releasing the payout.
--   3. Severity (grave/medio/leve) impacts professional score via score_penalty.

CREATE TABLE IF NOT EXISTS `wp_limpvix_feedback` (
    `id`                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `order_id`            BIGINT UNSIGNED NOT NULL UNIQUE COMMENT 'One feedback per order',
    `professional_id`     BIGINT UNSIGNED NOT NULL,
    `client_id`           BIGINT UNSIGNED NOT NULL,
    `rating`              TINYINT UNSIGNED NOT NULL COMMENT '1-5 stars',
    `comment`             TEXT NULL,
    `evidence_required`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'TRUE if rating < 3',
    `validated_by_admin`  TINYINT(1) NOT NULL DEFAULT 0,
    `validation_status`   ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `validated_by`        INT UNSIGNED NULL COMMENT 'WP user ID who validated',
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `validated_at`        DATETIME NULL,

    -- Resolution fields (Migration 030)
    `resolution_text`     TEXT NULL
        COMMENT 'Descrição da resolução registrada pelo responsável (gerente/usuário)',
    `resolution_severity` ENUM('grave','medio','leve') NULL
        COMMENT 'Gravidade: grave=−1.50pts, medio=−0.75pts, leve=0pts no score',
    `resolution_by`       INT UNSIGNED NULL
        COMMENT 'User ID WP do responsável que registrou a resolução',
    `resolution_by_name`  VARCHAR(200) NULL
        COMMENT 'Nome do responsável (cache display_name)',
    `resolution_at`       DATETIME NULL
        COMMENT 'Data/hora em que a resolução foi registrada',
    `score_penalty`       DECIMAL(3,2) NULL DEFAULT NULL
        COMMENT 'Penalidade aplicada ao rating: grave=1.50, medio=0.75, leve=0.00',

    INDEX `idx_professional_id`   (`professional_id`),
    INDEX `idx_validation_status` (`validation_status`),
    INDEX `idx_created_at`        (`created_at`),
    INDEX `idx_validated_at`      (`validated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Main feedback table — one per order, drives payout hold logic and professional score';
