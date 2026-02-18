-- Migration 031: Payout Authorization Step
-- Date: 2026-02-18
-- Purpose: Adiciona status 'authorized' e colunas de rastreio de autorizacao
--   Fluxo: pending > approved > authorized > processing > completed

ALTER TABLE `wp_limpvix_payouts`
    MODIFY COLUMN `status`
        ENUM('pending','approved','authorized','processing','completed','failed','cancelled','on_hold')
        NOT NULL DEFAULT 'pending'
        COMMENT 'Status: pending|approved|authorized|processing|completed|failed|cancelled|on_hold',
    ADD COLUMN `authorized_by`
        INT UNSIGNED DEFAULT NULL
        COMMENT 'User ID WP do responsavel que pre-aprovou o payout'
        AFTER `hold_until`,
    ADD COLUMN `authorized_at`
        DATETIME DEFAULT NULL
        COMMENT 'Timestamp da pre-aprovacao do payout'
        AFTER `authorized_by`,
    ADD INDEX `idx_payout_status_authorized` (`status`, `authorized_at`);
