-- Migration 037: Convert PropertyStructure booleans to integer counts
-- G-PROPERTY-STRUCTURE-COUNTS: Support multiple rooms per type
-- Date: 2026-02-19

-- Add new count columns to briefings table (if stored in DB)
-- PropertyStructure is typically stored as JSON in briefings.property_structure column
-- This migration ensures any direct column references are updated

-- Add balcony_count and garage_count to briefings if structure is stored as columns
ALTER TABLE `{prefix}limpvix_briefings`
    ADD COLUMN IF NOT EXISTS `living_room_count` INT NOT NULL DEFAULT 0 AFTER `bathrooms`,
    ADD COLUMN IF NOT EXISTS `kitchen_count` INT NOT NULL DEFAULT 0 AFTER `living_room_count`,
    ADD COLUMN IF NOT EXISTS `office_count` INT NOT NULL DEFAULT 0 AFTER `kitchen_count`,
    ADD COLUMN IF NOT EXISTS `external_area_count` INT NOT NULL DEFAULT 0 AFTER `office_count`,
    ADD COLUMN IF NOT EXISTS `balcony_count` INT NOT NULL DEFAULT 0 AFTER `external_area_count`,
    ADD COLUMN IF NOT EXISTS `garage_count` INT NOT NULL DEFAULT 0 AFTER `balcony_count`;

-- Migrate existing boolean data to counts (1 if true, 0 if false)
UPDATE `{prefix}limpvix_briefings`
SET
    `living_room_count` = CASE WHEN `has_living_room` = 1 THEN 1 ELSE 0 END,
    `kitchen_count` = CASE WHEN `has_kitchen` = 1 THEN 1 ELSE 0 END,
    `office_count` = CASE WHEN `has_office` = 1 THEN 1 ELSE 0 END,
    `external_area_count` = CASE WHEN `has_external_area` = 1 THEN 1 ELSE 0 END
WHERE `living_room_count` = 0
  AND (`has_living_room` = 1 OR `has_kitchen` = 1 OR `has_office` = 1 OR `has_external_area` = 1);

-- Add geofence enforcement options
INSERT IGNORE INTO `{prefix}options` (`option_name`, `option_value`, `autoload`)
VALUES
    ('limpvix_enforce_geofence_checkin', '1', 'yes'),
    ('limpvix_enforce_geofence_checkout', '1', 'yes'),
    ('limpvix_enforce_room_photos', '1', 'yes');
