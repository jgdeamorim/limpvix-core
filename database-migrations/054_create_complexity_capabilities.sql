-- =====================================================
-- Migration 054: Create Complexity Capabilities Junction
--
-- Tabela junction que vincula complexidades a capabilities.
-- Define quais competencias sao necessarias para cada
-- nivel de complexidade de um servico.
--
-- Formula de match:
--   required = complexity.capabilities
--            + SUM(additional.capabilities)
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_complexity_capabilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complexity_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_service_complexities.id',
    capability_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_capabilities.id',

    UNIQUE KEY unique_complexity_capability (complexity_id, capability_id),
    INDEX idx_complexity (complexity_id),
    INDEX idx_capability (capability_id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Junction: Complexity → Capabilities requeridas';

-- =====================================================
-- SEED DATA: Capabilities por complexidade
--
-- standard         → cleaning_basic
-- detailed         → cleaning_basic + cleaning_deep + window_cleaning
-- post_construction → cleaning_basic + cleaning_deep + cleaning_post_construction
-- =====================================================

-- Complexidade 'standard' → cleaning_basic
INSERT IGNORE INTO wp_limpvix_complexity_capabilities (complexity_id, capability_id)
SELECT sc.id, cap.id
FROM wp_limpvix_service_complexities sc
JOIN wp_limpvix_capabilities cap ON cap.slug = 'cleaning_basic'
WHERE sc.slug = 'standard';

-- Complexidade 'detailed' → cleaning_basic
INSERT IGNORE INTO wp_limpvix_complexity_capabilities (complexity_id, capability_id)
SELECT sc.id, cap.id
FROM wp_limpvix_service_complexities sc
JOIN wp_limpvix_capabilities cap ON cap.slug = 'cleaning_basic'
WHERE sc.slug = 'detailed';

-- Complexidade 'detailed' → cleaning_deep
INSERT IGNORE INTO wp_limpvix_complexity_capabilities (complexity_id, capability_id)
SELECT sc.id, cap.id
FROM wp_limpvix_service_complexities sc
JOIN wp_limpvix_capabilities cap ON cap.slug = 'cleaning_deep'
WHERE sc.slug = 'detailed';

-- Complexidade 'detailed' → window_cleaning
INSERT IGNORE INTO wp_limpvix_complexity_capabilities (complexity_id, capability_id)
SELECT sc.id, cap.id
FROM wp_limpvix_service_complexities sc
JOIN wp_limpvix_capabilities cap ON cap.slug = 'window_cleaning'
WHERE sc.slug = 'detailed';

-- Complexidade 'post_construction' → cleaning_basic
INSERT IGNORE INTO wp_limpvix_complexity_capabilities (complexity_id, capability_id)
SELECT sc.id, cap.id
FROM wp_limpvix_service_complexities sc
JOIN wp_limpvix_capabilities cap ON cap.slug = 'cleaning_basic'
WHERE sc.slug = 'post_construction';

-- Complexidade 'post_construction' → cleaning_deep
INSERT IGNORE INTO wp_limpvix_complexity_capabilities (complexity_id, capability_id)
SELECT sc.id, cap.id
FROM wp_limpvix_service_complexities sc
JOIN wp_limpvix_capabilities cap ON cap.slug = 'cleaning_deep'
WHERE sc.slug = 'post_construction';

-- Complexidade 'post_construction' → cleaning_post_construction
INSERT IGNORE INTO wp_limpvix_complexity_capabilities (complexity_id, capability_id)
SELECT sc.id, cap.id
FROM wp_limpvix_service_complexities sc
JOIN wp_limpvix_capabilities cap ON cap.slug = 'cleaning_post_construction'
WHERE sc.slug = 'post_construction';

-- =====================================================
-- FIM DA MIGRATION 054
-- =====================================================
