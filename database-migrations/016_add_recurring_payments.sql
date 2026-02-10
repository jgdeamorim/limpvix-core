-- ================================================================
-- Migration 016: Create Recurring Payments Table
-- ================================================================
-- Purpose: Track automatic recurring payments for contract renewals
--          Implements automatic payment collection for contracts with
--          auto_renew=true to prevent revenue loss
--
-- Context: GAP #2 - Recurring Payment System (P0 Critical)
--          System currently only NOTIFIES about renewal but doesn't CHARGE
--          This migration enables automatic payment processing via MercadoPago
--
-- Author: LimpVix Development Team
-- Date: 2026-02-10
-- Sprint: 8 - GAP #2
-- ================================================================

-- Create recurring_payments table
CREATE TABLE IF NOT EXISTS wp_limpvix_recurring_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_uuid VARCHAR(36) NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    billing_cycle_number INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    due_date DATE NOT NULL,
    gateway_transaction_id VARCHAR(100) NULL,
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    paid_at DATETIME NULL,
    failure_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_payment_uuid (payment_uuid),
    INDEX idx_contract_id (contract_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date),
    INDEX idx_gateway_transaction_id (gateway_transaction_id),
    UNIQUE INDEX idx_contract_cycle (contract_id, billing_cycle_number),

    CONSTRAINT fk_recurring_payment_contract
        FOREIGN KEY (contract_id)
        REFERENCES wp_limpvix_contracts(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Recurring payments for contract renewals - automatic billing';

-- ================================================================
-- Column Descriptions
-- ================================================================

-- id: Primary key, auto-increment
-- payment_uuid: UUID for external references (webhooks, API)
-- contract_id: FK to wp_limpvix_contracts (which contract this payment belongs to)
-- billing_cycle_number: Sequential counter (1st payment, 2nd payment, etc.)
-- amount: Payment amount in BRL (from contract monthly_value)
-- status: Payment lifecycle status (pending → processing → completed/failed)
-- due_date: Expected payment date (contract end_date)
-- gateway_transaction_id: MercadoPago payment ID (for reconciliation)
-- attempt_count: Retry counter (max 3 attempts)
-- paid_at: Timestamp when payment was confirmed (NULL if not paid yet)
-- failure_reason: Error message from gateway (NULL if successful)
-- created_at: Record creation timestamp
-- updated_at: Last modification timestamp (auto-updated)

-- ================================================================
-- Index Rationale
-- ================================================================

-- uk_payment_uuid: Unique constraint for UUID (external identifier)
-- idx_contract_id: Fast lookup of all payments for a contract (payment history)
-- idx_status: Fast lookup for cron jobs (find pending/failed payments)
-- idx_due_date: Fast lookup for payments due today (cron processing)
-- idx_gateway_transaction_id: Fast lookup for webhook processing (MercadoPago callbacks)
-- idx_contract_cycle: UNIQUE constraint prevents duplicate payments for same cycle (idempotency)

-- ================================================================
-- Foreign Key Constraints
-- ================================================================

-- fk_recurring_payment_contract:
--   - ON DELETE RESTRICT: Cannot delete contract if payments exist (data integrity)
--   - ON UPDATE CASCADE: If contract ID changes, update payments (unlikely but safe)

-- ================================================================
-- Status Values and State Machine
-- ================================================================

-- Status: pending
--   - Payment created, not yet sent to MercadoPago
--   - Cron will pick up and process

-- Status: processing
--   - Payment sent to MercadoPago, awaiting response
--   - Webhook will update status when payment completes

-- Status: completed
--   - Payment approved by MercadoPago
--   - Contract will be renewed (end_date extended)

-- Status: failed
--   - Payment rejected by MercadoPago
--   - Will retry if attempt_count < 3

-- Status: cancelled
--   - Payment manually cancelled by admin
--   - Terminal state, no further processing

-- State Machine:
-- pending → processing → completed (SUCCESS)
--         ↘         ↘ failed → processing (RETRY)
-- any → cancelled (MANUAL)

-- ================================================================
-- Migration Verification Queries
-- ================================================================

-- Verify table created
-- SHOW CREATE TABLE wp_limpvix_recurring_payments;

-- Verify all indexes exist
-- SHOW INDEX FROM wp_limpvix_recurring_payments;

-- Verify foreign key constraint
-- SELECT
--     CONSTRAINT_NAME,
--     TABLE_NAME,
--     REFERENCED_TABLE_NAME,
--     DELETE_RULE,
--     UPDATE_RULE
-- FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
-- WHERE TABLE_NAME = 'wp_limpvix_recurring_payments';

-- Test data insertion (do NOT run in production)
-- INSERT INTO wp_limpvix_recurring_payments
--   (payment_uuid, contract_id, billing_cycle_number, amount, status, due_date)
-- VALUES
--   (UUID(), 1, 1, 150.00, 'pending', CURDATE());

-- ================================================================
-- Rollback (if needed)
-- ================================================================

-- DROP TABLE IF EXISTS wp_limpvix_recurring_payments;

-- ================================================================
-- Notes
-- ================================================================

-- 1. Idempotency:
--    - UNIQUE constraint (contract_id, billing_cycle_number) prevents duplicate payments
--    - Cron must check if payment already exists before creating
--    - Webhook must handle duplicate callbacks (check status before updating)

-- 2. Retry Logic:
--    - Max 3 attempts (attempt_count field)
--    - Retry schedule: Day 0, +2, +5 (defined in application layer)
--    - After 3 failures: Contract status → payment_failed, admin notified

-- 3. Performance:
--    - Indexes optimized for cron queries (status, due_date)
--    - Indexes optimized for webhook queries (gateway_transaction_id)
--    - Expected volume: ~100-1000 payments/month initially

-- 4. Data Integrity:
--    - FK constraint prevents orphaned payments
--    - UNIQUE constraint prevents duplicate billing
--    - NOT NULL on critical fields (amount, contract_id, etc.)

-- 5. Backward Compatibility:
--    - New table, does not affect existing data
--    - Existing contracts continue working normally
--    - Only new renewals will create recurring_payments

-- 6. Security:
--    - payment_uuid prevents guessing (UUID format)
--    - gateway_transaction_id allows verification with MercadoPago
--    - failure_reason stored for audit trail

-- ================================================================
-- End of Migration 016
-- ================================================================
