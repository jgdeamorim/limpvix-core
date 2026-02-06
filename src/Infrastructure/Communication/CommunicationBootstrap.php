<?php
/**
 * CommunicationBootstrap - Inicialização do Módulo de Comunicação
 *
 * @package LimpVix\Infrastructure\Communication
 */

namespace LimpVix\Infrastructure\Communication;

use LimpVix\Infrastructure\Communication\Cron\FeedbackRemindersCron;
use LimpVix\Infrastructure\Communication\MessageFlowTriggers;

defined('ABSPATH') || exit;

final class CommunicationBootstrap
{
    /**
     * Inicializar módulo
     */
    public static function boot(): void
    {
        // Registrar WP-Cron para envio de lembretes
        if (class_exists('LimpVix\\Infrastructure\\Communication\\Cron\\FeedbackRemindersCron')) {
            FeedbackRemindersCron::register();
        }

        // Registrar triggers de fluxos de mensagens
        if (class_exists('LimpVix\\Infrastructure\\Communication\\MessageFlowTriggers')) {
            MessageFlowTriggers::register();
        }
    }

    /**
     * Executar na ativação do plugin
     */
    public static function onActivation(): void
    {
        // Criar tabelas necessárias
        self::runMigrations();

        // Agendar cron job
        if (!wp_next_scheduled('limpvix_send_feedback_reminders')) {
            wp_schedule_event(time(), 'hourly', 'limpvix_send_feedback_reminders');
        }
    }

    /**
     * Executar na desativação do plugin
     */
    public static function onDeactivation(): void
    {
        // Remover cron job
        $timestamp = wp_next_scheduled('limpvix_send_feedback_reminders');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'limpvix_send_feedback_reminders');
        }
    }

    /**
     * Executar migrations
     */
    private static function runMigrations(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Tabela de lembretes de feedback
        $sql_reminders = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}limpvix_feedback_reminders (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_uuid varchar(100) NOT NULL,
            customer_id bigint(20) UNSIGNED NOT NULL,
            service_completed_at datetime NOT NULL,
            reminder_count tinyint(1) NOT NULL DEFAULT 0,
            last_reminder_at datetime DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            stop_reason varchar(100) DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_uuid (order_uuid),
            KEY customer_id (customer_id),
            KEY status (status),
            KEY service_completed_at (service_completed_at)
        ) $charset_collate;";

        // Tabela de mensagens enviadas (log de auditoria)
        $sql_messages = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}limpvix_messages (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            channel varchar(20) NOT NULL,
            type varchar(50) NOT NULL,
            recipient_type varchar(20) NOT NULL,
            recipient_id bigint(20) UNSIGNED NOT NULL,
            order_uuid varchar(100) DEFAULT NULL,
            message_text text DEFAULT NULL,
            external_id varchar(100) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempt tinyint(1) NOT NULL DEFAULT 1,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_uuid (order_uuid),
            KEY recipient_id (recipient_id),
            KEY status (status),
            KEY channel (channel),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_reminders);
        dbDelta($sql_messages);
    }
}
