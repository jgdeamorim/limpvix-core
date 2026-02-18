-- ================================================================
-- Migration 029: Add Execution Fields to Recurring Payments Table
-- ================================================================
-- Purpose: Adapt recurring payment model for on-demand marketplace.
--          Instead of calendar-based monthly billing, charges are now
--          per-execution and aligned with contract.next_execution_date.
--
-- Context: LimpVix is an on-demand platform. A weekly contract may have
--          4 different professionals in 4 weeks (dynamic matching). Each
--          execution requires its own PIX QR Code and charge.
--
-- Billing model (new):
--   - weekly   → ~4 charges/month, each at monthlyValue / 4.33
--   - biweekly → ~2 charges/month, each at monthlyValue / 2.16
--   - monthly  → 1 charge/month at monthlyValue
--
-- Trigger (new): next_execution_date <= today + 3 days
-- Trigger (old): end_date <= today + 3 days
--
-- Author: LimpVix Development Team
-- Date: 2026-02-17
-- ================================================================

ALTER TABLE wp_limpvix_recurring_payments
    ADD COLUMN execution_scheduled_date DATE NULL
        COMMENT 'Data da execução para qual esta cobrança se refere',

    ADD COLUMN pix_qrcode TEXT NULL
        COMMENT 'PIX copia-e-cola gerado pelo EFI Bank (cob.write)',

    ADD COLUMN pix_qrimage TEXT NULL
        COMMENT 'QR Code PNG em base64 gerado pelo EFI Bank',

    ADD COLUMN payment_provider VARCHAR(20) NOT NULL DEFAULT 'efipay'
        COMMENT 'Gateway: efipay | mercadopago',

    ADD INDEX idx_execution_scheduled_date (execution_scheduled_date);

-- ================================================================
-- Rollback (if needed)
-- ================================================================
-- ALTER TABLE wp_limpvix_recurring_payments
--   DROP INDEX idx_execution_scheduled_date,
--   DROP COLUMN execution_scheduled_date,
--   DROP COLUMN pix_qrcode,
--   DROP COLUMN pix_qrimage,
--   DROP COLUMN payment_provider;

-- ================================================================
-- End of Migration 029
-- ================================================================
