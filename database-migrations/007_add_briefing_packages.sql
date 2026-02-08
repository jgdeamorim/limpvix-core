-- ========================================
-- MIGRATION 007: Add Package Support to Briefings
-- ========================================
-- Data: 2026-02-08
-- Descrição: Adicionar suporte a pacotes (basic, standard, premium) em Briefings
-- Referência: FASE 1 - Packages Implementation
-- ========================================

-- 1. Adicionar colunas de package na tabela principal
ALTER TABLE wp_limpvix_briefings
    ADD COLUMN package_type VARCHAR(50) NULL COMMENT 'Tipo de pacote: basic, standard, premium' AFTER estimated_duration_minutes,
    ADD COLUMN package_percentage DECIMAL(5,2) NULL COMMENT 'Percentual de aumento do pacote (0.15 = 15%)' AFTER package_type,
    ADD COLUMN required_professionals_count INT DEFAULT 1 COMMENT 'Quantidade de profissionais necessários' AFTER package_percentage;

-- 2. Criar índice para busca por package_type
ALTER TABLE wp_limpvix_briefings
    ADD INDEX idx_package_type (package_type);

-- 3. Criar tabela de configuração de pacotes (para admin management)
CREATE TABLE IF NOT EXISTS wp_limpvix_package_configs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_type VARCHAR(50) NOT NULL COMMENT 'basic, standard, premium',
    display_name VARCHAR(100) NOT NULL COMMENT 'Nome exibido',
    description TEXT NULL COMMENT 'Descrição do pacote',
    percentage_increase DECIMAL(5,2) NOT NULL DEFAULT 0.0 COMMENT 'Percentual de aumento (0.15 = 15%)',
    min_professionals INT NOT NULL DEFAULT 1 COMMENT 'Mínimo de profissionais',
    max_professionals INT NOT NULL DEFAULT 1 COMMENT 'Máximo de profissionais',
    required_skills JSON NOT NULL COMMENT 'Skills requeridas: ["limpeza_basica", ...]',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Pacote ativo?',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_package_type (package_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Configuração de pacotes de serviço';

-- 4. Inserir pacotes padrão
INSERT INTO wp_limpvix_package_configs (
    package_type, display_name, description, percentage_increase,
    min_professionals, max_professionals, required_skills, is_active
) VALUES
(
    'basic',
    'Básico',
    'Limpeza básica padrão. Ideal para manutenção regular.',
    0.0,
    1,
    1,
    '["limpeza_basica"]',
    TRUE
),
(
    'standard',
    'Padrão',
    'Limpeza completa com detalhes. +15% no preço.',
    0.15,
    1,
    2,
    '["limpeza_basica", "limpeza_completa"]',
    TRUE
),
(
    'premium',
    'Premium',
    'Limpeza pesada/pós-obra. Múltiplos profissionais. +30% no preço.',
    0.30,
    2,
    3,
    '["limpeza_basica", "limpeza_completa", "limpeza_pesada", "pos_obra"]',
    TRUE
);

-- 5. Atualizar briefings existentes com package_type padrão (basic)
UPDATE wp_limpvix_briefings
SET
    package_type = 'basic',
    package_percentage = 0.0,
    required_professionals_count = 1
WHERE package_type IS NULL;

-- ========================================
-- ROLLBACK (caso necessário)
-- ========================================
-- ALTER TABLE wp_limpvix_briefings
--     DROP COLUMN package_type,
--     DROP COLUMN package_percentage,
--     DROP COLUMN required_professionals_count,
--     DROP INDEX idx_package_type;
-- DROP TABLE IF EXISTS wp_limpvix_package_configs;
-- ========================================
