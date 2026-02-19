-- =====================================================
-- Migration 057: Remove legacy fields from service_catalog
-- =====================================================
--
-- CONTEXT (FASE 8 - Service Domain Refactor):
--
-- time_multiplier: FOSSIL field - PricingEngine uses hardcoded
--   COMPLEXITY_MULTIPLIERS constants (standard=1.0, detailed=1.3,
--   post_construction=1.8). This column was NEVER read by any
--   calculation code. Seed data had divergent value (1.7 for
--   post_construction vs PricingEngine's 1.8).
--   The correct time_multiplier lives in wp_limpvix_service_complexities.
--
-- required_skills: REPLACED by CapabilityRegistry system.
--   New SSOT: wp_limpvix_complexity_capabilities + wp_limpvix_additional_capabilities
--   junction tables linked to wp_limpvix_capabilities.
--
-- service_type: KEPT - external dependencies in contracts,
--   notifications, and NR06 certification checks.
--
-- @version 1.0.0
-- @since FASE 8

-- MySQL 8.0 does not support DROP COLUMN IF EXISTS.
-- These columns must exist (created in migration 009).
ALTER TABLE wp_limpvix_service_catalog
  DROP COLUMN time_multiplier,
  DROP COLUMN required_skills;

-- =====================================================
-- FIM DA MIGRATION 057
-- =====================================================
