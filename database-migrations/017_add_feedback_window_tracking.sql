-- ================================================================
-- Migration 015: Add Feedback Window Tracking
-- ================================================================
-- Purpose: Track 24-hour feedback window for customer feedback submission
--          before authorizing payout to professionals
--
-- Context: GAP #1 - Feedback Window System (P0 Critical)
--          Enforces Golden Rule: Payout only after feedback window
--          (24h for feedback submission or expiration)
--
-- Author: LimpVix Development Team
-- Date: 2026-02-10
-- Sprint: 7 - GAP #1
-- ================================================================

-- Add feedback_window_expires_at to wp_limpvix_executions
-- This column tracks when the 24-hour feedback window expires
-- NULL = window not started yet (backward compatibility)
-- NOT NULL = window active or expired
ALTER TABLE wp_limpvix_executions
ADD COLUMN feedback_window_expires_at DATETIME NULL
COMMENT '24h deadline for customer feedback submission before payout authorization';

-- Add feedback_window_expires_at to wp_limpvix_structured_feedbacks
-- Denormalization for query performance (avoid joins in feedback queries)
ALTER TABLE wp_limpvix_structured_feedbacks
ADD COLUMN feedback_window_expires_at DATETIME NULL
COMMENT 'Denormalized from executions table - window deadline for this feedback';

-- Create index for efficient cron queries
-- Used by:
-- 1. ProcessTimerExpired to check window status
-- 2. FeedbackReminderCronAdapter to send reminders
-- 3. FeedbackWindowMonitorWidget for admin dashboard
CREATE INDEX idx_feedback_window_expires
ON wp_limpvix_executions(feedback_window_expires_at);

-- ================================================================
-- Migration Verification Queries
-- ================================================================

-- Verify columns added
-- SELECT
--     COLUMN_NAME,
--     COLUMN_TYPE,
--     IS_NULLABLE,
--     COLUMN_COMMENT
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_NAME IN ('wp_limpvix_executions', 'wp_limpvix_structured_feedbacks')
--   AND COLUMN_NAME = 'feedback_window_expires_at';

-- Verify index created
-- SHOW INDEX FROM wp_limpvix_executions
-- WHERE Key_name = 'idx_feedback_window_expires';

-- ================================================================
-- Rollback (if needed)
-- ================================================================

-- ALTER TABLE wp_limpvix_executions DROP COLUMN feedback_window_expires_at;
-- ALTER TABLE wp_limpvix_structured_feedbacks DROP COLUMN feedback_window_expires_at;
-- DROP INDEX idx_feedback_window_expires ON wp_limpvix_executions;

-- ================================================================
-- Notes
-- ================================================================

-- 1. Backward Compatibility:
--    - Column is NULL by default
--    - Existing executions without window will continue to work
--    - CheckFeedbackWindowStatus returns allow_payout=true if window is NULL

-- 2. Window Logic:
--    - Window starts when execution becomes VALIDATED
--    - Expires after 24 hours
--    - If expired without feedback: auto-approve payout
--    - If feedback submitted: check score (≥3.0 = approve, <3.0 = manual review)

-- 3. Performance:
--    - Index on feedback_window_expires_at for efficient queries
--    - Expected query patterns:
--      - WHERE feedback_window_expires_at IS NOT NULL AND feedback_window_expires_at < NOW()
--      - WHERE feedback_window_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 1 HOUR)

-- 4. Data Integrity:
--    - No foreign keys (feedback_window is owned by Execution aggregate)
--    - No NOT NULL constraint (backward compatibility)
--    - No default value (explicitly set by application layer)

-- ================================================================
-- End of Migration 015
-- ================================================================
