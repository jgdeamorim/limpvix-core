-- ========================================
-- MIGRATION 008: Add Complexity Tracking to Briefings
-- ========================================
-- Data: 2026-02-08
-- Descrição: Adicionar suporte a complexity tracking (simple, medium, complex)
-- Referência: FASE 2 - Complexity Tracking Implementation
-- ========================================

-- 1. Adicionar colunas de complexity na tabela principal
ALTER TABLE wp_limpvix_briefings
    ADD COLUMN complexity_level VARCHAR(20) NULL COMMENT 'Nível de complexidade: simple, medium, complex' AFTER required_professionals_count,
    ADD COLUMN complexity_multiplier DECIMAL(4,2) NULL COMMENT 'Multiplier de complexidade (1.0, 1.3, 1.5)' AFTER complexity_level;

-- 2. Criar índice para busca por complexity_level
ALTER TABLE wp_limpvix_briefings
    ADD INDEX idx_complexity_level (complexity_level);

-- 3. Atualizar briefings existentes com complexity_level padrão (simple)
UPDATE wp_limpvix_briefings
SET
    complexity_level = 'simple',
    complexity_multiplier = 1.0
WHERE complexity_level IS NULL;

-- ========================================
-- ROLLBACK (caso necessário)
-- ========================================
-- ALTER TABLE wp_limpvix_briefings
--     DROP COLUMN complexity_level,
--     DROP COLUMN complexity_multiplier,
--     DROP INDEX idx_complexity_level;
-- ========================================
