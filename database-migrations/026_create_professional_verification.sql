-- Migration 026: Professional Trust & Eligibility Pipeline
-- Date: 2026-02-17
-- Purpose: Aggregate de verificação profissional em 4 camadas
--          (OTP → KYC → Background → Risk Engine)

-- ─── Tabela principal: pipeline de verificação ───────────────────────────────
CREATE TABLE IF NOT EXISTS wp_limpvix_professional_verification (
    id                      CHAR(36)     NOT NULL PRIMARY KEY,
    user_id                 BIGINT UNSIGNED NOT NULL,

    -- Camada 1: OTP
    otp_verified            TINYINT(1)   NOT NULL DEFAULT 0,
    otp_verified_at         DATETIME     NULL,

    -- Camada 2: KYC
    kyc_status              ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    kyc_result_json         TEXT         NULL COMMENT 'KycResult normalizado — sem dados brutos do provedor',
    kyc_provider            VARCHAR(50)  NULL,
    kyc_checked_at          DATETIME     NULL,

    -- Camada 3: Background Check
    background_status       ENUM('PENDING','APPROVED','RESTRICTED','NOT_ELIGIBLE') NOT NULL DEFAULT 'PENDING',
    background_result_json  TEXT         NULL COMMENT 'BackgroundResult normalizado — sem dados brutos da Exato',
    background_provider     VARCHAR(50)  NULL,
    background_checked_at   DATETIME     NULL,
    background_expires_at   DATETIME     NULL,

    -- Camada 4: Risk Engine
    risk_level              ENUM('LOW','MEDIUM','HIGH') NULL,

    -- Status Final
    final_status            ENUM(
                                'PENDING_VERIFICATION',
                                'ACTIVE',
                                'ACTIVE_MONITORED',
                                'UNDER_REVIEW',
                                'NOT_ELIGIBLE',
                                'SUSPENDED'
                            ) NOT NULL DEFAULT 'PENDING_VERIFICATION',

    -- Timestamps
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_id      (user_id),
    INDEX idx_final_status (final_status),
    INDEX idx_bg_expires   (background_expires_at),
    INDEX idx_kyc_status   (kyc_status)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── Tabela de consentimento LGPD ────────────────────────────────────────────
-- LGPD Art. 7 — background check exige consentimento separado dos Termos Gerais
CREATE TABLE IF NOT EXISTS wp_limpvix_consent_records (
    id               CHAR(36)     NOT NULL PRIMARY KEY,
    user_id          BIGINT UNSIGNED NOT NULL,

    consent_type     ENUM('BACKGROUND_CHECK','TERMS_OF_SERVICE','PRIVACY_POLICY') NOT NULL,
    consent_version  VARCHAR(20)  NOT NULL COMMENT 'Ex: 1.0, 2.1',
    accepted_at      DATETIME     NOT NULL,
    ip_address       VARCHAR(45)  NULL,
    hash_snapshot    CHAR(64)     NOT NULL COMMENT 'SHA-256 do texto exibido ao usuário no momento do aceite',

    INDEX idx_user_consent  (user_id, consent_type),
    INDEX idx_accepted_at   (accepted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Nota: sem UPDATE/DELETE — registros de consentimento são imutáveis (auditoria)
