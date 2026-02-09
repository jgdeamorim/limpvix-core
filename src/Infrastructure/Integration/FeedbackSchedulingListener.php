<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Integration;

/**
 * Integration Listener: Feedback ↔ Scheduling
 *
 * Integra módulo de Scheduling com Feedback:
 * - Checkout → Libera formulário de feedback para cliente
 */
final class FeedbackSchedulingListener
{
    public static function init(): void
    {
        $instance = new self();

        // Checkout completo → Libera feedback
        add_action('limpvix_scheduling_check_out_performed', [$instance, 'handleCheckOut'], 10, 1);
    }

    /**
     * Handle checkout: Libera feedback
     *
     * @param array $eventData
     */
    public function handleCheckOut(array $eventData): void
    {
        $scheduleUuid = $eventData['schedule_uuid'] ?? null;
        $orderUuid = $eventData['order_uuid'] ?? null;

        if (!$orderUuid) {
            error_log('[LimpVix Feedback] Checkout sem order_uuid: ' . $scheduleUuid);
            return;
        }

        // Disparar evento para módulo de Feedback
        do_action('limpvix_feedback_enable', [
            'order_uuid' => $orderUuid,
            'schedule_uuid' => $scheduleUuid,
            'timestamp' => current_time('mysql'),
        ]);

        // Enviar notificação para cliente (email/SMS)
        $this->sendFeedbackNotification($orderUuid);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Feedback] Feedback enabled for order %s (Checkout: %s)',
                $orderUuid,
                $scheduleUuid
            ));
        }
    }

    /**
     * Envia notificação de feedback para cliente
     *
     * @param string $orderUuid
     */
    private function sendFeedbackNotification(string $orderUuid): void
    {
        global $wpdb;
        $ordersTable = $wpdb->prefix . 'limpvix_orders';

        // Buscar dados do cliente
        $order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT customer_email, customer_name FROM {$ordersTable} WHERE uuid = %s",
                $orderUuid
            ),
            ARRAY_A
        );

        if (!$order) {
            return;
        }

        // Email para cliente
        $to = $order['customer_email'];
        $subject = 'Avalie seu serviço de limpeza - LimpVix';
        $message = sprintf(
            "Olá %s,\n\nSeu serviço foi concluído! Gostaríamos de ouvir sua opinião.\n\nAvalie agora: %s\n\nObrigado,\nEquipe LimpVix",
            $order['customer_name'],
            home_url('/feedback?order=' . $orderUuid)
        );

        wp_mail($to, $subject, $message);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Feedback] Notification sent to %s (Order: %s)',
                $to,
                $orderUuid
            ));
        }
    }
}
