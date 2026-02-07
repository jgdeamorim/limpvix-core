<?php
/**
 * Migration 009: Structured Feedback Tables
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
    require_once ABSPATH . 'wp-load.php';
}

global $wpdb;

echo "Executando Migration 009: Structured Feedback Tables\n\n";

// =====================================================
// Tabela 1: wp_limpvix_structured_feedbacks
// =====================================================
$sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}limpvix_structured_feedbacks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(36) NOT NULL UNIQUE COMMENT 'UUID do feedback',
    order_uuid VARCHAR(36) NOT NULL COMMENT 'UUID da order',
    customer_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do cliente',

    service_category VARCHAR(50) NOT NULL COMMENT 'limpeza_basica|pos_obra|teto|esquadrias',
    checklist_data JSON NOT NULL COMMENT 'Checklist completo (critérios + scores)',

    photos JSON NOT NULL COMMENT 'Array de URLs de fotos',
    general_comment TEXT NULL COMMENT 'Comentário geral opcional',

    final_score DECIMAL(3,2) NOT NULL COMMENT 'Score médio (1.00-5.00)',
    status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|submitted|validated|disputed',

    created_at DATETIME NOT NULL COMMENT 'Data de criação',
    submitted_at DATETIME NULL COMMENT 'Data de submissão',
    validated_at DATETIME NULL COMMENT 'Data de validação',

    UNIQUE KEY unique_order_feedback (order_uuid),
    INDEX idx_uuid (uuid),
    INDEX idx_customer_id (customer_id),
    INDEX idx_status (status),
    INDEX idx_final_score (final_score),
    INDEX idx_service_category (service_category),
    INDEX idx_submitted_at (submitted_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Structured Feedbacks - Feedback estruturado com checklist';";

$result1 = $wpdb->query($sql1);
echo ($result1 !== false ? "✅" : "❌") . " wp_limpvix_structured_feedbacks\n";
if ($result1 === false) {
    echo "   Error: " . $wpdb->last_error . "\n";
}

// =====================================================
// Tabela 2: wp_limpvix_feedback_disputes
// =====================================================
$sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}limpvix_feedback_disputes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feedback_uuid VARCHAR(36) NOT NULL COMMENT 'UUID do feedback',
    order_uuid VARCHAR(36) NOT NULL COMMENT 'UUID da order',

    professional_id BIGINT UNSIGNED NOT NULL COMMENT 'ID do profissional',
    dispute_reason TEXT NOT NULL COMMENT 'Motivo da disputa',

    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|in_review|resolved',
    resolution TEXT NULL COMMENT 'Decisão da arbitragem',
    resolved_by BIGINT UNSIGNED NULL COMMENT 'Admin que resolveu',
    resolved_at DATETIME NULL COMMENT 'Data de resolução',

    created_at DATETIME NOT NULL COMMENT 'Data da disputa',

    INDEX idx_feedback_uuid (feedback_uuid),
    INDEX idx_order_uuid (order_uuid),
    INDEX idx_professional_id (professional_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Feedback Disputes - Disputas/arbitragem de feedbacks';";

$result2 = $wpdb->query($sql2);
echo ($result2 !== false ? "✅" : "❌") . " wp_limpvix_feedback_disputes\n";
if ($result2 === false) {
    echo "   Error: " . $wpdb->last_error . "\n";
}

echo "\n✅ Migration 009 completa!\n";

// Verificar tabelas criadas
$tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}limpvix_%feedback%'", ARRAY_N);
echo "\nTabelas criadas:\n";
foreach ($tables as $table) {
    echo "  - {$table[0]}\n";
}
