-- =====================================================
-- Migration 052: Create Capabilities Table (SSOT)
--
-- Tabela centralizada de competencias tecnicas.
-- SSOT (Single Source of Truth) para todas as skills
-- do sistema — substitui listas hardcoded em PHP.
--
-- Referenciada por:
--   - complexity_capabilities (junction)
--   - additional_capabilities (junction)
--   - professional matching
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_capabilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE COMMENT 'Identificador unico da capability',
    display_name VARCHAR(255) NOT NULL COMMENT 'Nome amigavel para UI',
    description TEXT NULL COMMENT 'Descricao detalhada da competencia',
    is_active TINYINT(1) DEFAULT 1 COMMENT '1=ativa, 0=desativada',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_slug (slug),
    INDEX idx_active (is_active)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Capabilities SSOT - Competencias tecnicas reais dos profissionais';

-- =====================================================
-- SEED DATA: Competencias tecnicas reais
-- =====================================================

INSERT IGNORE INTO wp_limpvix_capabilities (slug, display_name, description) VALUES
('cleaning_basic', 'Limpeza Basica', 'Limpeza padrao de ambientes internos'),
('cleaning_deep', 'Limpeza Profunda', 'Limpeza detalhada com atencao a cantos e detalhes'),
('cleaning_post_construction', 'Limpeza Pos-Obra', 'Remocao de residuos de construcao e reforma'),
('cleaning_pre_move', 'Limpeza Pre-Mudanca', 'Limpeza completa antes de mudanca'),
('window_cleaning', 'Limpeza de Vidros/Esquadrias', 'Limpeza de janelas, vidros e esquadrias'),
('ceiling_cleaning', 'Limpeza de Teto', 'Limpeza de forros PVC, gesso e tetos'),
('carpet_cleaning', 'Limpeza de Carpetes', 'Lavagem e higienizacao de carpetes'),
('upholstery_cleaning', 'Higienizacao de Estofados', 'Higienizacao de sofas, poltronas e cadeiras'),
('floor_polishing', 'Manutencao/Polimento de Piso', 'Polimento e manutencao de pisos'),
('curtain_cleaning', 'Limpeza de Cortinas/Persianas', 'Limpeza de cortinas e persianas'),
('garden_cleaning', 'Limpeza de Jardim/Area Externa', 'Limpeza de quintais, varandas e garagens'),
('appliance_cleaning', 'Limpeza de Eletrodomesticos', 'Limpeza interna de geladeira, fogao, forno'),
('cabinet_cleaning', 'Limpeza de Armarios', 'Limpeza interna e externa de armarios'),
('sanitization', 'Sanitizacao', 'Desinfeccao e sanitizacao de ambientes'),
('organization', 'Organizacao de Ambientes', 'Organizacao e arrumacao de espacos'),
('industrial_kitchen', 'Cozinha Industrial', 'Limpeza de cozinhas industriais e comerciais'),
('pool_cleaning', 'Limpeza de Piscinas', 'Limpeza e manutencao de piscinas');

-- =====================================================
-- FIM DA MIGRATION 052
-- =====================================================
