-- =====================================================
-- Migration 042: Create Execution Evidence Table
--
-- Fotos e videos de check-in/check-out/execucao
-- Cada evidencia vinculada a uma execution e categorizada
-- por tipo (photo/video), categoria e comodo
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_execution_evidence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID da evidencia',
    execution_uuid VARCHAR(36) NOT NULL COMMENT 'FK to executions.execution_uuid',

    type VARCHAR(20) NOT NULL COMMENT 'photo|video',
    category VARCHAR(50) NOT NULL COMMENT 'epi_checkin|epi_checkout|location|room|issue',
    stage VARCHAR(50) NOT NULL COMMENT 'check_in|execution|check_out',

    url VARCHAR(2083) NOT NULL COMMENT 'URL da midia (foto ou video)',
    room_type VARCHAR(100) NULL COMMENT 'Tipo do comodo: quarto_1, banheiro_1, sala, cozinha, etc',

    uploaded_by BIGINT UNSIGNED NULL COMMENT 'User ID do profissional ou cliente',
    uploaded_by_role VARCHAR(50) NULL COMMENT 'professional|customer',
    status VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',

    captured_at DATETIME NOT NULL COMMENT 'Data/hora da captura da evidencia',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_execution_uuid (execution_uuid),
    INDEX idx_category (category),
    INDEX idx_stage (stage),
    INDEX idx_status (status),
    INDEX idx_room_type (room_type)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Execution Evidence - Fotos/videos de check-in, execucao e check-out';
