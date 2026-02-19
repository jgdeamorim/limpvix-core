-- =====================================================
-- Migration 046: Create Message Flows Table
--
-- Fluxos de mensagem orquestrados (C1-C3, P1-P3)
-- Define sequencia de mensagens por evento trigger
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_message_flows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID do fluxo',

    flow_key VARCHAR(100) NOT NULL UNIQUE COMMENT 'c1_contact|c2_confirmation|c3_feedback|p1_offer|p2_reminder|p3_late_alert',
    flow_name VARCHAR(255) NOT NULL COMMENT 'Nome amigavel do fluxo',
    trigger_event VARCHAR(100) NOT NULL COMMENT 'Evento que dispara: briefing_received|24h_before|service_completed|etc',
    channel VARCHAR(50) NOT NULL COMMENT 'whatsapp|sms|push|email',

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sequence JSON NOT NULL COMMENT 'Array de steps com timing e template',
    conditions JSON NULL COMMENT 'Regras condicionais de roteamento',

    total_executions INT NOT NULL DEFAULT 0,
    successful_executions INT NOT NULL DEFAULT 0,
    failed_executions INT NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_trigger_event (trigger_event),
    INDEX idx_channel (channel),
    INDEX idx_is_active (is_active)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Message Flows - Fluxos de comunicacao automatica (C1-C3, P1-P3)';
