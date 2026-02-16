-- Migration: Create wp_limpvix_executions table
-- Sprint 1 - Dia 5: Persistência de Execution Aggregate
-- Author: Claude Sonnet 4.5
-- Date: 2026-02-08

CREATE TABLE IF NOT EXISTS wp_limpvix_executions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_uuid CHAR(36) NOT NULL UNIQUE,
    order_uuid CHAR(36) NOT NULL,
    professional_id BIGINT UNSIGNED NOT NULL,

    -- Status (ExecutionStatusEnum)
    status VARCHAR(50) NOT NULL DEFAULT 'created',

    -- Scheduled data (for SLA validation)
    scheduled_start_time DATETIME NULL,
    service_location JSON NULL COMMENT 'GeoLocation: {latitude, longitude, address}',
    geofence_radius_meters INT NOT NULL DEFAULT 150,

    -- Check-in data
    check_in_at DATETIME NULL,
    check_in_geo JSON NULL COMMENT 'GeoLocation: {latitude, longitude, address}',

    -- Check-out data
    check_out_at DATETIME NULL,
    check_out_geo JSON NULL COMMENT 'GeoLocation: {latitude, longitude, address}',
    evidence JSON NULL COMMENT 'EvidenceCollection: [{type, url, uploadedAt}, ...]',

    -- SLA tracking
    sla_violations JSON NULL COMMENT 'Array of SlaViolation: [{reason, timestamp, metadata}, ...]',

    -- Timestamps
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indices
    INDEX idx_execution_uuid (execution_uuid),
    INDEX idx_order_uuid (order_uuid),
    INDEX idx_professional_id (professional_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_start_time (scheduled_start_time)

    -- Foreign key removed for maximum compatibility (soft reference)
    -- Referential integrity enforced at application layer
    -- CONSTRAINT fk_execution_order FOREIGN KEY (order_uuid) REFERENCES wp_limpvix_orders(uuid)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

-- Comentários para documentação
ALTER TABLE wp_limpvix_executions COMMENT = 'Execution Aggregate - Track service execution with check-in/checkout + geo + SLA (Sprint 1)';
