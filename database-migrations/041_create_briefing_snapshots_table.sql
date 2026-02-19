-- =====================================================
-- Migration 041: Create Briefing Snapshots Table
--
-- Snapshots imutaveis do estado do briefing em cada transicao
-- Auditoria e rastreabilidade de mudancas
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_briefing_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID do snapshot',
    briefing_uuid VARCHAR(36) NOT NULL COMMENT 'FK to briefings.uuid',

    snapshot_version INT NOT NULL COMMENT 'Numero sequencial do snapshot',
    status VARCHAR(50) NOT NULL COMMENT 'Status do briefing no momento do snapshot',
    property_type VARCHAR(20) NULL,
    estimated_m2 DECIMAL(10,2) NULL,
    estimated_duration_minutes INT NULL,

    state_data LONGTEXT NOT NULL COMMENT 'Estado completo serializado como JSON',
    change_reason VARCHAR(255) NULL COMMENT 'Motivo da criacao do snapshot',
    triggered_by BIGINT UNSIGNED NULL COMMENT 'User ID que disparou',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_briefing_uuid (briefing_uuid),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Briefing Snapshots - Estado imutavel do briefing em cada transicao';
