-- Migration 027: Payout Dual-Mode Fields
-- Date: 2026-02-17
-- Purpose: Adicionar colunas necessárias para o sistema dual-mode de payouts:
--          MP OAuth (automático por profissional) + PIX Manual (admin)
--
-- Tabelas afetadas:
--   wp_limpvix_professionals → campos OAuth MP por profissional
--   wp_limpvix_payouts       → campos de método, retry e controle manual

-- ──────────────────────────────────────────────────────────────────────────────
-- 1. wp_limpvix_professionals — MercadoPago OAuth por profissional
-- ──────────────────────────────────────────────────────────────────────────────

ALTER TABLE `wp_limpvix_professionals`

    ADD COLUMN IF NOT EXISTS `preferred_payout_method`
        ENUM('mp_oauth', 'pix_manual') NOT NULL DEFAULT 'pix_manual'
        COMMENT 'Método preferido de recebimento do profissional'
        AFTER `pix_key_type`,

    ADD COLUMN IF NOT EXISTS `mp_oauth_status`
        ENUM('connected', 'expired', 'revoked', 'not_connected') NOT NULL DEFAULT 'not_connected'
        COMMENT 'Status da conexão OAuth com MercadoPago'
        AFTER `preferred_payout_method`,

    ADD COLUMN IF NOT EXISTS `mp_access_token`
        TEXT DEFAULT NULL
        COMMENT 'Access token OAuth do profissional (encrypted)'
        AFTER `mp_oauth_status`,

    ADD COLUMN IF NOT EXISTS `mp_refresh_token`
        TEXT DEFAULT NULL
        COMMENT 'Refresh token OAuth do profissional (encrypted)'
        AFTER `mp_access_token`,

    ADD COLUMN IF NOT EXISTS `mp_user_id`
        VARCHAR(100) DEFAULT NULL
        COMMENT 'MercadoPago user_id obtido via OAuth'
        AFTER `mp_refresh_token`,

    ADD COLUMN IF NOT EXISTS `mp_oauth_connected_at`
        DATETIME DEFAULT NULL
        COMMENT 'Timestamp quando profissional conectou OAuth'
        AFTER `mp_user_id`,

    ADD COLUMN IF NOT EXISTS `mp_oauth_expires_at`
        DATETIME DEFAULT NULL
        COMMENT 'Quando o access token expira (180 dias após emissão)'
        AFTER `mp_oauth_connected_at`;

-- Índices de suporte
CREATE INDEX IF NOT EXISTS `idx_prof_payout_method`
    ON `wp_limpvix_professionals` (`preferred_payout_method`);

CREATE INDEX IF NOT EXISTS `idx_prof_mp_oauth_status`
    ON `wp_limpvix_professionals` (`mp_oauth_status`);

-- ──────────────────────────────────────────────────────────────────────────────
-- 2. wp_limpvix_payouts — método de payout, retry logic e controle PIX manual
-- ──────────────────────────────────────────────────────────────────────────────

ALTER TABLE `wp_limpvix_payouts`

    -- Método de payout
    ADD COLUMN IF NOT EXISTS `payout_method`
        ENUM('mp_oauth', 'pix_manual') NOT NULL DEFAULT 'pix_manual'
        COMMENT 'mp_oauth = automático via OAuth do profissional; pix_manual = admin processa'
        AFTER `status`,

    -- Retry logic (para falhas de MP OAuth)
    ADD COLUMN IF NOT EXISTS `retry_count`
        TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Número de tentativas de reprocessamento realizadas'
        AFTER `payout_method`,

    ADD COLUMN IF NOT EXISTS `max_retries`
        TINYINT UNSIGNED NOT NULL DEFAULT 3
        COMMENT 'Número máximo de tentativas antes de marcar como definitivamente falho'
        AFTER `retry_count`,

    -- Controle de PIX manual (admin marca como pago)
    ADD COLUMN IF NOT EXISTS `manually_marked_paid_by`
        INT UNSIGNED DEFAULT NULL
        COMMENT 'User ID do admin que confirmou o pagamento PIX'
        AFTER `max_retries`,

    ADD COLUMN IF NOT EXISTS `manually_marked_paid_at`
        DATETIME DEFAULT NULL
        COMMENT 'Timestamp da confirmação manual do PIX'
        AFTER `manually_marked_paid_by`,

    ADD COLUMN IF NOT EXISTS `manual_payment_proof`
        TEXT DEFAULT NULL
        COMMENT 'Comprovante ou descrição do pagamento PIX realizado'
        AFTER `manually_marked_paid_at`,

    -- Hold period (ex: aguardar feedback antes de liberar)
    ADD COLUMN IF NOT EXISTS `hold_until`
        DATETIME DEFAULT NULL
        COMMENT 'Payout só pode ser processado após esta data/hora'
        AFTER `manual_payment_proof`;

-- Índices de suporte
CREATE INDEX IF NOT EXISTS `idx_payout_method`
    ON `wp_limpvix_payouts` (`payout_method`);

CREATE INDEX IF NOT EXISTS `idx_payout_hold_until`
    ON `wp_limpvix_payouts` (`hold_until`);
