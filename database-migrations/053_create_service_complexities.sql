-- =====================================================
-- Migration 053: Create Service Complexities Table
--
-- Complexidade tecnica por servico.
-- Cada servico pode ter N complexidades com diferentes
-- multiplicadores de tempo e capabilities requeridas.
--
-- Substitui o campo service_type como indicador de
-- escopo tecnico — agora e uma entidade propria.
--
-- Referenciada por:
--   - complexity_capabilities (junction → capabilities)
--   - briefings (complexity_id)
--   - PricingEngine (time_multiplier)
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_service_complexities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_service_catalog.id',
    slug VARCHAR(50) NOT NULL COMMENT 'standard|detailed|post_construction',
    display_name VARCHAR(100) NOT NULL COMMENT 'Nome amigavel para UI',
    description TEXT NULL COMMENT 'Descricao do nivel de complexidade',
    time_multiplier DECIMAL(4,2) DEFAULT 1.00 COMMENT 'Multiplicador de tempo (1.00=base, 1.30=+30%)',
    is_active TINYINT(1) DEFAULT 1 COMMENT '1=ativa, 0=desativada',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_service_complexity (service_id, slug),
    INDEX idx_service (service_id),
    INDEX idx_slug (slug),
    INDEX idx_active (is_active)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Service Complexities - Niveis de complexidade tecnica por servico';

-- =====================================================
-- SEED DATA: Complexidades mapeadas dos service_types
--
-- Mapeia cada service_type existente para uma complexity:
--   standard         → slug 'standard'    (mult 1.00)
--   pre_move         → slug 'detailed'    (mult 1.30)
--   post_construction → slug 'post_construction' (mult 1.80)
-- =====================================================

-- Servicos standard → complexidade 'standard'
INSERT IGNORE INTO wp_limpvix_service_complexities (service_id, slug, display_name, description, time_multiplier)
SELECT id, 'standard', 'Padrao', 'Limpeza padrao com escopo basico', 1.00
FROM wp_limpvix_service_catalog
WHERE service_type = 'standard';

-- Servicos pre_move → complexidade 'detailed'
INSERT IGNORE INTO wp_limpvix_service_complexities (service_id, slug, display_name, description, time_multiplier)
SELECT id, 'detailed', 'Detalhada', 'Limpeza detalhada com escopo ampliado', 1.30
FROM wp_limpvix_service_catalog
WHERE service_type = 'pre_move';

-- Servicos post_construction → complexidade 'post_construction'
INSERT IGNORE INTO wp_limpvix_service_complexities (service_id, slug, display_name, description, time_multiplier)
SELECT id, 'post_construction', 'Pos-Obra', 'Limpeza pesada com remocao de residuos de obra', 1.80
FROM wp_limpvix_service_catalog
WHERE service_type = 'post_construction';

-- =====================================================
-- FIM DA MIGRATION 053
-- =====================================================
