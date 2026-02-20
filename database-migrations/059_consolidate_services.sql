-- Migration 059: Consolidate 6 services → 2
-- FASE 9A — Eliminar duplicação service_type ↔ complexity.slug
--
-- ANTES: 6 linhas (residential_standard, residential_pre_move, residential_post_construction,
--                   commercial_standard, commercial_pre_move, commercial_post_construction)
-- DEPOIS: 2 linhas (residential_cleaning, commercial_cleaning)
--         cada uma com 3 complexidades (standard, detailed, post_construction)
--
-- Strategy: Rename _standard rows (preserves IDs + FK references),
-- then reassign complexities from other 4 services and delete them.

-- Step 0: Make service_type nullable FIRST (needed before setting NULL in steps 1-2)
ALTER TABLE wp_limpvix_service_catalog MODIFY COLUMN service_type VARCHAR(50) NULL;

-- Step 1: Rename residential_standard → residential_cleaning
UPDATE wp_limpvix_service_catalog
SET service_code = 'residential_cleaning',
    display_name = 'Limpeza Residencial',
    description = 'Serviço completo de limpeza residencial — complexidade define dificuldade',
    service_type = NULL,
    updated_at = NOW()
WHERE service_code = 'residential_standard';

-- Step 2: Rename commercial_standard → commercial_cleaning
UPDATE wp_limpvix_service_catalog
SET service_code = 'commercial_cleaning',
    display_name = 'Limpeza Comercial',
    description = 'Serviço completo de limpeza comercial — complexidade define dificuldade',
    service_type = NULL,
    updated_at = NOW()
WHERE service_code = 'commercial_standard';

-- Step 3: Reassign residential complexities to residential_cleaning
UPDATE wp_limpvix_service_complexities
SET service_id = (SELECT id FROM wp_limpvix_service_catalog WHERE service_code = 'residential_cleaning')
WHERE service_id IN (
    SELECT id FROM wp_limpvix_service_catalog
    WHERE service_code IN ('residential_pre_move', 'residential_post_construction')
);

-- Step 4: Reassign commercial complexities to commercial_cleaning
UPDATE wp_limpvix_service_complexities
SET service_id = (SELECT id FROM wp_limpvix_service_catalog WHERE service_code = 'commercial_cleaning')
WHERE service_id IN (
    SELECT id FROM wp_limpvix_service_catalog
    WHERE service_code IN ('commercial_pre_move', 'commercial_post_construction')
);

-- Step 5: Update contracts to new service_codes
UPDATE wp_limpvix_contracts
SET service_code = 'residential_cleaning'
WHERE service_code IN ('residential_standard', 'residential_pre_move', 'residential_post_construction', 'CLEAN_RESIDENTIAL');

UPDATE wp_limpvix_contracts
SET service_code = 'commercial_cleaning'
WHERE service_code IN ('commercial_standard', 'commercial_pre_move', 'commercial_post_construction');

-- Step 6: Delete 4 old services (complexities already reassigned in steps 3-4)
DELETE FROM wp_limpvix_service_catalog
WHERE service_code IN (
    'residential_pre_move', 'residential_post_construction',
    'commercial_pre_move', 'commercial_post_construction'
);

-- (service_type already made nullable in Step 0)
