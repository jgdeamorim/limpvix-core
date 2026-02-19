-- P0.4: Add geographic index columns to briefings table
-- For IBGE-based pricing adjustments

ALTER TABLE wp_limpvix_briefings ADD COLUMN IF NOT EXISTS geo_index DECIMAL(4,3) DEFAULT NULL;
ALTER TABLE wp_limpvix_briefings ADD COLUMN IF NOT EXISTS geo_classification VARCHAR(20) DEFAULT NULL;
ALTER TABLE wp_limpvix_briefings ADD COLUMN IF NOT EXISTS geo_multiplier DECIMAL(4,3) DEFAULT NULL;
