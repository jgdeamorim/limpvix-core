-- =====================================================
-- Migration 045: Create Feedback Photos Table
--
-- Fotos enviadas pelo cliente junto com o feedback
-- Suporta fotos de antes/depois e problemas
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_feedback_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID da foto',
    feedback_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to feedback.id',

    photo_url VARCHAR(2083) NOT NULL COMMENT 'URL da foto',
    photo_type VARCHAR(50) NOT NULL COMMENT 'before|after|issue|general',
    room_type VARCHAR(100) NULL COMMENT 'Comodo/area da foto',

    uploaded_by BIGINT UNSIGNED NOT NULL COMMENT 'User ID do cliente',
    sequence_number INT NULL COMMENT 'Ordem na serie de fotos',

    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_feedback_id (feedback_id),
    INDEX idx_photo_type (photo_type),
    INDEX idx_room_type (room_type)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Feedback Photos - Fotos enviadas pelo cliente com o feedback';
