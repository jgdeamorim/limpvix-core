-- =====================================================
-- Migration 044: Create Feedback Criteria Table
--
-- Criterios individuais de avaliacao do feedback
-- Cada criterio tem score 1-5 e categoria
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_feedback_criteria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID do criterio',
    feedback_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to feedback.id',

    criteria_key VARCHAR(100) NOT NULL COMMENT 'limpeza_geral|banheiro|cozinha|pontualidade|educacao|etc',
    label VARCHAR(255) NOT NULL COMMENT 'Label exibido ao cliente',
    category VARCHAR(50) NOT NULL COMMENT 'limpeza|comportamento|pontualidade|geral',

    score INT NOT NULL COMMENT 'Nota 1-5',
    observation TEXT NULL COMMENT 'Observacao opcional do avaliador',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY unique_feedback_criteria (feedback_id, criteria_key),
    INDEX idx_feedback_id (feedback_id),
    INDEX idx_criteria_key (criteria_key),
    INDEX idx_score (score),
    INDEX idx_category (category)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Feedback Criteria - Criterios individuais de avaliacao (score por item)';
