-- Migration 024: Add Manual Payout Fields
-- Date: 2026-02-16
-- Purpose: Add audit trail fields for manual payouts (GAP C)
-- Dependencies: Requires wp_limpvix_payouts table (created in previous migrations)

-- Add manual payout audit trail fields
ALTER TABLE wp_limpvix_payouts
ADD COLUMN IF NOT EXISTS is_manual BOOLEAN DEFAULT FALSE
    COMMENT 'TRUE se foi criado manualmente por admin (não automático)',

ADD COLUMN IF NOT EXISTS manual_reason TEXT NULL
    COMMENT 'Motivo do payout manual (ex: bonificação, correção, ajuste)',

ADD COLUMN IF NOT EXISTS created_by INT UNSIGNED NULL
    COMMENT 'User ID do admin que criou o payout manual',

ADD COLUMN IF NOT EXISTS approved_by INT UNSIGNED NULL
    COMMENT 'User ID do admin que aprovou o payout manual (4-eyes)',

ADD COLUMN IF NOT EXISTS approved_manually_at DATETIME NULL
    COMMENT 'Timestamp quando foi aprovado manualmente',

ADD COLUMN IF NOT EXISTS requires_approval BOOLEAN DEFAULT FALSE
    COMMENT 'TRUE se requer aprovação de segundo admin (4-eyes policy)',

ADD INDEX IF NOT EXISTS idx_is_manual (is_manual),
ADD INDEX IF NOT EXISTS idx_requires_approval (requires_approval),
ADD INDEX IF NOT EXISTS idx_created_by (created_by),
ADD INDEX IF NOT EXISTS idx_approved_by (approved_by);

-- Add new status for manual payouts pending approval
-- Note: Status column is ENUM, so we need to alter it
ALTER TABLE wp_limpvix_payouts
MODIFY COLUMN status ENUM(
    'pending',
    'approved',
    'processing',
    'completed',
    'failed',
    'on_hold',
    'cancelled',
    'manual_pending'
) DEFAULT 'pending'
COMMENT 'Status do payout. manual_pending = aguardando aprovação 4-eyes';

-- Create audit trail table for manual payout actions
CREATE TABLE IF NOT EXISTS wp_limpvix_payout_audit_trail (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payout_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL COMMENT 'created, approved, rejected, processed, cancelled',
    performed_by INT UNSIGNED NOT NULL COMMENT 'User ID do admin que executou a ação',
    reason TEXT NULL COMMENT 'Motivo da ação (obrigatório para rejeição)',
    metadata JSON NULL COMMENT 'Dados adicionais da ação',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_payout (payout_id),
    INDEX idx_performed_by (performed_by),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),

    FOREIGN KEY (payout_id)
        REFERENCES wp_limpvix_payouts(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Audit trail de ações em payouts manuais (GAP C)';
