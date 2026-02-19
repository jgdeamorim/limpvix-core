-- =====================================================
-- Migration 043: Create Execution Issues Table
--
-- Problemas reportados durante execucao do servico
-- Profissional ou cliente podem reportar
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_execution_issues (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID do issue',
    execution_uuid VARCHAR(36) NOT NULL COMMENT 'FK to executions.execution_uuid',

    issue_type VARCHAR(100) NOT NULL COMMENT 'equipment_malfunction|property_damage|safety_hazard|client_complaint|other',
    severity VARCHAR(50) NOT NULL COMMENT 'low|medium|high|critical',
    description TEXT NOT NULL,
    room_type VARCHAR(100) NULL COMMENT 'Comodo/area afetada',

    reported_by BIGINT UNSIGNED NOT NULL COMMENT 'User ID de quem reportou',
    reported_by_role VARCHAR(50) NOT NULL COMMENT 'professional|customer|admin',
    photo_urls JSON NULL COMMENT 'Array de URLs de fotos do problema',

    status VARCHAR(50) NOT NULL DEFAULT 'open' COMMENT 'open|in_progress|resolved|disputed',
    resolution_notes TEXT NULL,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,

    reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_execution_uuid (execution_uuid),
    INDEX idx_severity (severity),
    INDEX idx_status (status),
    INDEX idx_reported_at (reported_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Execution Issues - Problemas reportados durante execucao';
