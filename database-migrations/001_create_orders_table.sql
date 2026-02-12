-- =====================================================
-- Migration 001: Create Orders Table
--
-- Tabela base para pedidos/ordens de serviço
-- Deve ser criada PRIMEIRO pois outras tabelas referenciam uuid
--
-- @version 1.0.0
-- @since 2026-02-10
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID único da order',

    -- Relacionamentos
    appointment_id BIGINT UNSIGNED NULL COMMENT 'ID do appointment no Booknetic',
    customer_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do cliente (WordPress user)',
    service_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do serviço',
    professional_id BIGINT UNSIGNED NULL COMMENT 'ID do profissional alocado',

    -- Status
    status VARCHAR(50) NOT NULL COMMENT 'Status do pedido: draft|confirmed|in_progress|completed|cancelled',
    financial_status VARCHAR(50) NOT NULL DEFAULT 'PENDING_PAYMENT' COMMENT 'Status financeiro: PENDING_PAYMENT|PAID|REFUNDED',

    -- Valores financeiros
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor total do pedido',
    platform_fee_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Percentual da taxa da plataforma',
    platform_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor da taxa da plataforma',
    professional_net_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Valor líquido para o profissional',

    -- Timestamps
    scheduled_at DATETIME NOT NULL COMMENT 'Data/hora agendada para o serviço',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de criação',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Data de atualização',

    -- Metadata (JSON para dados adicionais)
    metadata LONGTEXT NULL COMMENT 'Dados adicionais em JSON',

    -- Índices
    INDEX idx_uuid (uuid),
    INDEX idx_appointment_id (appointment_id),
    INDEX idx_customer_id (customer_id),
    INDEX idx_service_id (service_id),
    INDEX idx_professional_id (professional_id),
    INDEX idx_status (status),
    INDEX idx_financial_status (financial_status),
    INDEX idx_total_amount (total_amount),
    INDEX idx_scheduled_at (scheduled_at),
    INDEX idx_created_at (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Pedidos/Ordens de Serviço - Tabela base (Order Aggregate)';
