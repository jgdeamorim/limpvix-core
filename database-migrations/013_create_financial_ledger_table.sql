-- Migration 010: CREATE limpvix_financial_ledger
--
-- OBJETIVO:
-- Criar tabela de ledger financeiro (append-only) para rastreabilidade de eventos financeiros
--
-- USADO POR:
-- - RegisterBriefingAcceptance.php (event_type: briefing_accepted)
-- - StaffFinancialStatusResolver.php (event_type: dispute_opened, transferred)
-- - PayoutsPage.php (histórico de payouts)
--
-- PRINCÍPIOS:
-- - Append-only (sem UPDATE, sem DELETE)
-- - Auditabilidade total
-- - Idempotência (UNIQUE ledger_uuid)
--
-- Data: 2026-02-08
-- Correção: P0 Oculto - Tabela inexistente

CREATE TABLE IF NOT EXISTS wp_limpvix_financial_ledger (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    ledger_uuid VARCHAR(64) NOT NULL COMMENT 'UUID único do evento',

    -- Entidades relacionadas
    order_uuid VARCHAR(64) NULL COMMENT 'UUID da Order LimpVix',
    customer_id BIGINT(20) UNSIGNED NULL COMMENT 'ID do cliente WordPress',
    professional_id BIGINT(20) UNSIGNED NULL COMMENT 'ID do profissional (staff)',
    appointment_id BIGINT(20) UNSIGNED NULL COMMENT 'ID do appointment Booknetic',

    -- Tipo de evento financeiro
    event_type VARCHAR(100) NOT NULL COMMENT 'briefing_accepted, payment_received, payout_authorized, transferred, dispute_opened, etc.',

    -- Dados do evento (JSON flexível)
    event_data LONGTEXT NULL COMMENT 'JSON com dados específicos do evento (IP, user_agent, valores, etc.)',

    -- Status e flags
    resolved TINYINT(1) DEFAULT 0 COMMENT 'Para disputas: 0=aberta, 1=resolvida',

    -- Auditoria
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Índices
    PRIMARY KEY (id),
    UNIQUE KEY unique_ledger_uuid (ledger_uuid),
    KEY idx_order_uuid (order_uuid),
    KEY idx_customer_id (customer_id),
    KEY idx_professional_id (professional_id),
    KEY idx_event_type (event_type),
    KEY idx_created_at (created_at),
    KEY idx_professional_event (professional_id, event_type),
    KEY idx_dispute_resolved (professional_id, event_type, resolved)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ledger financeiro imutável para auditoria';

-- Registrar versão da migration
INSERT INTO wp_options (option_name, option_value, autoload)
VALUES ('limpvix_db_version_financial_ledger', '1.0.0', 'no')
ON DUPLICATE KEY UPDATE option_value = '1.0.0';
