-- =====================================================
-- Migration 056: Create Additional Capabilities Junction
--
-- Tabela junction que vincula adicionais a capabilities.
-- Quando um cliente seleciona um adicional no briefing,
-- as capabilities correspondentes sao adicionadas ao
-- conjunto de competencias requeridas para o match.
--
-- Formula de match:
--   required = complexity.capabilities
--            + SUM(additional.capabilities)
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_additional_capabilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    additional_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_service_additionals.id',
    capability_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_capabilities.id',

    UNIQUE KEY unique_additional_capability (additional_id, capability_id),
    INDEX idx_additional (additional_id),
    INDEX idx_capability (capability_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Junction: Additional → Capabilities requeridas';

-- =====================================================
-- SEED DATA: Capabilities por adicional
--
-- Mapeamento baseado nos adicionais existentes (migration 009):
--   ceiling_pvc    → ceiling_cleaning
--   window_frames  → window_cleaning
--   blinds         → curtain_cleaning
--   curtains       → curtain_cleaning
--   upholstery     → upholstery_cleaning
--   carpets        → carpet_cleaning
--   garden         → garden_cleaning
--   organization   → organization
--   appliances     → appliance_cleaning
--   cabinets       → cabinet_cleaning
-- =====================================================

-- ceiling_pvc → ceiling_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'ceiling_cleaning'
WHERE a.additional_code = 'ceiling_pvc';

-- window_frames → window_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'window_cleaning'
WHERE a.additional_code = 'window_frames';

-- blinds → curtain_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'curtain_cleaning'
WHERE a.additional_code = 'blinds';

-- curtains → curtain_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'curtain_cleaning'
WHERE a.additional_code = 'curtains';

-- upholstery → upholstery_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'upholstery_cleaning'
WHERE a.additional_code = 'upholstery';

-- carpets → carpet_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'carpet_cleaning'
WHERE a.additional_code = 'carpets';

-- garden → garden_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'garden_cleaning'
WHERE a.additional_code = 'garden';

-- organization → organization
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'organization'
WHERE a.additional_code = 'organization';

-- appliances → appliance_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'appliance_cleaning'
WHERE a.additional_code = 'appliances';

-- cabinets → cabinet_cleaning
INSERT IGNORE INTO wp_limpvix_additional_capabilities (additional_id, capability_id)
SELECT a.id, cap.id
FROM wp_limpvix_service_additionals a
JOIN wp_limpvix_capabilities cap ON cap.slug = 'cabinet_cleaning'
WHERE a.additional_code = 'cabinets';

-- =====================================================
-- FIM DA MIGRATION 056
-- =====================================================
