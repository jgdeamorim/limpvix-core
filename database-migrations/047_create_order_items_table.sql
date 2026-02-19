-- =====================================================
-- Migration 047: Create Order Items Table
--
-- Itens individuais de cada pedido (servico + adicionais)
-- Decomposicao do total do pedido por item
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_order_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID do item',
    order_uuid VARCHAR(36) NOT NULL COMMENT 'FK to orders.uuid',

    service_id BIGINT UNSIGNED NULL COMMENT 'FK to service_catalog.id (NULL se adicional avulso)',
    service_code VARCHAR(100) NOT NULL COMMENT 'Codigo do servico ou adicional',
    service_name VARCHAR(255) NOT NULL COMMENT 'Nome exibido',
    item_type VARCHAR(50) NOT NULL DEFAULT 'service' COMMENT 'service|additional|package_fee|geo_adjustment',

    unit_price DECIMAL(10,2) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Quantidade (pode ser m2 ou unidades)',
    unit_type VARCHAR(20) NOT NULL DEFAULT 'unit' COMMENT 'unit|m2|fixed',
    subtotal DECIMAL(10,2) NOT NULL COMMENT 'unit_price * quantity',
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,

    metadata JSON NULL COMMENT 'Dados adicionais do item',
    status VARCHAR(50) NOT NULL DEFAULT 'confirmed' COMMENT 'confirmed|cancelled|refunded',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_order_uuid (order_uuid),
    INDEX idx_service_id (service_id),
    INDEX idx_item_type (item_type),
    INDEX idx_status (status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Order Items - Itens individuais de cada pedido';
