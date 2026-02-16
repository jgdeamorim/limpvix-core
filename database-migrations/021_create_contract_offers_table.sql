-- Migration 021: Create Contract Offers Table
-- Date: 2026-02-12
-- Purpose: Support professional matching and offer system (GAP #3)
--
-- RESPONSABILIDADE:
-- - Armazenar ofertas de contratos enviadas para profissionais
-- - Tracking de status (pending, accepted, rejected, expired)
-- - Match score para auditoria de algoritmo de matching
-- - Suporte para first-to-accept pattern (race condition handling)
--
-- FLUXO:
-- 1. SendOffers cria N ofertas (status=pending, expires_at=+24h)
-- 2. AcceptOffer: primeira aceitação → outras expiram automaticamente
-- 3. Cron job: expira ofertas não respondidas após 24h

CREATE TABLE IF NOT EXISTS wp_limpvix_contract_offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Foreign keys
    contract_id INT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_contracts.id',
    professional_id INT UNSIGNED NOT NULL COMMENT 'FK to wp_limpvix_professionals.id (PK, NOT user_id)',

    -- Offer details
    proposed_amount DECIMAL(10,2) NOT NULL COMMENT 'Valor proposto para o serviço',
    status ENUM('pending', 'accepted', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL COMMENT 'Data/hora de expiração (24h após criação)',

    -- Matching metadata
    match_score DECIMAL(5,2) NULL COMMENT 'Score do algoritmo de matching (0-100)',
    match_metadata JSON NULL COMMENT 'Breakdown do score (proximity, prof_score, acceptance_rate, experience)',

    -- Response tracking
    responded_at DATETIME NULL COMMENT 'Data/hora que professional respondeu (accepted/rejected)',
    rejection_reason VARCHAR(255) NULL COMMENT 'Motivo da rejeição (se status=rejected)',

    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_contract_id (contract_id),
    INDEX idx_professional_id (professional_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at),
    INDEX idx_created_at (created_at),
    INDEX idx_contract_status (contract_id, status) COMMENT 'Query: offers pendentes por contrato',
    INDEX idx_professional_status (professional_id, status) COMMENT 'Query: offers pendentes por profissional',

    -- Foreign key constraints
    CONSTRAINT fk_offers_contract
        FOREIGN KEY (contract_id)
        REFERENCES wp_limpvix_contracts(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT fk_offers_professional
        FOREIGN KEY (professional_id)
        REFERENCES wp_limpvix_professionals(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Contract offers sent to professionals for matching and allocation';

-- Verificação pós-criação
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME,
    TABLE_COMMENT
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'wp_limpvix_contract_offers';
