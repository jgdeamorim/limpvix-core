-- =====================================================
-- Migration 055: Create Execution Levels Table
--
-- Niveis de execucao operacional (substitui package_configs).
-- Define COMO o servico sera executado, nao O QUE sera feito.
--
-- ExecutionLevel NAO possui capabilities — nao participa
-- do match tecnico. Apenas define:
--   - Multiplicador de preco
--   - Tamanho da equipe (min/max)
--   - Nivel de checklist
--   - Horas de garantia
--
-- Substitui: Package.php, PackageType.php, package_configs
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_execution_levels (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE COMMENT 'basic_execution|standard_execution|premium_execution',
    display_name VARCHAR(100) NOT NULL COMMENT 'Nome amigavel para UI',
    description TEXT NULL COMMENT 'Descricao do nivel de execucao',
    price_multiplier DECIMAL(5,2) DEFAULT 1.00 COMMENT 'Multiplicador de preco (1.00, 1.15, 1.30)',
    team_min INT DEFAULT 1 COMMENT 'Numero minimo de profissionais',
    team_max INT DEFAULT 1 COMMENT 'Numero maximo de profissionais',
    checklist_level VARCHAR(50) DEFAULT 'basic' COMMENT 'basic|detailed|complete',
    warranty_hours INT DEFAULT 0 COMMENT 'Horas de garantia pos-servico',
    is_active TINYINT(1) DEFAULT 1 COMMENT '1=ativo, 0=desativado',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_slug (slug),
    INDEX idx_active (is_active)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Execution Levels - Niveis de execucao operacional (substitui packages)';

-- =====================================================
-- SEED DATA: 3 niveis de execucao
--
-- Mapeamento do legado:
--   basic   (0%)  → basic_execution    (1.00x)
--   standard (15%) → standard_execution (1.15x)
--   premium  (30%) → premium_execution  (1.30x)
-- =====================================================

INSERT IGNORE INTO wp_limpvix_execution_levels
(slug, display_name, description, price_multiplier, team_min, team_max, checklist_level, warranty_hours)
VALUES
('basic_execution', 'Execucao Basica', 'Execucao padrao com 1 profissional e checklist basico', 1.00, 1, 1, 'basic', 0),
('standard_execution', 'Execucao Padrao', 'Execucao com possibilidade de 2 profissionais, checklist detalhado e garantia 12h', 1.15, 1, 2, 'detailed', 12),
('premium_execution', 'Execucao Premium', 'Equipe de 2-3 profissionais, checklist completo e garantia 24h', 1.30, 2, 3, 'complete', 24);

-- =====================================================
-- FIM DA MIGRATION 055
-- =====================================================
