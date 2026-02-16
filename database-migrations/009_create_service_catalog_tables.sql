-- =====================================================
-- Migration 008: Catálogo de Serviços e Adicionais
-- Data: 2026-02-09
-- Autor: LimpVix Core Team
-- Descrição: Tabelas para gerenciar catálogo de serviços
--           (Comercial/Residencial) e adicionais/extras
-- =====================================================

-- 1. Tabela principal de serviços (Comercial/Residencial)
CREATE TABLE IF NOT EXISTS wp_limpvix_service_catalog (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'Código único do serviço',
    category VARCHAR(50) NOT NULL COMMENT 'commercial ou residential',
    service_type VARCHAR(50) NOT NULL COMMENT 'standard, pre_move, post_construction',
    display_name VARCHAR(100) NOT NULL COMMENT 'Nome exibido',
    description TEXT NULL COMMENT 'Descrição do serviço',
    base_price DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Preço base (pode ser 0 se calculado por m²)',
    time_multiplier DECIMAL(4,2) DEFAULT 1.0 COMMENT 'Multiplicador de tempo (1.3 = +30%)',
    requires_multiple_professionals BOOLEAN DEFAULT FALSE COMMENT 'Requer múltiplos profissionais?',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Serviço ativo?',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_category (category),
    INDEX idx_type (service_type),
    INDEX idx_active (is_active),
    INDEX idx_code (service_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Catálogo de serviços principais';

-- 2. Tabela de adicionais/extras
CREATE TABLE IF NOT EXISTS wp_limpvix_service_additionals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    additional_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'Código único do adicional',
    display_name VARCHAR(100) NOT NULL COMMENT 'Nome exibido',
    description TEXT NULL COMMENT 'Descrição do adicional',
    unit_type VARCHAR(20) NOT NULL COMMENT 'fixed, per_m2, per_unit',
    base_price DECIMAL(10,2) NOT NULL COMMENT 'Preço base por unidade',
    estimated_duration_minutes INT DEFAULT 0 COMMENT 'Duração estimada em minutos',
    compatible_with_categories JSON NULL COMMENT '["commercial", "residential"] ou ambos',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Adicional ativo?',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_active (is_active),
    INDEX idx_code (additional_code),
    INDEX idx_unit_type (unit_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Catálogo de adicionais/extras';

-- 3. Relação briefing <-> adicionais (many-to-many)
CREATE TABLE IF NOT EXISTS wp_limpvix_briefing_additionals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    briefing_uuid CHAR(36) NOT NULL COMMENT 'UUID do briefing',
    additional_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do adicional',
    quantity DECIMAL(10,2) DEFAULT 1.00 COMMENT 'Quantidade (pode ser m², unidades, etc)',
    unit_price DECIMAL(10,2) NOT NULL COMMENT 'Preço unitário no momento da seleção',
    total_price DECIMAL(10,2) NOT NULL COMMENT 'Preço total (quantity * unit_price)',
    notes TEXT NULL COMMENT 'Observações sobre este adicional',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_briefing (briefing_uuid),
    INDEX idx_additional (additional_id)

    -- FOREIGN KEY (commented for compatibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Relação briefing <-> adicionais';

-- =====================================================
-- SEED DATA: Serviços Padrão
-- =====================================================

-- Limpeza Comercial
INSERT IGNORE INTO wp_limpvix_service_catalog
(service_code, category, service_type, display_name, description, base_price, time_multiplier, requires_multiple_professionals, is_active)
VALUES
('commercial_standard', 'commercial', 'standard', 'Limpeza Comercial Padrão', 'Limpeza comercial regular com manutenção básica', 0.00, 1.0, FALSE, TRUE),
('commercial_pre_move', 'commercial', 'pre_move', 'Limpeza Comercial Pré-Mudança', 'Limpeza profunda antes de mudança comercial', 0.00, 1.3, FALSE, TRUE),
('commercial_post_construction', 'commercial', 'post_construction', 'Limpeza Comercial Pós-Obra', 'Remoção de resíduos de construção/reforma comercial', 0.00, 1.7, TRUE, TRUE);

-- Limpeza Residencial
INSERT IGNORE INTO wp_limpvix_service_catalog
(service_code, category, service_type, display_name, description, base_price, time_multiplier, requires_multiple_professionals, is_active)
VALUES
('residential_standard', 'residential', 'standard', 'Limpeza Residencial Padrão', 'Limpeza residencial regular com manutenção básica', 0.00, 1.0, FALSE, TRUE),
('residential_pre_move', 'residential', 'pre_move', 'Limpeza Residencial Pré-Mudança', 'Limpeza profunda antes de mudança residencial', 0.00, 1.3, FALSE, TRUE),
('residential_post_construction', 'residential', 'post_construction', 'Limpeza Residencial Pós-Obra', 'Remoção de resíduos de construção/reforma residencial', 0.00, 1.7, TRUE, TRUE);

-- =====================================================
-- SEED DATA: Adicionais Padrão
-- =====================================================

INSERT IGNORE INTO wp_limpvix_service_additionals
(additional_code, display_name, description, unit_type, base_price, estimated_duration_minutes, compatible_with_categories, is_active)
VALUES
-- Adicionais de área/superfície
('ceiling_pvc', 'Limpeza de Teto PVC', 'Limpeza e higienização de forro de PVC', 'per_m2', 5.00, 10, '["commercial", "residential"]', TRUE),
('window_frames', 'Esquadrias e Vidros', 'Limpeza de esquadrias, janelas e vidros', 'per_unit', 15.00, 20, '["commercial", "residential"]', TRUE),
('blinds', 'Limpeza de Persianas', 'Limpeza detalhada de persianas', 'per_unit', 25.00, 30, '["commercial", "residential"]', TRUE),
('curtains', 'Limpeza de Cortinas', 'Limpeza profissional de cortinas', 'per_m2', 8.00, 15, '["residential"]', TRUE),

-- Adicionais de móveis/objetos
('upholstery', 'Higienização de Estofados', 'Higienização profunda de sofás, poltronas, cadeiras', 'per_unit', 80.00, 45, '["commercial", "residential"]', TRUE),
('carpets', 'Limpeza de Carpetes', 'Lavagem profunda de carpetes', 'per_m2', 12.00, 15, '["commercial", "residential"]', TRUE),

-- Adicionais de ambiente
('garden', 'Limpeza de Jardim/Área Externa', 'Limpeza de quintais, varandas, garagens', 'per_m2', 6.00, 20, '["residential"]', TRUE),
('organization', 'Organização de Ambientes', 'Organização e arrumação de ambientes', 'fixed', 100.00, 120, '["residential"]', TRUE),

-- Adicionais especiais
('appliances', 'Limpeza de Eletrodomésticos', 'Limpeza interna de geladeira, fogão, forno', 'per_unit', 35.00, 30, '["residential"]', TRUE),
('cabinets', 'Limpeza de Armários', 'Limpeza interna e externa de armários', 'per_unit', 20.00, 25, '["commercial", "residential"]', TRUE);

-- =====================================================
-- FIM DA MIGRATION 008
-- =====================================================
