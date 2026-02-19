-- =====================================================
-- Migration 040: Create Briefing Packages Table
--
-- Pacote selecionado por briefing (basico/padrao/premium)
-- Vinculo entre briefing e package_configs
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_briefing_packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID do registro',
    briefing_uuid VARCHAR(36) NOT NULL UNIQUE COMMENT 'FK to briefings.uuid (1:1)',

    package_type VARCHAR(50) NOT NULL COMMENT 'basic|standard|premium',
    display_name VARCHAR(100) NOT NULL,
    percentage_increase DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '0.00, 0.15, 0.30',
    min_professionals INT NOT NULL DEFAULT 1,
    max_professionals INT NOT NULL DEFAULT 1,
    required_skills JSON NULL COMMENT 'Array de skill codes requeridos',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_briefing_uuid (briefing_uuid),
    INDEX idx_package_type (package_type)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Briefing Packages - Pacote selecionado no Step 5 do briefing';
