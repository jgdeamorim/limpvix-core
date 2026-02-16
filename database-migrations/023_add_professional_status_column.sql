-- Migration 023: Add status column to professionals table
-- Purpose: Fix missing status column that breaks queries
-- Date: 2026-02-13
-- Sprint: 8.5 (Hardening Infra)

-- Add status column (safe - will error if already exists, which is fine)
ALTER TABLE wp_limpvix_professionals
ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' 
COMMENT 'Status: active, inactive, suspended' 
AFTER email;

-- Add index for performance
ALTER TABLE wp_limpvix_professionals
ADD INDEX idx_status (status);

-- Update any existing records to active (if column was just added, all will be 'active' already due to DEFAULT)
UPDATE wp_limpvix_professionals SET status = 'active' WHERE status = '' OR status IS NULL;
