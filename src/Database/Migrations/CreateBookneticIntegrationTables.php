<?php
/**
 * CreateBookneticIntegrationTables - Migração de BD
 *
 * Cria tabelas necessárias para integração Booknetic × Limpvix
 *
 * Tabelas:
 * - limpvix_appointment_order_map (mapeamento appointment ↔ order)
 * - limpvix_financial_context (contexto financeiro das orders)
 *
 * @package LimpVix\Database\Migrations
 */

namespace LimpVix\Database\Migrations;

defined('ABSPATH') || exit;

final class CreateBookneticIntegrationTables
{
    /**
     * Executar migração
     */
    public static function up(): void
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        // Tabela 1: Mapeamento Appointment ↔ Order
        $tableName1 = $wpdb->prefix . 'limpvix_appointment_order_map';
        
        $sql1 = "CREATE TABLE IF NOT EXISTS `{$tableName1}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `appointment_id` BIGINT(20) UNSIGNED NOT NULL,
            `order_uuid` VARCHAR(64) NOT NULL,
            `customer_id` BIGINT(20) UNSIGNED NULL,
            `staff_id` BIGINT(20) UNSIGNED NULL,
            `price` DECIMAL(10,2) DEFAULT 0.00,
            `status` VARCHAR(50) DEFAULT 'pending',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_appointment` (`appointment_id`),
            UNIQUE KEY `unique_order_uuid` (`order_uuid`),
            KEY `idx_customer_id` (`customer_id`),
            KEY `idx_staff_id` (`staff_id`),
            KEY `idx_status` (`status`)
        ) {$charsetCollate};";

        // Tabela 2: Contexto Financeiro
        $tableName2 = $wpdb->prefix . 'limpvix_financial_context';
        
        $sql2 = "CREATE TABLE IF NOT EXISTS `{$tableName2}` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_uuid` VARCHAR(64) NOT NULL,
            `customer_id` BIGINT(20) UNSIGNED NOT NULL,
            `professional_id` BIGINT(20) UNSIGNED NULL,
            `appointment_id` BIGINT(20) UNSIGNED NULL,
            `briefing_completed` TINYINT(1) DEFAULT 0,
            `briefing_data` LONGTEXT NULL COMMENT 'JSON com dados do briefing',
            `service_complexity` VARCHAR(20) DEFAULT 'medium',
            `feedback_rating` TINYINT(1) NULL COMMENT '1-5 estrelas ou NULL',
            `feedback_comment` TEXT NULL,
            `feedback_timestamp` DATETIME NULL,
            `has_dispute` TINYINT(1) DEFAULT 0,
            `professional_valid` TINYINT(1) DEFAULT 1,
            `previous_payout` TINYINT(1) DEFAULT 0,
            `timer_started_at` DATETIME NULL,
            `timer_duration_hours` INT(11) DEFAULT 24,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_order_uuid` (`order_uuid`),
            KEY `idx_customer_id` (`customer_id`),
            KEY `idx_professional_id` (`professional_id`),
            KEY `idx_appointment_id` (`appointment_id`),
            KEY `idx_feedback_rating` (`feedback_rating`),
            KEY `idx_has_dispute` (`has_dispute`)
        ) {$charsetCollate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        dbDelta($sql1);
        dbDelta($sql2);

        // Registrar versão da migração
        update_option('limpvix_db_version_booknetic', '1.0.0');

        do_action('limpvix_log_event', 'database_migration_completed', [
            'migration' => 'CreateBookneticIntegrationTables',
            'tables' => [$tableName1, $tableName2],
        ]);
    }

    /**
     * Reverter migração (drop tables)
     */
    public static function down(): void
    {
        global $wpdb;

        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}limpvix_financial_context`");
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}limpvix_appointment_order_map`");

        delete_option('limpvix_db_version_booknetic');
    }

    /**
     * Verificar se migração já foi executada
     *
     * @return bool
     */
    public static function isApplied(): bool
    {
        $version = get_option('limpvix_db_version_booknetic', '0.0.0');
        return version_compare($version, '1.0.0', '>=');
    }
}
