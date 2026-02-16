-- Migration 020: Add KYC (Know Your Customer) Fields to Professionals
-- Date: 2026-02-11
-- Purpose: Biometric identity verification with PPID integration
--
-- KYC Status Flow:
--   not_started → pending → processing → approved/rejected → expired (se certificado expirar)
--
-- Security:
--   - Documentos armazenados em S3 (URLs apenas)
--   - PPID response data em JSON
--   - Dados sensíveis (CPF, RG) já existem em outras colunas

-- Add KYC status and verification fields
ALTER TABLE wp_limpvix_professionals

-- KYC Status
ADD COLUMN kyc_status ENUM(
    'not_started',    -- Profissional ainda não iniciou KYC
    'pending',        -- Documentos enviados, aguardando processamento
    'processing',     -- PPID processando OCR/Liveness/FaceMatch
    'approved',       -- KYC aprovado, profissional verificado
    'rejected',       -- KYC rejeitado (documentos inválidos, face mismatch, etc)
    'expired'         -- KYC aprovado mas expirou (revalidação necessária)
) DEFAULT 'not_started'
    COMMENT 'Status do processo de verificação biométrica KYC',

-- KYC Timestamps
ADD COLUMN kyc_started_at DATETIME NULL
    COMMENT 'Quando profissional iniciou processo KYC',

ADD COLUMN kyc_submitted_at DATETIME NULL
    COMMENT 'Quando documentos foram enviados para validação',

ADD COLUMN kyc_approved_at DATETIME NULL
    COMMENT 'Quando KYC foi aprovado',

ADD COLUMN kyc_rejected_at DATETIME NULL
    COMMENT 'Quando KYC foi rejeitado',

ADD COLUMN kyc_expires_at DATETIME NULL
    COMMENT 'Data de expiração do KYC aprovado (revalidação periódica)',

-- Document URLs (stored in S3 or WordPress uploads)
ADD COLUMN kyc_document_url VARCHAR(500) NULL
    COMMENT 'URL do documento enviado (RG ou CNH)',

ADD COLUMN kyc_selfie_url VARCHAR(500) NULL
    COMMENT 'URL da selfie com liveness',

-- PPID API Response Data (JSON)
ADD COLUMN kyc_ocr_data JSON NULL
    COMMENT 'Dados extraídos do OCR (nome, CPF, data nascimento, etc)',

ADD COLUMN kyc_liveness_data JSON NULL
    COMMENT 'Resultado do liveness detection (score, anti-spoofing)',

ADD COLUMN kyc_facematch_data JSON NULL
    COMMENT 'Resultado do face match (similaridade documento vs selfie)',

-- Rejection / Notes
ADD COLUMN kyc_rejection_reason TEXT NULL
    COMMENT 'Motivo da rejeição (se kyc_status = rejected)',

ADD COLUMN kyc_admin_notes TEXT NULL
    COMMENT 'Notas do admin sobre o processo KYC',

ADD COLUMN kyc_approved_by INT UNSIGNED NULL
    COMMENT 'User ID do admin que aprovou manualmente (se aplicável)',

ADD COLUMN kyc_rejected_by INT UNSIGNED NULL
    COMMENT 'User ID do admin que rejeitou manualmente (se aplicável)',

-- Document Classification
ADD COLUMN kyc_document_type ENUM('rg', 'cnh', 'passport', 'other') NULL
    COMMENT 'Tipo de documento enviado (identificado pelo PPID Classification)',

-- Retry tracking
ADD COLUMN kyc_retry_count INT UNSIGNED DEFAULT 0
    COMMENT 'Número de tentativas de KYC (limitar a 3 tentativas)',

ADD COLUMN kyc_last_retry_at DATETIME NULL
    COMMENT 'Data da última tentativa de reenvio',

-- Indexes for performance
ADD INDEX idx_kyc_status (kyc_status),
ADD INDEX idx_kyc_approved_at (kyc_approved_at),
ADD INDEX idx_kyc_expires_at (kyc_expires_at);

-- Add foreign key for approved_by and rejected_by (WordPress users table)
-- Note: WordPress doesn't enforce FK constraints by default, but we document it
-- ALTER TABLE wp_limpvix_professionals
-- ADD CONSTRAINT fk_kyc_approved_by FOREIGN KEY (kyc_approved_by) REFERENCES wp_users(ID) ON DELETE SET NULL,
-- ADD CONSTRAINT fk_kyc_rejected_by FOREIGN KEY (kyc_rejected_by) REFERENCES wp_users(ID) ON DELETE SET NULL;

-- ============================================================================
-- BUSINESS RULES (enforced by application layer)
-- ============================================================================
--
-- 1. Professional CANNOT accept offers if kyc_status != 'approved'
-- 2. KYC expires after 24 months (kyc_expires_at)
-- 3. Max 3 retry attempts (kyc_retry_count <= 3)
-- 4. If KYC rejected 3 times, admin must review manually
-- 5. Selfie liveness score must be >= 80% (configurable)
-- 6. Face match similarity must be >= 90% (configurable)
-- 7. OCR confidence must be >= 85% (configurable)
--
-- ============================================================================

-- Example: Find professionals with pending KYC
-- SELECT id, name, email, kyc_status, kyc_submitted_at
-- FROM wp_limpvix_professionals
-- WHERE kyc_status = 'pending'
-- ORDER BY kyc_submitted_at ASC;

-- Example: Find professionals with expired KYC
-- SELECT id, name, email, kyc_status, kyc_expires_at
-- FROM wp_limpvix_professionals
-- WHERE kyc_status = 'approved'
--   AND kyc_expires_at < NOW();

-- Example: Find professionals ready to work (KYC approved and not expired)
-- SELECT id, name, email, kyc_status, kyc_approved_at, kyc_expires_at
-- FROM wp_limpvix_professionals
-- WHERE kyc_status = 'approved'
--   AND (kyc_expires_at IS NULL OR kyc_expires_at > NOW());
