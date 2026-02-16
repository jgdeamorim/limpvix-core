-- =====================================================
-- LimpVix Core - Scheduling Module Database Schema
-- Migration: 007_create_scheduling_tables.sql
-- Versão: 1.0.0
-- Data: 2026-02-08
-- =====================================================

-- TABELA 1: wp_limpvix_schedules
-- Agendamentos principais (Aggregate Root)
CREATE TABLE IF NOT EXISTS wp_limpvix_schedules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL UNIQUE COMMENT 'Schedule UUID',
    order_uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'Order UUID (FK)',
    briefing_id BIGINT UNSIGNED NOT NULL COMMENT 'Briefing ID',

    -- Tempo
    requested_time DATETIME NOT NULL COMMENT 'Horário solicitado pelo cliente',
    window_start DATETIME NOT NULL COMMENT 'Início da janela válida',
    window_end DATETIME NOT NULL COMMENT 'Fim da janela válida',
    estimated_duration_minutes INT NOT NULL COMMENT 'Duração estimada em minutos',

    -- Status e Alocação
    status VARCHAR(50) NOT NULL DEFAULT 'draft' COMMENT 'draft|allocated|in_progress|completed|cancelled',
    required_professionals INT NOT NULL DEFAULT 1 COMMENT 'Quantidade de profissionais necessários',

    -- Dados JSON (Value Objects serializados)
    service_location JSON NOT NULL COMMENT 'ServiceLocation completo',
    check_in_data JSON NULL COMMENT 'CheckIn serializado',
    check_out_data JSON NULL COMMENT 'CheckOut serializado',
    sla_violation JSON NULL COMMENT 'SlaViolation serializado',

    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    allocated_at DATETIME NULL COMMENT 'Quando foi alocado',
    completed_at DATETIME NULL COMMENT 'Quando foi completado',

    -- Índices
    INDEX idx_uuid (uuid),
    INDEX idx_order_uuid (order_uuid),
    INDEX idx_status (status),
    INDEX idx_requested_time (requested_time),
    INDEX idx_created_at (created_at)

    -- Foreign Keys (requires migration 001 to run first)
    -- FOREIGN KEY (commented for compatibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Agendamentos de serviços';

-- TABELA 2: wp_limpvix_professional_allocations
-- Alocações de profissionais aos schedules
CREATE TABLE IF NOT EXISTS wp_limpvix_professional_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'Schedule UUID (FK)',
    professional_id INT NOT NULL COMMENT 'Staff ID do Booknetic',

    -- TimeSlot alocado
    allocated_start DATETIME NOT NULL COMMENT 'Início do slot alocado',
    allocated_end DATETIME NOT NULL COMMENT 'Fim do slot alocado',

    -- Metadata
    status VARCHAR(50) NOT NULL DEFAULT 'allocated' COMMENT 'allocated|released|cancelled',
    allocation_score DECIMAL(5,2) NULL COMMENT 'Score de alocação (0-100)',

    -- Timestamps
    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME NULL COMMENT 'Quando foi liberado',

    -- Índices
    INDEX idx_schedule_uuid (schedule_uuid),
    INDEX idx_professional_id (professional_id),
    INDEX idx_professional_date (professional_id, allocated_start),
    INDEX idx_status (status)

    -- Foreign Keys
    -- FOREIGN KEY (commented for compatibility)
    --     FOREIGN KEY (professional_id) REFERENCES wp_bkntc_staff -- EXTERNAL PLUGIN(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Alocações de profissionais';

-- TABELA 3: wp_limpvix_professional_availability
-- Disponibilidade semanal dos profissionais
CREATE TABLE IF NOT EXISTS wp_limpvix_professional_availability (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    professional_id INT NOT NULL COMMENT 'Staff ID do Booknetic',

    -- Disponibilidade
    day_of_week VARCHAR(10) NOT NULL COMMENT 'monday|tuesday|...|sunday',
    start_time TIME NOT NULL COMMENT 'Início do horário disponível',
    end_time TIME NOT NULL COMMENT 'Fim do horário disponível',
    max_daily_hours INT NOT NULL DEFAULT 8 COMMENT 'Máximo de horas por dia',

    -- Dados JSON
    service_region JSON NOT NULL COMMENT 'ServiceRegion (centro + raio)',
    skills JSON NOT NULL COMMENT 'ProfessionalSkills array',
    limitations JSON NULL COMMENT 'Limitações físicas array',

    -- Metadata
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Índices
    INDEX idx_professional_id (professional_id),
    INDEX idx_day_of_week (day_of_week),
    INDEX idx_active (is_active),
    UNIQUE KEY unique_professional_day (professional_id, day_of_week)

    -- Foreign Keys
    --     FOREIGN KEY (professional_id) REFERENCES wp_bkntc_staff -- EXTERNAL PLUGIN(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Disponibilidade semanal de profissionais';

-- TABELA 4: wp_limpvix_check_ins
-- Check-ins dos profissionais
CREATE TABLE IF NOT EXISTS wp_limpvix_check_ins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL UNIQUE COMMENT 'CheckIn UUID',
    schedule_uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'Schedule UUID (FK)',
    professional_id INT NOT NULL COMMENT 'Staff ID',

    -- Timestamp e Localização
    timestamp DATETIME NOT NULL COMMENT 'Quando fez check-in',
    latitude DECIMAL(10,8) NOT NULL COMMENT 'Latitude',
    longitude DECIMAL(11,8) NOT NULL COMMENT 'Longitude',

    -- Validações
    within_window BOOLEAN NOT NULL COMMENT 'Dentro da janela válida?',
    within_geofence BOOLEAN NOT NULL COMMENT 'Dentro do geofence (150m)?',
    delay_minutes INT NULL COMMENT 'Atraso em minutos (null se no horário)',

    -- Dados JSON
    media_urls JSON NOT NULL COMMENT 'URLs de fotos/vídeos',
    sla_violation JSON NULL COMMENT 'SlaViolation se houver',

    -- Metadata
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Índices
    INDEX idx_uuid (uuid),
    INDEX idx_schedule_uuid (schedule_uuid),
    INDEX idx_professional_id (professional_id),
    INDEX idx_timestamp (timestamp)

    -- Foreign Keys
    -- FOREIGN KEY (commented for compatibility)
    --     FOREIGN KEY (professional_id) REFERENCES wp_bkntc_staff -- EXTERNAL PLUGIN(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Check-ins de profissionais';

-- TABELA 5: wp_limpvix_check_outs
-- Check-outs dos profissionais
CREATE TABLE IF NOT EXISTS wp_limpvix_check_outs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL UNIQUE COMMENT 'CheckOut UUID',
    schedule_uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'Schedule UUID (FK)',
    professional_id INT NOT NULL COMMENT 'Staff ID',
    check_in_uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'CheckIn UUID (FK)',

    -- Timestamp e Duração
    timestamp DATETIME NOT NULL COMMENT 'Quando fez check-out',
    actual_duration_minutes INT NOT NULL COMMENT 'Duração real em minutos',

    -- Dados JSON
    media_urls JSON NOT NULL COMMENT 'URLs de fotos do resultado',

    -- Metadata
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Índices
    INDEX idx_uuid (uuid),
    INDEX idx_schedule_uuid (schedule_uuid),
    INDEX idx_professional_id (professional_id),
    INDEX idx_check_in_uuid (check_in_uuid),
    INDEX idx_timestamp (timestamp)

    -- Foreign Keys
    -- FOREIGN KEY (commented for compatibility)
    --     FOREIGN KEY (professional_id) REFERENCES wp_bkntc_staff -- EXTERNAL PLUGIN(id) ON DELETE CASCADE
    -- FOREIGN KEY (commented for compatibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Check-outs de profissionais';

-- TABELA 6: wp_limpvix_scheduling_ledger
-- Ledger de eventos do scheduling (append-only, auditoria)
CREATE TABLE IF NOT EXISTS wp_limpvix_scheduling_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ledger_uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL UNIQUE COMMENT 'Ledger entry UUID',
    schedule_uuid CHAR(36) COLLATE utf8mb4_unicode_520_ci NOT NULL COMMENT 'Schedule UUID (FK)',

    -- Evento
    event_type VARCHAR(100) NOT NULL COMMENT 'Tipo do evento',
    from_status VARCHAR(50) NULL COMMENT 'Status anterior',
    to_status VARCHAR(50) NOT NULL COMMENT 'Novo status',

    -- Actor
    actor VARCHAR(50) NOT NULL DEFAULT 'system' COMMENT 'system|customer|admin|professional',
    actor_id BIGINT UNSIGNED NULL COMMENT 'ID do ator (user_id, staff_id, etc)',

    -- Dados JSON
    event_data JSON NULL COMMENT 'Dados do evento',

    -- Timestamp
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Quando ocorreu',

    -- Índices
    INDEX idx_ledger_uuid (ledger_uuid),
    INDEX idx_schedule_uuid (schedule_uuid),
    INDEX idx_event_type (event_type),
    INDEX idx_occurred_at (occurred_at)

    -- Foreign Keys
    -- FOREIGN KEY (commented for compatibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci COMMENT='Ledger de eventos do scheduling (append-only)';

-- =====================================================
-- FIM DA MIGRATION
-- =====================================================
