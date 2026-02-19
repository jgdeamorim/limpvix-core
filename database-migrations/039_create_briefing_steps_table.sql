-- =====================================================
-- Migration 039: Create Briefing Steps Table
--
-- Armazena cada step individual do briefing multi-step
-- 10 steps: tipo_imovel, estrutura, limpeza, adicionais,
-- pacote, frequencia, data_hora, localizacao, condicoes, resumo
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_briefing_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID do step',
    briefing_uuid VARCHAR(36) NOT NULL COMMENT 'FK to briefings.uuid',

    step_number INT NOT NULL COMMENT 'Posicao do step (1-10)',
    step_key VARCHAR(100) NOT NULL COMMENT 'property_type|structure|cleaning|additionals|package|frequency|datetime|location|conditions|summary',
    step_label VARCHAR(255) NOT NULL COMMENT 'Label exibido no wizard',

    form_data JSON NOT NULL COMMENT 'Dados do formulario em JSON',
    is_completed TINYINT(1) NOT NULL DEFAULT 0,

    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_briefing_step (briefing_uuid, step_key),
    INDEX idx_briefing_uuid (briefing_uuid),
    INDEX idx_step_key (step_key),
    INDEX idx_is_completed (is_completed)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Briefing Steps - Steps individuais do wizard multi-step (10 steps)';
