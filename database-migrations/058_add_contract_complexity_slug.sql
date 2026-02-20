-- Migration 058: Add complexity_slug to contracts
-- FASE 9A — Contratos precisam saber qual complexidade foi contratada
-- (antes derivava do service_code 1:1, agora 1:N após consolidação)

ALTER TABLE wp_limpvix_contracts
  ADD COLUMN complexity_slug VARCHAR(50) NULL AFTER service_code;

-- Backfill: todos contratos existentes são 'standard'
UPDATE wp_limpvix_contracts
SET complexity_slug = 'standard'
WHERE complexity_slug IS NULL;
