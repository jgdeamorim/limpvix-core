-- Migration 024: Create user_verifications table
-- Purpose: Enterprise-grade phone/email verification tracking
-- Date: 2026-02-14
-- Sprint: 9 (OTP Verification)

-- Create user verifications table
CREATE TABLE IF NOT EXISTS wp_limpvix_user_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    
    -- User reference
    user_id BIGINT UNSIGNED NOT NULL
        COMMENT 'WordPress user ID',
    
    -- Contact information
    phone_number VARCHAR(20) NULL
        COMMENT 'E.164 format: +5511999999999',
    
    email VARCHAR(255) NULL
        COMMENT 'Email address for fallback verification',
    
    -- Verification status
    phone_verified BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'TRUE if phone verified via OTP',
    
    email_verified BOOLEAN NOT NULL DEFAULT FALSE
        COMMENT 'TRUE if email verified (optional)',
    
    phone_verified_at DATETIME NULL
        COMMENT 'Timestamp when phone was verified',
    
    email_verified_at DATETIME NULL
        COMMENT 'Timestamp when email was verified',
    
    -- OTP tracking
    last_request_id VARCHAR(100) NULL
        COMMENT 'NVoip OTP request ID (for verify)',
    
    last_channel VARCHAR(20) NULL
        COMMENT 'Last channel used: whatsapp, sms, email',
    
    -- Security & rate limiting
    otp_attempts INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Failed verification attempts counter',
    
    otp_send_count INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Number of OTP sends in current window',
    
    otp_send_window_start DATETIME NULL
        COMMENT 'Start of current rate limit window',
    
    blocked_until DATETIME NULL
        COMMENT 'User is blocked from sending OTP until this time',
    
    -- Audit
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'Record creation timestamp',
    
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'Last update timestamp',
    
    PRIMARY KEY (id),
    UNIQUE KEY idx_user_id (user_id),
    INDEX idx_phone_verified (phone_verified),
    INDEX idx_phone_number (phone_number),
    INDEX idx_blocked_until (blocked_until),
    
    CONSTRAINT fk_user_verifications_user
        FOREIGN KEY (user_id)
        REFERENCES wp_users(ID)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='User verification tracking (phone/email OTP)';

-- Initialize verification records for existing users
INSERT INTO wp_limpvix_user_verifications (user_id, phone_number, phone_verified, created_at)
SELECT
    wp_users.ID,
    wp_usermeta.meta_value,
    FALSE,
    NOW()
FROM wp_users
LEFT JOIN wp_usermeta ON wp_users.ID = wp_usermeta.user_id AND wp_usermeta.meta_key = 'phone_number'
WHERE NOT EXISTS (
    SELECT 1 FROM wp_limpvix_user_verifications WHERE wp_limpvix_user_verifications.user_id = wp_users.ID
);
