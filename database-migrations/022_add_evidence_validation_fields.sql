-- Migration 022: Add Evidence Validation Fields
-- Date: 2026-02-12
-- Purpose: Support admin validation of execution evidence (GAP #4)
--
-- RESPONSABILIDADE:
-- - Permitir admin aprovar/rejeitar evidências de execução
-- - Tracking de quem validou e quando
-- - Armazenar motivo de rejeição para feedback ao professional
--
-- FLUXO:
-- 1. Professional submete evidências (fotos/vídeos) via check-out
-- 2. Evidências ficam em status 'pending' aguardando validação
-- 3. Admin valida:
--    a) Approve → evidence_status='approved', libera payout
--    b) Reject → evidence_status='rejected', bloqueia payout, notifica professional
-- 4. Execution só pode ser marcada como 'completed' se evidências aprovadas

ALTER TABLE wp_limpvix_executions
ADD COLUMN evidence_status ENUM('pending', 'approved', 'rejected') NULL DEFAULT 'pending'
    COMMENT 'Status de validação das evidências (pending até admin validar)',

ADD COLUMN evidence_validated_at DATETIME NULL
    COMMENT 'Data/hora que admin validou as evidências',

ADD COLUMN evidence_validated_by INT UNSIGNED NULL
    COMMENT 'User ID do admin que validou (FK to wp_users.ID)',

ADD COLUMN evidence_rejection_reason TEXT NULL
    COMMENT 'Motivo da rejeição (se evidence_status=rejected)',

ADD INDEX idx_evidence_status (evidence_status)
    COMMENT 'Query: listar executions com evidências pendentes de validação';

-- Verificação pós-migração
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    COLUMN_TYPE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'wp_limpvix_executions'
  AND COLUMN_NAME LIKE 'evidence%'
ORDER BY ORDINAL_POSITION;
