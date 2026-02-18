-- Migration 027: Payout Dual-Mode Fields
-- Date: 2026-02-17
-- Purpose: Adicionar colunas para o sistema dual-mode de payouts
-- Nota: Migrations sao executadas apenas uma vez pelo MigrationRunner

-- 1. wp_limpvix_professionals: campos OAuth MP por profissional
ALTER TABLE `wp_limpvix_professionals`
    ADD COLUMN `preferred_payout_method`
        ENUM('mp_oauth', 'pix_manual') NOT NULL DEFAULT 'pix_manual'
        COMMENT 'Metodo preferido de recebimento' AFTER `pix_key_type`,
    ADD COLUMN `mp_oauth_status`
        ENUM('connected', 'expired', 'revoked', 'not_connected') NOT NULL DEFAULT 'not_connected'
        COMMENT 'Status OAuth MercadoPago' AFTER `preferred_payout_method`,
    ADD COLUMN `mp_access_token`
        TEXT DEFAULT NULL
        COMMENT 'Access token OAuth encrypted' AFTER `mp_oauth_status`,
    ADD COLUMN `mp_refresh_token`
        TEXT DEFAULT NULL
        COMMENT 'Refresh token OAuth encrypted' AFTER `mp_access_token`,
    ADD COLUMN `mp_user_id`
        VARCHAR(100) DEFAULT NULL
        COMMENT 'MercadoPago user_id via OAuth' AFTER `mp_refresh_token`,
    ADD COLUMN `mp_oauth_connected_at`
        DATETIME DEFAULT NULL
        COMMENT 'Timestamp conexao OAuth' AFTER `mp_user_id`,
    ADD COLUMN `mp_oauth_expires_at`
        DATETIME DEFAULT NULL
        COMMENT 'Expiracao do access token' AFTER `mp_oauth_connected_at`,
    ADD INDEX `idx_prof_payout_method` (`preferred_payout_method`),
    ADD INDEX `idx_prof_mp_oauth_status` (`mp_oauth_status`);

-- 2. wp_limpvix_payouts: metodo, retry logic e controle PIX manual
ALTER TABLE `wp_limpvix_payouts`
    ADD COLUMN `payout_method`
        ENUM('mp_oauth', 'pix_manual') NOT NULL DEFAULT 'pix_manual'
        COMMENT 'Metodo de payout' AFTER `status`,
    ADD COLUMN `retry_count`
        TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Tentativas realizadas' AFTER `payout_method`,
    ADD COLUMN `max_retries`
        TINYINT UNSIGNED NOT NULL DEFAULT 3
        COMMENT 'Max tentativas permitidas' AFTER `retry_count`,
    ADD COLUMN `manually_marked_paid_by`
        INT UNSIGNED DEFAULT NULL
        COMMENT 'User ID admin que confirmou PIX' AFTER `max_retries`,
    ADD COLUMN `manually_marked_paid_at`
        DATETIME DEFAULT NULL
        COMMENT 'Timestamp confirmacao PIX' AFTER `manually_marked_paid_by`,
    ADD COLUMN `manual_payment_proof`
        TEXT DEFAULT NULL
        COMMENT 'Comprovante pagamento PIX' AFTER `manually_marked_paid_at`,
    ADD COLUMN `hold_until`
        DATETIME DEFAULT NULL
        COMMENT 'Payout liberado apos esta data' AFTER `manual_payment_proof`,
    ADD INDEX `idx_payout_method` (`payout_method`),
    ADD INDEX `idx_payout_hold_until` (`hold_until`);
