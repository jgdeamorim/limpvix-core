-- Migration 051: Add raw_response column to payouts table for full audit trail
-- Stores the complete API response from EFI Bank for each payout transaction

ALTER TABLE wp_limpvix_payouts
ADD COLUMN raw_response LONGTEXT NULL DEFAULT NULL AFTER failure_reason;
