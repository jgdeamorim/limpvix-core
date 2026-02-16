-- =====================================================
-- Migration 008: Communication System Tables
--
-- Cria 3 tabelas para comunicação event-driven:
-- 1. wp_limpvix_message_templates (templates versionados)
-- 2. wp_limpvix_message_log (histórico de envios)
-- 3. wp_limpvix_message_queue (retry queue)
--
-- @version 0.3.0
-- @since 2026-02-07
-- =====================================================

-- =====================================================
-- Tabela 1: wp_limpvix_message_templates
-- =====================================================
CREATE TABLE IF NOT EXISTS wp_limpvix_message_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id VARCHAR(50) NOT NULL COMMENT 'ID único (T-BOOKING-01)',
    version VARCHAR(10) NOT NULL COMMENT 'Versão semântica (1.2)',
    content LONGTEXT NOT NULL COMMENT 'Conteúdo com placeholders {{var}}',
    required_variables JSON NOT NULL COMMENT 'Array de variáveis requeridas',
    channel VARCHAR(20) NOT NULL COMMENT 'whatsapp|sms|email|push',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Template ativo',
    created_at DATETIME NOT NULL COMMENT 'Data de criação',

    -- Índices
    UNIQUE KEY unique_template_version (template_id, version),
    INDEX idx_template_id (template_id),
    INDEX idx_channel (channel),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Message Templates - Templates versionados';

