-- Migration 025: Add Required Skills to Service Catalog
-- Date: 2026-02-16
-- Purpose: Move service → required_skills mapping from hardcoded PHP to database (GAP D)
-- Dependencies: Requires wp_limpvix_service_catalog table (created in migration 009)

-- Add required_skills column to service_catalog
ALTER TABLE wp_limpvix_service_catalog
ADD COLUMN IF NOT EXISTS required_skills JSON NULL
    COMMENT 'Array de skills necessárias para executar este serviço (ex: ["limpeza_residencial","limpeza_vidros"])';

-- Add index for JSON queries (MySQL 5.7+)
ALTER TABLE wp_limpvix_service_catalog
ADD INDEX IF NOT EXISTS idx_required_skills ((CAST(required_skills AS CHAR(255) ARRAY)));

-- Populate existing services with required skills
-- Based on hardcoded mapping in SendOffers.php (lines 170-184)

-- Residential services
UPDATE wp_limpvix_service_catalog
SET required_skills = JSON_ARRAY('limpeza_residencial')
WHERE service_code = 'residential_standard'
AND required_skills IS NULL;

UPDATE wp_limpvix_service_catalog
SET required_skills = JSON_ARRAY('limpeza_residencial', 'limpeza_pesada')
WHERE service_code = 'residential_pre_move'
AND required_skills IS NULL;

UPDATE wp_limpvix_service_catalog
SET required_skills = JSON_ARRAY('limpeza_residencial', 'limpeza_pesada', 'limpeza_pos_obra')
WHERE service_code = 'residential_post_construction'
AND required_skills IS NULL;

-- Commercial services
UPDATE wp_limpvix_service_catalog
SET required_skills = JSON_ARRAY('limpeza_comercial')
WHERE service_code = 'commercial_standard'
AND required_skills IS NULL;

UPDATE wp_limpvix_service_catalog
SET required_skills = JSON_ARRAY('limpeza_comercial', 'manutencao_piso')
WHERE service_code = 'commercial_pre_move'
AND required_skills IS NULL;

UPDATE wp_limpvix_service_catalog
SET required_skills = JSON_ARRAY('limpeza_comercial', 'manutencao_piso', 'limpeza_pos_obra')
WHERE service_code = 'commercial_post_construction'
AND required_skills IS NULL;

-- Add comment to table for documentation
ALTER TABLE wp_limpvix_service_catalog
COMMENT = 'Catálogo de serviços com skills requeridas (GAP D - database-driven mapping)';
