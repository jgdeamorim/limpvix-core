-- =====================================================
-- Rollback 006: Briefing Tables
--
-- Remove as 3 tabelas do módulo Briefing
--
-- @version 0.2.0
-- @since 2026-02-06
-- =====================================================

-- Desabilitar checagem de foreign keys temporariamente
SET FOREIGN_KEY_CHECKS = 0;

-- Remover triggers
DROP TRIGGER IF EXISTS before_update_briefings;
DROP TRIGGER IF EXISTS before_update_briefing_data;

-- Remover tabelas (ordem reversa devido a foreign keys)
DROP TABLE IF EXISTS wp_limpvix_briefing_ledger;
DROP TABLE IF EXISTS wp_limpvix_briefing_data;
DROP TABLE IF EXISTS wp_limpvix_briefings;

-- Reabilitar checagem de foreign keys
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- Verificação
-- =====================================================
-- SELECT 'Rollback 006 executado com sucesso!' as status;
