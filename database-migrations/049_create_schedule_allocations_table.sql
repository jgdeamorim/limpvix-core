-- =====================================================
-- Migration 049: Create Schedule Allocations Table
--
-- Alocacoes de profissionais em agendamentos especificos
-- Resultado do ProfessionalMatcher (score de alocacao)
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_schedule_allocations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID da alocacao',
    schedule_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to schedules.id',
    professional_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to professionals.id',

    allocated_start DATETIME NOT NULL COMMENT 'Inicio do slot alocado',
    allocated_end DATETIME NOT NULL COMMENT 'Fim do slot alocado',

    status VARCHAR(50) NOT NULL DEFAULT 'allocated' COMMENT 'allocated|confirmed|released|cancelled|no_show',
    allocation_score DECIMAL(5,2) NULL COMMENT 'Score de qualidade da alocacao (0-100)',

    matching_criteria JSON NULL COMMENT 'Criterios que determinaram o match',
    distance_km DECIMAL(8,2) NULL COMMENT 'Distancia do local de servico',

    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    released_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_schedule_id (schedule_id),
    INDEX idx_professional_id (professional_id),
    INDEX idx_professional_date (professional_id, allocated_start),
    INDEX idx_status (status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Schedule Allocations - Alocacoes de profissionais em agendamentos';
