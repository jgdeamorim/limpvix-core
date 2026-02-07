-- =====================================================
-- Migration 006: Briefing Tables
--
-- Cria 3 tabelas para o módulo Briefing:
-- 1. wp_limpvix_briefings (principal)
-- 2. wp_limpvix_briefing_data (dados JSON por step)
-- 3. wp_limpvix_briefing_ledger (event sourcing)
--
-- @version 0.2.0
-- @since 2026-02-06
-- =====================================================

-- =====================================================
-- Tabela 1: wp_limpvix_briefings (Principal)
-- =====================================================
CREATE TABLE IF NOT EXISTS wp_limpvix_briefings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID único do Briefing',
    order_id BIGINT UNSIGNED NULL COMMENT 'Order ID vinculada (WooCommerce) - NULL até pagamento',
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'WordPress user ID',

    -- Estado e tipo
    status VARCHAR(50) NOT NULL DEFAULT 'draft' COMMENT 'Estado da state machine',
    property_type VARCHAR(20) NOT NULL COMMENT 'residential|commercial',

    -- Métricas calculadas
    estimated_m2 DECIMAL(10,2) NULL COMMENT 'm² estimado',
    estimated_duration_minutes INT NULL COMMENT 'Tempo estimado (minutos)',
    buffer_minutes INT DEFAULT 30 COMMENT 'Buffer operacional',

    -- Flags e metadados
    requires_contract BOOLEAN DEFAULT FALSE COMMENT 'Requer contrato (frequency != avulso)',
    phone_verified BOOLEAN DEFAULT FALSE COMMENT 'Telefone verificado via Firebase OTP',
    version VARCHAR(10) DEFAULT '1.0' COMMENT 'Versão do schema',

    -- Timestamps
    created_at DATETIME NOT NULL COMMENT 'Data de criação',
    updated_at DATETIME NOT NULL COMMENT 'Data de última atualização',
    locked_at DATETIME NULL COMMENT 'Data de lock (estado final)',

    -- Índices
    INDEX idx_uuid (uuid),
    INDEX idx_order_id (order_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_locked_at (locked_at),

    -- Foreign key para WordPress users
    CONSTRAINT fk_briefing_user FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Briefings - Tabela principal';

-- =====================================================
-- Tabela 2: wp_limpvix_briefing_data (Dados por step)
-- =====================================================
CREATE TABLE IF NOT EXISTS wp_limpvix_briefing_data (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    briefing_uuid VARCHAR(36) NOT NULL COMMENT 'UUID do Briefing',
    data_key VARCHAR(100) NOT NULL COMMENT 'Chave do step: structure, frequency, location, etc',
    data_value LONGTEXT NOT NULL COMMENT 'JSON serializado do step',
    created_at DATETIME NOT NULL COMMENT 'Data de criação',
    updated_at DATETIME NOT NULL COMMENT 'Data de atualização',

    -- Índices
    UNIQUE KEY unique_briefing_key (briefing_uuid, data_key),
    INDEX idx_briefing_uuid (briefing_uuid),
    INDEX idx_data_key (data_key),

    -- Foreign key
    CONSTRAINT fk_briefing_data_uuid
        FOREIGN KEY (briefing_uuid)
        REFERENCES wp_limpvix_briefings(uuid)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Briefing Data - Dados JSON por step';

-- =====================================================
-- Tabela 3: wp_limpvix_briefing_ledger (Event Sourcing)
-- =====================================================
CREATE TABLE IF NOT EXISTS wp_limpvix_briefing_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ledger_uuid VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID único do evento',
    briefing_uuid VARCHAR(36) NOT NULL COMMENT 'UUID do Briefing',

    -- Evento
    event_type VARCHAR(100) NOT NULL COMMENT 'Tipo: briefing.created, briefing.step_completed, etc',
    from_status VARCHAR(50) NULL COMMENT 'Status de origem (NULL para created)',
    to_status VARCHAR(50) NOT NULL COMMENT 'Status de destino',

    -- Ator
    actor VARCHAR(50) NOT NULL DEFAULT 'customer' COMMENT 'Quem executou: customer|admin|system',
    actor_id BIGINT UNSIGNED NULL COMMENT 'ID do ator (user_id se customer/admin)',

    -- Dados do evento
    event_data LONGTEXT NULL COMMENT 'JSON com dados adicionais do evento',

    -- Timestamp
    occurred_at DATETIME NOT NULL COMMENT 'Data/hora do evento',

    -- Índices
    INDEX idx_ledger_uuid (ledger_uuid),
    INDEX idx_briefing_uuid (briefing_uuid),
    INDEX idx_event_type (event_type),
    INDEX idx_occurred_at (occurred_at),
    INDEX idx_actor (actor, actor_id),

    -- Foreign key
    CONSTRAINT fk_briefing_ledger_uuid
        FOREIGN KEY (briefing_uuid)
        REFERENCES wp_limpvix_briefings(uuid)
        ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Briefing Ledger - Event Sourcing (append-only)';

-- =====================================================
-- Triggers para updated_at automático
-- =====================================================

-- Trigger para wp_limpvix_briefings
DROP TRIGGER IF EXISTS before_update_briefings;
DELIMITER //
CREATE TRIGGER before_update_briefings
BEFORE UPDATE ON wp_limpvix_briefings
FOR EACH ROW
BEGIN
    SET NEW.updated_at = NOW();
END//
DELIMITER ;

-- Trigger para wp_limpvix_briefing_data
DROP TRIGGER IF EXISTS before_update_briefing_data;
DELIMITER //
CREATE TRIGGER before_update_briefing_data
BEFORE UPDATE ON wp_limpvix_briefing_data
FOR EACH ROW
BEGIN
    SET NEW.updated_at = NOW();
END//
DELIMITER ;

-- =====================================================
-- Comentários finais
-- =====================================================
--
-- OBSERVAÇÕES:
-- 1. Ledger é append-only (INSERT IGNORE) - nunca UPDATE ou DELETE
-- 2. briefing_data permite versionamento de schema via JSON
-- 3. Triggers atualizam updated_at automaticamente
-- 4. Foreign keys garantem integridade referencial
-- 5. Índices otimizam queries comuns
--
-- ROLLBACK:
-- DROP TABLE IF EXISTS wp_limpvix_briefing_ledger;
-- DROP TABLE IF EXISTS wp_limpvix_briefing_data;
-- DROP TABLE IF EXISTS wp_limpvix_briefings;
--
-- =====================================================
