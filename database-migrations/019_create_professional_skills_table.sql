-- ============================================================================
-- Migration 019: Professional Skills - Relação Profissional ↔ Serviços
-- ============================================================================
-- Data: 2026-02-11
-- Descrição: Cria tabela de relação entre profissionais e serviços do catálogo
--            Permite indicar se profissional possui certificado para cada skill
-- ============================================================================

-- Tabela de Skills do Profissional (many-to-many)
CREATE TABLE IF NOT EXISTS wp_limpvix_professional_skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Relação
    professional_id BIGINT UNSIGNED NOT NULL COMMENT 'FK para wp_limpvix_professionals.id',
    service_id BIGINT UNSIGNED NOT NULL COMMENT 'FK para wp_limpvix_service_catalog.id',

    -- Certificação
    has_certification BOOLEAN DEFAULT FALSE COMMENT 'Possui certificado para este serviço?',
    certification_number VARCHAR(100) NULL COMMENT 'Número do certificado (se aplicável)',
    certification_issuer VARCHAR(255) NULL COMMENT 'Órgão emissor do certificado',
    certification_expires_at DATE NULL COMMENT 'Data de validade do certificado',

    -- Status
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Skill ativa para o profissional?',
    verified_by BIGINT UNSIGNED NULL COMMENT 'Admin que verificou o certificado',
    verified_at DATETIME NULL COMMENT 'Quando foi verificado',

    -- Experiência
    years_of_experience INT DEFAULT 0 COMMENT 'Anos de experiência neste serviço',
    total_services_completed INT DEFAULT 0 COMMENT 'Total de serviços deste tipo concluídos',

    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_professional (professional_id),
    INDEX idx_service (service_id),
    INDEX idx_active (is_active),
    INDEX idx_has_cert (has_certification),
    UNIQUE KEY unique_professional_service (professional_id, service_id) COMMENT 'Um profissional não pode ter a mesma skill duplicada'

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT 'Relação entre profissionais e serviços do catálogo (skills)';

-- ============================================================================
-- FIM DA MIGRATION 019
-- ============================================================================
