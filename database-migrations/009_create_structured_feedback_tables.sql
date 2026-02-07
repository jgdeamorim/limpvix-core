-- =====================================================
-- Migration 009: Structured Feedback System Tables
--
-- Cria 2 tabelas para feedback estruturado e arbitrável:
-- 1. wp_limpvix_structured_feedbacks (feedback principal)
-- 2. wp_limpvix_feedback_disputes (disputas/arbitragem)
--
-- @version 0.3.0
-- @since 2026-02-07
-- =====================================================

-- =====================================================
-- Tabela 1: wp_limpvix_structured_feedbacks
-- =====================================================
CREATE TABLE IF NOT EXISTS wp_limpvix_structured_feedbacks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID do feedback',
    order_uuid VARCHAR(36) NOT NULL COMMENT 'UUID da order',
    customer_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do cliente',

    -- Categoria e checklist
    service_category VARCHAR(50) NOT NULL COMMENT 'limpeza_basica|pos_obra|teto|esquadrias',
    checklist_data JSON NOT NULL COMMENT 'Checklist completo (critérios + scores)',

    -- Fotos e comentário
    photos JSON NOT NULL COMMENT 'Array de URLs de fotos',
    general_comment TEXT NULL COMMENT 'Comentário geral opcional',

    -- Score e status
    final_score DECIMAL(3,2) NOT NULL COMMENT 'Score médio (1.00-5.00)',
    status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|submitted|validated|disputed',

    -- Timestamps
    created_at DATETIME NOT NULL COMMENT 'Data de criação',
    submitted_at DATETIME NULL COMMENT 'Data de submissão',
    validated_at DATETIME NULL COMMENT 'Data de validação',

    -- Índices
    UNIQUE KEY unique_order_feedback (order_uuid),
    INDEX idx_uuid (uuid),
    INDEX idx_customer_id (customer_id),
    INDEX idx_status (status),
    INDEX idx_final_score (final_score),
    INDEX idx_service_category (service_category),
    INDEX idx_submitted_at (submitted_at),

    FOREIGN KEY (order_uuid) REFERENCES wp_limpvix_orders(uuid) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Structured Feedbacks - Feedback estruturado com checklist';

-- =====================================================
-- Tabela 2: wp_limpvix_feedback_disputes
-- =====================================================
CREATE TABLE IF NOT EXISTS wp_limpvix_feedback_disputes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feedback_uuid VARCHAR(36) NOT NULL COMMENT 'UUID do feedback',
    order_uuid VARCHAR(36) NOT NULL COMMENT 'UUID da order',

    -- Profissional que disputou
    professional_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do profissional',
    dispute_reason TEXT NOT NULL COMMENT 'Motivo da disputa',

    -- Resolução
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|in_review|resolved',
    resolution TEXT NULL COMMENT 'Decisão da arbitragem',
    resolved_by BIGINT UNSIGNED NULL COMMENT 'Admin que resolveu',
    resolved_at DATETIME NULL COMMENT 'Data de resolução',

    -- Timestamps
    created_at DATETIME NOT NULL COMMENT 'Data da disputa',

    -- Índices
    INDEX idx_feedback_uuid (feedback_uuid),
    INDEX idx_order_uuid (order_uuid),
    INDEX idx_professional_id (professional_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),

    FOREIGN KEY (feedback_uuid) REFERENCES wp_limpvix_structured_feedbacks(uuid) ON DELETE CASCADE,
    FOREIGN KEY (professional_id) REFERENCES wp_bkntc_staff(id)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Feedback Disputes - Disputas/arbitragem de feedbacks';

-- =====================================================
-- Comentários finais
-- =====================================================
--
-- OBSERVAÇÕES:
-- 1. structured_feedbacks tem UNIQUE em order_uuid (1 feedback por order)
-- 2. checklist_data é JSON com todos critérios avaliados
-- 3. photos é JSON array de URLs
-- 4. final_score é calculado como média dos critérios
-- 5. Disputas permitem arbitragem manual por admin
-- 6. Status: draft → submitted → validated OU disputed
--
-- ROLLBACK:
-- DROP TABLE IF EXISTS wp_limpvix_feedback_disputes;
-- DROP TABLE IF EXISTS wp_limpvix_structured_feedbacks;
--
-- =====================================================
