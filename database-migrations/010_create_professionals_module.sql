-- ============================================================================
-- Migration 010: Professional Module - Base do Marketplace
-- ============================================================================
-- Data: 2026-02-09
-- Versão: v0.2.0
-- Descrição: Cria estrutura completa de profissionais para modelo marketplace
--            (Uber/99) com score, região, skills e disponibilidade
-- ============================================================================

-- 1. TABELA DE PROFISSIONAIS
-- ============================================================================
-- Profissionais autônomos da plataforma (gig economy, sem vínculo)
-- ============================================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_professionals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE COMMENT 'FK para wp_users',

    -- Identificação
    full_name VARCHAR(255) NOT NULL,
    cpf VARCHAR(14) UNIQUE NOT NULL COMMENT 'CPF único, sem máscara',
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL,
    birth_date DATE NULL,

    -- Score e Performance (calculados automaticamente)
    score DECIMAL(3,2) DEFAULT 5.00 COMMENT '0.00-5.00 estrelas, média ponderada',
    total_services INT DEFAULT 0 COMMENT 'Total de serviços oferecidos',
    completed_services INT DEFAULT 0 COMMENT 'Serviços concluídos com sucesso',
    cancelled_services INT DEFAULT 0 COMMENT 'Serviços cancelados pelo profissional',
    no_show_count INT DEFAULT 0 COMMENT 'Não comparecimentos',
    acceptance_rate DECIMAL(5,2) DEFAULT 100.00 COMMENT '% de ofertas aceitas',

    -- Pontualidade (para cálculo de score)
    on_time_count INT DEFAULT 0 COMMENT 'Check-ins dentro da janela',
    late_count INT DEFAULT 0 COMMENT 'Check-ins atrasados',
    avg_delay_minutes INT DEFAULT 0 COMMENT 'Média de atraso em minutos',

    -- Localização (centro de atuação)
    service_region JSON NOT NULL COMMENT '{center: {lat: -20.123, lng: -40.456}, radius_km: 10}',
    latitude DECIMAL(10,8) NULL COMMENT 'Lat do centro de atuação',
    longitude DECIMAL(11,8) NULL COMMENT 'Lng do centro de atuação',
    service_radius_km INT DEFAULT 10 COMMENT 'Raio de atuação em km',

    -- Skills e Limitações
    skills JSON NOT NULL COMMENT '["limpeza_basica", "pos_obra", "teto", "esquadrias", "vidros"]',
    certifications JSON NULL COMMENT '["nr35_altura", "eletrica_basica"]',
    physical_limitations JSON NULL COMMENT '["no_heights", "no_heavy_lifting"]',

    -- Disponibilidade Semanal
    weekly_availability JSON NOT NULL COMMENT '{
        "monday": [{"start": "08:00", "end": "12:00"}, {"start": "14:00", "end": "18:00"}],
        "tuesday": [...],
        "sunday": []
    }',
    max_daily_hours INT DEFAULT 8 COMMENT 'Máximo de horas por dia',
    min_service_interval_hours INT DEFAULT 1 COMMENT 'Intervalo mínimo entre serviços',

    -- Financeiro (para payouts)
    bank_account JSON NULL COMMENT '{
        "bank_code": "001",
        "bank_name": "Banco do Brasil",
        "agency": "1234",
        "account": "56789-0",
        "account_type": "corrente",
        "holder_name": "Nome Completo",
        "holder_cpf": "12345678900"
    }',
    pix_key VARCHAR(255) NULL COMMENT 'Chave PIX para recebimento',
    pix_key_type VARCHAR(20) NULL COMMENT 'cpf|cnpj|email|phone|random',

    -- Status e Verificação
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Profissional ativo na plataforma?',
    is_verified BOOLEAN DEFAULT FALSE COMMENT 'Documentos verificados?',
    verified_at DATETIME NULL COMMENT 'Quando foi verificado',
    verified_by BIGINT UNSIGNED NULL COMMENT 'ID do admin que verificou',

    -- Suspensão/Ban
    suspended_until DATETIME NULL COMMENT 'Suspenso até quando?',
    suspension_reason TEXT NULL COMMENT 'Motivo da suspensão',
    is_permanently_banned BOOLEAN DEFAULT FALSE COMMENT 'Banido permanentemente?',
    ban_reason TEXT NULL,

    -- Compliance e Documentação
    has_valid_documents BOOLEAN DEFAULT FALSE COMMENT 'Documentos válidos?',
    document_expiry_date DATE NULL COMMENT 'Vencimento do documento principal',
    documents JSON NULL COMMENT '{
        "rg": {"number": "123", "file_url": "..."},
        "cpf": {"verified": true},
        "address_proof": {"file_url": "..."}
    }',

    -- EPI (Equipamento de Proteção Individual)
    has_epi BOOLEAN DEFAULT FALSE COMMENT 'Possui EPI?',
    epi_last_check DATE NULL COMMENT 'Última verificação de EPI',

    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_activity_at DATETIME NULL COMMENT 'Última atividade na plataforma',

    -- Indexes
    INDEX idx_user (user_id),
    INDEX idx_cpf (cpf),
    INDEX idx_score (score DESC),
    INDEX idx_location (latitude, longitude),
    INDEX idx_active_verified (is_active, is_verified),
    INDEX idx_acceptance_rate (acceptance_rate DESC),

    -- Foreign Keys
    FOREIGN KEY (user_id) REFERENCES wp_users(ID) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES wp_users(ID) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Profissionais autônomos da plataforma marketplace';


