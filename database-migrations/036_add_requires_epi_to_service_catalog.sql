-- P1.5: Link EPI requirements to service catalog
-- Adds requires_epi flag and required_epis JSON to service catalog

ALTER TABLE wp_limpvix_service_catalog
    ADD COLUMN requires_epi TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN required_epis JSON DEFAULT NULL COMMENT 'EPI slugs required for this service type' AFTER requires_epi;

-- Update service types that require EPI
UPDATE wp_limpvix_service_catalog
SET requires_epi = 1, required_epis = '["luvas","botas","mascara","oculos","avental"]'
WHERE service_type IN ('post_construction');

UPDATE wp_limpvix_service_catalog
SET requires_epi = 1, required_epis = '["luvas","botas","mascara","avental"]'
WHERE service_type IN ('pre_move');

UPDATE wp_limpvix_service_catalog
SET requires_epi = 1, required_epis = '["luvas"]'
WHERE service_type IN ('standard') AND requires_epi = 0;
