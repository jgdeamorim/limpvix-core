-- =====================================================
-- Migration 048: Create Professional Verifications Table
--
-- Pipeline de verificacao KYC completa do profissional
-- 4 camadas: OTP, KYC (PPID), Background Check, Risk
--
-- Nota: wp_limpvix_professional_verification (singular, migration 026)
-- ja existe para tracking basico. Esta tabela (plural) e para
-- o pipeline completo de verificacao com status final.
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_professional_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID da verificacao',
    professional_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to professionals.id',
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to wp_users.ID',

    -- Camada 1: OTP
    otp_verified TINYINT(1) NOT NULL DEFAULT 0,
    otp_provider VARCHAR(50) NULL COMMENT 'firebase|twilio|permissive',
    otp_verified_at DATETIME NULL,

    -- Camada 2: KYC (PPID)
    kyc_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
    kyc_provider VARCHAR(50) NULL COMMENT 'ppid|mock',
    kyc_result_json TEXT NULL COMMENT 'Resultado normalizado (sem dados brutos do provider)',
    kyc_checked_at DATETIME NULL,

    -- Camada 3: Background Check (Exato)
    background_status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|restricted|not_eligible',
    background_provider VARCHAR(50) NULL COMMENT 'exato|mock',
    background_result_json TEXT NULL,
    background_checked_at DATETIME NULL,
    background_expires_at DATETIME NULL,

    -- Camada 4: Risk Engine
    risk_level VARCHAR(20) NULL COMMENT 'low|medium|high',

    -- Status Final
    final_status VARCHAR(50) NOT NULL DEFAULT 'pending_verification'
        COMMENT 'pending_verification|active|active_monitored|under_review|not_eligible|suspended',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_professional (professional_id),
    INDEX idx_user_id (user_id),
    INDEX idx_final_status (final_status),
    INDEX idx_kyc_status (kyc_status),
    INDEX idx_background_expires (background_expires_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='Professional Verifications - Pipeline KYC completo (OTP + KYC + Background + Risk)';