-- 2. HISTÓRICO DE ALOCAÇÕES
-- ============================================================================
-- Registra todas alocações de profissionais em contratos (append-only)
-- ============================================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_professional_allocations_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL COMMENT 'FK para wp_limpvix_contracts',
    professional_id BIGINT UNSIGNED NOT NULL COMMENT 'FK para wp_limpvix_professionals',

    -- Período de Alocação
    allocated_at DATETIME NOT NULL COMMENT 'Quando foi alocado',
    deallocated_at DATETIME NULL COMMENT 'Quando foi desalocado (se aplicável)',

    -- Motivo de Desalocação
    deallocation_reason VARCHAR(50) NULL COMMENT 'low_score|client_request|professional_request|contract_ended',
    deallocation_notes TEXT NULL,

    -- Performance durante alocação
    final_score DECIMAL(3,2) NULL COMMENT 'Score final ao ser desalocado',
    total_executions INT DEFAULT 0 COMMENT 'Total de execuções realizadas',
    completed_executions INT DEFAULT 0 COMMENT 'Execuções concluídas',
    failed_executions INT DEFAULT 0 COMMENT 'Execuções falhadas',

    -- Estatísticas
    avg_feedback_rating DECIMAL(3,2) NULL COMMENT 'Média de feedback do cliente',
    on_time_percentage DECIMAL(5,2) NULL COMMENT '% de pontualidade',

    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_contract (contract_id),
    INDEX idx_professional (professional_id),
    INDEX idx_dates (allocated_at, deallocated_at),
    INDEX idx_deallocation_reason (deallocation_reason),

    -- Foreign Keys
    FOREIGN KEY (contract_id) REFERENCES wp_limpvix_contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (professional_id) REFERENCES wp_limpvix_professionals(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Histórico de alocações de profissionais em contratos';


-- 3. ALTERAR TABELA DE BRIEFINGS (Adicionar Recorrência)
-- ============================================================================
-- Permite que cliente solicite serviços recorrentes direto no briefing
-- ============================================================================

ALTER TABLE wp_limpvix_briefings
ADD COLUMN is_recurrent BOOLEAN DEFAULT FALSE COMMENT 'Cliente solicitou serviço recorrente?',
ADD COLUMN recurrence_type VARCHAR(20) NULL COMMENT 'weekly|monthly|biweekly',
ADD COLUMN recurrence_duration VARCHAR(20) NULL COMMENT '3_months|6_months|indefinite',
ADD COLUMN recurrence_day INT NULL COMMENT 'Dia do mês (1-31) para monthly, dia da semana (0-6) para weekly',
ADD COLUMN recurrence_weeks INT NULL COMMENT 'A cada X semanas (para biweekly)',
ADD COLUMN recurrence_start_date DATE NULL COMMENT 'Data de início da recorrência';


-- 4. ALTERAR TABELA DE CONTRATOS (Vincular Profissionais)
-- ============================================================================
-- Adiciona referência ao profissional alocado no contrato
-- ============================================================================

ALTER TABLE wp_limpvix_contracts
ADD COLUMN allocated_professional_id BIGINT UNSIGNED NULL COMMENT 'FK para wp_limpvix_professionals.id (PK, NÃO user_id)',
ADD COLUMN allocation_status VARCHAR(50) DEFAULT 'pending' COMMENT 'pending|allocated|rejected|reallocating',
ADD COLUMN allocation_attempts INT DEFAULT 0 COMMENT 'Tentativas de alocação',
ADD COLUMN last_allocation_attempt DATETIME NULL,
ADD INDEX idx_allocated_professional (allocated_professional_id),
ADD FOREIGN KEY (allocated_professional_id) REFERENCES wp_limpvix_professionals(id) ON DELETE SET NULL;


-- 5. TABELA DE OFERTAS (para sistema first-to-accept)
-- ============================================================================
-- Profissionais recebem ofertas e podem aceitar/rejeitar
-- ============================================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_contract_offers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contract_id BIGINT UNSIGNED NOT NULL COMMENT 'FK para wp_limpvix_contracts.id',
    professional_id BIGINT UNSIGNED NOT NULL COMMENT 'FK para wp_limpvix_professionals.id (PK, NÃO user_id)',

    -- Score de Alocação (calculado pelo algoritmo)
    allocation_score DECIMAL(5,2) NOT NULL COMMENT '0-100, score de match do profissional',
    proximity_score DECIMAL(5,2) NOT NULL COMMENT 'Score de proximidade (40%)',
    availability_score DECIMAL(5,2) NOT NULL COMMENT 'Score de disponibilidade (30%)',
    rating_score DECIMAL(5,2) NOT NULL COMMENT 'Score de rating (20%)',
    workload_score DECIMAL(5,2) NOT NULL COMMENT 'Score de carga de trabalho (10%)',

    -- Status da Oferta
    status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending|accepted|rejected|expired',

    -- Timestamps
    offered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL COMMENT 'Oferta expira após X minutos',
    responded_at DATETIME NULL,

    -- Motivo de Rejeição
    rejection_reason VARCHAR(100) NULL COMMENT 'too_far|busy|not_interested|other',
    rejection_notes TEXT NULL,

    -- Indexes
    INDEX idx_contract (contract_id),
    INDEX idx_professional (professional_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at),
    UNIQUE KEY unique_contract_professional (contract_id, professional_id),

    -- Foreign Keys
    FOREIGN KEY (contract_id) REFERENCES wp_limpvix_contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (professional_id) REFERENCES wp_limpvix_professionals(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Ofertas de contratos enviadas para profissionais';


-- 6. TABELA DE SCORE HISTORY (para auditoria de score)
-- ============================================================================
-- Registra todas mudanças de score com motivo
-- ============================================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_professional_score_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    professional_id BIGINT UNSIGNED NOT NULL,

    -- Score
    old_score DECIMAL(3,2) NOT NULL,
    new_score DECIMAL(3,2) NOT NULL,
    score_change DECIMAL(3,2) NOT NULL COMMENT 'Pode ser negativo',

    -- Motivo da mudança
    change_reason VARCHAR(100) NOT NULL COMMENT 'feedback|late_checkin|no_show|good_execution|epi_violation',
    related_contract_id BIGINT UNSIGNED NULL,
    related_execution_id BIGINT UNSIGNED NULL,

    -- Detalhes
    change_details JSON NULL COMMENT 'Dados extras sobre o motivo',

    -- Timestamps
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    changed_by VARCHAR(50) DEFAULT 'system' COMMENT 'system|admin|client',

    -- Indexes
    INDEX idx_professional (professional_id),
    INDEX idx_changed_at (changed_at),
    INDEX idx_reason (change_reason),

    -- Foreign Keys
    FOREIGN KEY (professional_id) REFERENCES wp_limpvix_professionals(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT 'Histórico de mudanças de score dos profissionais';


-- 7. INSERIR ROLE DE PROFISSIONAL NO WORDPRESS
-- ============================================================================

-- Nota: Esta parte será executada via PHP (add_role)
-- Aqui apenas documentamos a estrutura esperada

/*
Role: 'limpvix_professional'
Capabilities:
- read (acesso ao painel)
- limpvix_view_offers (ver ofertas)
- limpvix_accept_offers (aceitar ofertas)
- limpvix_checkin (fazer check-in)
- limpvix_checkout (fazer checkout)
- limpvix_view_schedule (ver agenda)
- limpvix_update_profile (atualizar perfil)
*/


-- ============================================================================
-- FIM DA MIGRATION 010
-- ============================================================================

-- CHANGELOG:
-- v0.2.0 - 2026-02-09 - Criação inicial do módulo de profissionais
--   - Tabela wp_limpvix_professionals
--   - Tabela wp_limpvix_professional_allocations_history
--   - Campos de recorrência em wp_limpvix_briefings
--   - Vinculação de profissionais em wp_limpvix_contracts
--   - Sistema de ofertas (wp_limpvix_contract_offers)
--   - Histórico de score (wp_limpvix_professional_score_history)
