-- =====================================================
-- Migration 050: Create Service Required Skills Table
--
-- Mapeamento de skills requeridas por servico
-- Usado pelo ProfessionalMatcher para filtrar candidatos
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_service_required_skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID do registro',
    service_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to service_catalog.id',

    skill_code VARCHAR(100) NOT NULL COMMENT 'limpeza_residencial|limpeza_comercial|limpeza_pesada|pos_obra|etc',
    skill_name VARCHAR(255) NOT NULL COMMENT 'Nome amigavel da skill',

    is_mandatory TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=obrigatorio, 0=desejavel',
    proficiency_level VARCHAR(50) NULL COMMENT 'basic|intermediate|advanced',
    requires_certification TINYINT(1) NOT NULL DEFAULT 0,
    certification_type VARCHAR(100) NULL COMMENT 'Tipo de certificacao necessaria',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_service_skill (service_id, skill_code),
    INDEX idx_service_id (service_id),
    INDEX idx_skill_code (skill_code),
    INDEX idx_is_mandatory (is_mandatory)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Service Required Skills - Skills requeridas por tipo de servico para matching';
