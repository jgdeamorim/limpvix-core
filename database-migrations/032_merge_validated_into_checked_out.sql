-- P0.1: Merge VALIDATED state into CHECKED_OUT
-- Reason: Checkout with evidence IS the validation. Separate VALIDATED state removed.
-- Flow: CHECKED_OUT → CLOSED (direct, no VALIDATED intermediate)

UPDATE wp_limpvix_executions
SET status = 'checked_out'
WHERE status = 'validated';
