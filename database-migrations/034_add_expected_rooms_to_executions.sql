-- P1.4: Add expected_rooms_count to executions table
-- Populated from Briefing PropertyStructure during Execution creation
-- Used to validate room evidence photos at check-out

ALTER TABLE wp_limpvix_executions ADD COLUMN expected_rooms_count INT DEFAULT 0 AFTER feedback_window_expires_at;
