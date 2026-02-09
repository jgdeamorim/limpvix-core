-- =====================================================
-- Migration 009: Sistema de Contratos Recorrentes
-- Data: 2026-02-09
-- Autor: LimpVix Core Team
-- Descrição: Tabelas para gerenciar contratos recorrentes
--           (mensais, semanais, quinzenais) e histórico
-- =====================================================

-- 1. Tabela principal de contratos
CREATE TABLE IF NOT EXISTS wp_limpvix_contracts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_number VARCHAR(50) NOT NULL UNIQUE COMMENT 'Número único do contrato (ex: CNT-2026-001)',
    client_user_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do usuário/cliente WordPress',

    -- Tipo e frequência
    contract_type VARCHAR(20) NOT NULL COMMENT 'monthly, weekly, biweekly',
    recurrence_day INT NULL COMMENT 'Dia do mês (1-31) ou dia da semana (0-6 para weekly)',
    recurrence_weeks INT DEFAULT 1 COMMENT 'A cada quantas semanas (para weekly/biweekly)',

    -- Serviço contratado
    service_code VARCHAR(50) NOT NULL COMMENT 'Código do serviço (ex: residential_standard)',
    property_type VARCHAR(20) NOT NULL COMMENT 'residential ou commercial',
    estimated_m2 DECIMAL(10,2) NULL COMMENT 'Área estimada em m²',

    -- Financeiro
    monthly_value DECIMAL(10,2) NOT NULL COMMENT 'Valor mensal fixo do contrato',
    payment_method VARCHAR(50) NULL COMMENT 'Método de pagamento padrão',
    payment_day INT NULL COMMENT 'Dia do pagamento (1-31)',

    -- Vigência
    start_date DATE NOT NULL COMMENT 'Data de início do contrato',
    end_date DATE NULL COMMENT 'Data de fim (NULL = sem prazo)',
    auto_renew BOOLEAN DEFAULT FALSE COMMENT 'Renovação automática?',

    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active, suspended, cancelled, expired',

    -- Endereço de serviço (JSON)
    service_address JSON NOT NULL COMMENT 'Endereço completo onde o serviço é prestado',

    -- Metadados
    notes TEXT NULL COMMENT 'Observações sobre o contrato',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cancelled_at DATETIME NULL COMMENT 'Data de cancelamento',
    cancellation_reason TEXT NULL COMMENT 'Motivo do cancelamento',

    INDEX idx_client (client_user_id),
    INDEX idx_status (status),
    INDEX idx_dates (start_date, end_date),
    INDEX idx_type (contract_type),
    INDEX idx_contract_number (contract_number),

    FOREIGN KEY (client_user_id) REFERENCES wp_users(ID) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Contratos recorrentes de serviço';

-- 2. Tabela de execuções do contrato (histórico)
CREATE TABLE IF NOT EXISTS wp_limpvix_contract_executions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do contrato',
    briefing_uuid CHAR(36) NULL COMMENT 'UUID do briefing gerado (se houver)',
    schedule_uuid CHAR(36) NULL COMMENT 'UUID do schedule criado (se houver)',

    -- Datas
    scheduled_date DATE NOT NULL COMMENT 'Data agendada para execução',
    executed_date DATE NULL COMMENT 'Data real de execução',
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, scheduled, in_progress, completed, cancelled, failed',

    -- Valor (pode variar do contrato se houver extras)
    execution_value DECIMAL(10,2) NULL COMMENT 'Valor desta execução específica',

    -- Metadados
    notes TEXT NULL COMMENT 'Observações sobre esta execução',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_contract (contract_id),
    INDEX idx_date (scheduled_date),
    INDEX idx_status (status),
    INDEX idx_briefing (briefing_uuid),
    INDEX idx_schedule (schedule_uuid),

    FOREIGN KEY (contract_id) REFERENCES wp_limpvix_contracts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Histórico de execuções de contratos';

-- =====================================================
-- Triggers e Funções Auxiliares
-- =====================================================

-- Trigger para atualizar status do contrato quando expirado
DELIMITER //

CREATE TRIGGER IF NOT EXISTS check_contract_expiration
BEFORE UPDATE ON wp_limpvix_contracts
FOR EACH ROW
BEGIN
    -- Se o contrato tem data de fim e já passou, marcar como expirado
    IF NEW.end_date IS NOT NULL AND NEW.end_date < CURDATE() AND NEW.status = 'active' THEN
        SET NEW.status = 'expired';
    END IF;
END//

DELIMITER ;

-- =====================================================
-- SEED DATA: Exemplo de contrato (comentado)
-- =====================================================

-- Exemplo de insert (descomentado apenas para demonstração)
/*
INSERT INTO wp_limpvix_contracts (
    contract_number, client_user_id, contract_type, recurrence_day,
    service_code, property_type, estimated_m2,
    monthly_value, payment_day, start_date, auto_renew, status, service_address
) VALUES (
    'CNT-2026-DEMO', 1, 'monthly', 15,
    'residential_standard', 'residential', 120.00,
    350.00, 5, CURDATE(), TRUE, 'active',
    JSON_OBJECT(
        'address', 'Rua Exemplo',
        'number', '123',
        'complement', 'Apto 45',
        'neighborhood', 'Centro',
        'city', 'Vitória',
        'state', 'ES',
        'zip_code', '29000-000'
    )
);
*/

-- =====================================================
-- FIM DA MIGRATION 009
-- =====================================================
