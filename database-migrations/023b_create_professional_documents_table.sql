-- Migration 023: Create Professional Documents Table
-- Date: 2026-02-16
-- Purpose: Support document upload/review for professional KYC compliance
-- GAP A: Document Upload/Review para KYC

CREATE TABLE IF NOT EXISTS wp_limpvix_professional_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Professional reference
    professional_id BIGINT UNSIGNED NOT NULL,

    -- Document identification
    document_type ENUM(
        'cpf_front',
        'cpf_back',
        'rg_front',
        'rg_back',
        'selfie',
        'proof_of_address',
        'certificate_nr35',
        'certificate_nr10',
        'certificate_nr06',
        'certificate_other'
    ) NOT NULL COMMENT 'Tipo de documento',

    -- File information
    file_path VARCHAR(500) NOT NULL COMMENT 'Path do arquivo no WordPress media library',
    attachment_id BIGINT UNSIGNED NULL COMMENT 'WordPress attachment post ID',
    mime_type VARCHAR(100) NULL,
    file_size INT UNSIGNED NULL COMMENT 'Tamanho em bytes',
    original_filename VARCHAR(255) NULL,

    -- Review status
    status ENUM('pending', 'approved', 'rejected', 'expired')
        DEFAULT 'pending'
        COMMENT 'Status da verificação',

    reviewed_by BIGINT UNSIGNED NULL COMMENT 'User ID do admin que revisou',
    reviewed_at DATETIME NULL,
    rejection_reason TEXT NULL COMMENT 'Motivo da rejeição (se rejeitado)',

    -- Expiry (para certificados)
    expires_at DATETIME NULL COMMENT 'Data de expiração (certificados)',

    -- Metadata
    metadata JSON NULL COMMENT 'Dados adicionais (ex: nome do titular, número do documento)',

    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_professional (professional_id),
    INDEX idx_status (status),
    INDEX idx_type (document_type),
    INDEX idx_expires_at (expires_at),
    INDEX idx_created_at (created_at),

    -- Foreign key
    CONSTRAINT fk_professional_documents_professional
        FOREIGN KEY (professional_id)
        REFERENCES wp_limpvix_professionals(id)
        ON DELETE CASCADE,

    CONSTRAINT fk_professional_documents_reviewer
        FOREIGN KEY (reviewed_by)
        REFERENCES wp_users(ID)
        ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Documentos enviados por profissionais para verificação KYC';
