-- =====================================================
-- Migration 038: Create API Keys Table
--
-- Tabela para gerenciar chaves de acesso a REST API
-- Cada chave tem scopes (permissoes) e expiracao
--
-- @version 1.0.0
-- @since 2026-02-19
-- =====================================================

CREATE TABLE IF NOT EXISTS wp_limpvix_api_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE COMMENT 'UUID da chave',
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'FK to wp_users.ID',
    key_hash CHAR(64) NOT NULL UNIQUE COMMENT 'SHA256 hash da chave real',
    name VARCHAR(255) NOT NULL COMMENT 'Nome amigavel da chave',
    scopes JSON NOT NULL COMMENT 'Array de permissoes: read, write, admin, etc',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at DATETIME NULL COMMENT 'Data de expiracao (NULL = sem expiracao)',
    last_used_at DATETIME NULL COMMENT 'Ultimo uso registrado',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user_id (user_id),
    INDEX idx_active (is_active),
    INDEX idx_expires_at (expires_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci
COMMENT='API Keys - Chaves de acesso a REST API LimpVix';