-- =====================================================
-- Tabela 2: wp_limpvix_message_log
-- =====================================================
CREATE TABLE IF NOT EXISTS wp_limpvix_message_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(100) NOT NULL UNIQUE COMMENT 'ID único da mensagem',
    template_id VARCHAR(50) NOT NULL COMMENT 'Template usado',
    template_version VARCHAR(10) NOT NULL COMMENT 'Versão do template',
    recipient VARCHAR(255) NOT NULL COMMENT 'Destinatário (phone/email)',
    channel VARCHAR(20) NOT NULL COMMENT 'whatsapp|sms|email|push',
    content LONGTEXT NULL COMMENT 'Mensagem renderizada',

    -- Status de entrega
    status VARCHAR(20) NOT NULL COMMENT 'pending|sent|delivered|read|failed',
    retry_count INT DEFAULT 0 COMMENT 'Número de tentativas',
    sent_at DATETIME NULL COMMENT 'Timestamp de envio',
    delivered_at DATETIME NULL COMMENT 'Timestamp de entrega',
    read_at DATETIME NULL COMMENT 'Timestamp de leitura',
    failed_at DATETIME NULL COMMENT 'Timestamp de falha',

    -- Rastreabilidade
    event_id VARCHAR(100) NULL COMMENT 'ID do evento de domínio que disparou',
    event_type VARCHAR(100) NULL COMMENT 'Tipo do evento',
    user_id BIGINT UNSIGNED NULL COMMENT 'User ID relacionado',

    -- Erro (se falhou)
    error_type VARCHAR(100) NULL COMMENT 'Tipo do erro',
    error_message TEXT NULL COMMENT 'Mensagem de erro',

    -- Provider response
    provider_response JSON NULL COMMENT 'Resposta do provider',

    created_at DATETIME NOT NULL COMMENT 'Data de criação',

    -- Índices
    INDEX idx_message_id (message_id),
    INDEX idx_template_id (template_id),
    INDEX idx_recipient (recipient),
    INDEX idx_status (status),
    INDEX idx_event_id (event_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_failed_at (failed_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Message Log - Histórico de envios (append-only)';

-- =====================================================
-- Tabela 3: wp_limpvix_message_queue
-- =====================================================
CREATE TABLE IF NOT EXISTS wp_limpvix_message_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(100) NOT NULL COMMENT 'ID da mensagem original',
    template_id VARCHAR(50) NOT NULL COMMENT 'Template para retry',
    recipient VARCHAR(255) NOT NULL COMMENT 'Destinatário',
    variables JSON NOT NULL COMMENT 'Variáveis do template',

    -- Rastreabilidade
    event_id VARCHAR(100) NULL COMMENT 'Evento original',
    event_type VARCHAR(100) NULL COMMENT 'Tipo do evento',
    user_id BIGINT UNSIGNED NULL COMMENT 'User ID',

    -- Retry control
    retry_count INT NOT NULL COMMENT 'Número da tentativa (1, 2, 3)',
    scheduled_at DATETIME NOT NULL COMMENT 'Quando deve ser processado',
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|completed|failed',

    created_at DATETIME NOT NULL COMMENT 'Data de criação',
    processed_at DATETIME NULL COMMENT 'Data de processamento',

    -- Índices
    INDEX idx_message_id (message_id),
    INDEX idx_template_id (template_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_at (scheduled_at),
    INDEX idx_status_scheduled (status, scheduled_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Message Queue - Fila de retry';

-- =====================================================
-- Popular com templates iniciais
-- =====================================================

-- Template: Appointment Confirmed
INSERT IGNORE INTO wp_limpvix_message_templates (template_id, version, content, required_variables, channel, is_active, created_at) VALUES
('T-BOOKING-01', '1.0', 'Olá {{client_name}}! 👋\n\nSeu serviço foi agendado com sucesso para {{appointment_date}} às {{appointment_time}}.\n\nProfissional: {{professional_name}}\n\nEquipe Limpvix', '["client_name", "appointment_date", "appointment_time", "professional_name"]', 'whatsapp', TRUE, NOW());

-- Template: Reminder 24h
INSERT IGNORE INTO wp_limpvix_message_templates (template_id, version, content, required_variables, channel, is_active, created_at) VALUES
('T-REMINDER-24H', '1.0', 'Lembrete: Seu serviço está agendado para amanhã, {{appointment_date}}, entre {{window_start}} e {{window_end}}.\n\nProfissional {{professional_name}} chegará nesse horário.\n\nEquipe Limpvix', '["appointment_date", "window_start", "window_end", "professional_name"]', 'whatsapp', TRUE, NOW());

-- Template: Professional On The Way
INSERT IGNORE INTO wp_limpvix_message_templates (template_id, version, content, required_variables, channel, is_active, created_at) VALUES
('T-ON-THE-WAY', '1.0', 'Olá {{client_name}}! 👋\n\nO profissional {{professional_name}} está a caminho.\n\nChegada prevista entre {{window_start}} e {{window_end}}.\n\nDúvidas? Responda esta mensagem.', '["client_name", "professional_name", "window_start", "window_end"]', 'whatsapp', TRUE, NOW());

-- Template: Check-in Performed
INSERT IGNORE INTO wp_limpvix_message_templates (template_id, version, content, required_variables, channel, is_active, created_at) VALUES
('T-CHECKIN', '1.0', 'Serviço iniciado! ✅\n\nProfissional {{professional_name}} fez check-in às {{checkin_time}}.\n\nVocê pode acompanhar o andamento pelo app.\n\nEquipe Limpvix', '["professional_name", "checkin_time"]', 'whatsapp', TRUE, NOW());

-- Template: Service Completed
INSERT IGNORE INTO wp_limpvix_message_templates (template_id, version, content, required_variables, channel, is_active, created_at) VALUES
('T-CHECKOUT', '1.0', 'Serviço concluído! 🎉\n\nProfissional {{professional_name}} finalizou o serviço às {{checkout_time}}.\n\nComo foi a experiência? Sua avaliação é muito importante:\n\n👉 {{feedback_url}}\n\nObrigado!', '["professional_name", "checkout_time", "feedback_url"]', 'whatsapp', TRUE, NOW());

-- Template: Feedback Request D+1
INSERT IGNORE INTO wp_limpvix_message_templates (template_id, version, content, required_variables, channel, is_active, created_at) VALUES
('T-FEEDBACK-D1', '1.0', 'Olá {{client_name}}! 👋\n\nO serviço realizado ontem atendeu suas expectativas?\n\nSua avaliação ajuda a Limpvix a manter a qualidade ⭐\n\n👉 Avaliar agora: {{feedback_url}}\n\nEquipe Limpvix', '["client_name", "feedback_url"]', 'whatsapp', TRUE, NOW());

-- Template: SLA Violation Alert
INSERT IGNORE INTO wp_limpvix_message_templates (template_id, version, content, required_variables, channel, is_active, created_at) VALUES
('T-SLA-ISSUE', '1.0', 'ALERTA: Violação de SLA detectada\n\nOrder: {{order_uuid}}\nTipo: {{violation_type}}\nProfissional: {{professional_name}}\nTimestamp: {{occurred_at}}', '["order_uuid", "violation_type", "professional_name", "occurred_at"]', 'email', TRUE, NOW());

-- =====================================================
-- Comentários finais
-- =====================================================
--
-- OBSERVAÇÕES:
-- 1. message_log é append-only (nunca DELETE)
-- 2. message_queue pode ser limpa após 30 dias (completed/failed)
-- 3. Templates versionados permitem A/B testing
-- 4. JSON fields permitem evolução de schema
-- 5. Índices otimizam queries comuns
--
-- ROLLBACK:
-- DROP TABLE IF EXISTS wp_limpvix_message_queue;
-- DROP TABLE IF EXISTS wp_limpvix_message_log;
-- DROP TABLE IF EXISTS wp_limpvix_message_templates;
--
-- =====================================================
