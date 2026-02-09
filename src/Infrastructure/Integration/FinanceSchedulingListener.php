<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Integration;

/**
 * Integration Listener: Finance ↔ Scheduling
 *
 * Integra módulo de Scheduling com Finance:
 * - Check-in → Libera hold financeiro
 * - Checkout → Autoriza payout
 */
final class FinanceSchedulingListener
{
    public static function init(): void
    {
        $instance = new self();

        // Check-in completo → Libera hold
        add_action('limpvix_scheduling_check_in_performed', [$instance, 'handleCheckIn'], 10, 1);

        // Checkout completo → Autoriza payout
        add_action('limpvix_scheduling_check_out_performed', [$instance, 'handleCheckOut'], 10, 1);

        // Schedule cancelado → Reverter hold
        add_action('limpvix_scheduling_schedule_cancelled', [$instance, 'handleCancellation'], 10, 1);
    }

    /**
     * Handle check-in: Libera hold financeiro
     *
     * @param array $eventData
     */
    public function handleCheckIn(array $eventData): void
    {
        $scheduleUuid = $eventData['schedule_uuid'] ?? null;
        $orderUuid = $eventData['order_uuid'] ?? null;

        if (!$orderUuid) {
            error_log('[LimpVix Finance] Check-in sem order_uuid: ' . $scheduleUuid);
            return;
        }

        // Disparar evento para Finance
        do_action('limpvix_finance_release_hold', [
            'order_uuid' => $orderUuid,
            'reason' => 'check_in_completed',
            'schedule_uuid' => $scheduleUuid,
            'timestamp' => current_time('mysql'),
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Finance] Hold released for order %s (Check-in: %s)',
                $orderUuid,
                $scheduleUuid
            ));
        }
    }

    /**
     * Handle checkout: Autoriza payout
     *
     * @param array $eventData
     */
    public function handleCheckOut(array $eventData): void
    {
        $scheduleUuid = $eventData['schedule_uuid'] ?? null;
        $orderUuid = $eventData['order_uuid'] ?? null;
        $actualDuration = $eventData['actual_duration_minutes'] ?? null;

        if (!$orderUuid) {
            error_log('[LimpVix Finance] Checkout sem order_uuid: ' . $scheduleUuid);
            return;
        }

        // Disparar evento para Finance autorizar payout
        do_action('limpvix_finance_authorize_payout', [
            'order_uuid' => $orderUuid,
            'schedule_uuid' => $scheduleUuid,
            'actual_duration_minutes' => $actualDuration,
            'timestamp' => current_time('mysql'),
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Finance] Payout authorized for order %s (Checkout: %s, Duration: %d min)',
                $orderUuid,
                $scheduleUuid,
                $actualDuration
            ));
        }
    }

    /**
     * Handle cancellation: Reverter hold
     *
     * @param array $eventData
     */
    public function handleCancellation(array $eventData): void
    {
        $scheduleUuid = $eventData['schedule_uuid'] ?? null;
        $orderUuid = $eventData['order_uuid'] ?? null;

        if (!$orderUuid) {
            error_log('[LimpVix Finance] Cancellation sem order_uuid: ' . $scheduleUuid);
            return;
        }

        // Verificar se tinha check-in (não pode cancelar depois de check-in)
        $hasCheckIn = $eventData['had_check_in'] ?? false;

        if ($hasCheckIn) {
            // Não reverter - serviço foi iniciado
            error_log(sprintf(
                '[LimpVix Finance] Cannot revert hold - service was started (Schedule: %s)',
                $scheduleUuid
            ));
            return;
        }

        // Disparar evento para Finance reverter hold
        do_action('limpvix_finance_revert_hold', [
            'order_uuid' => $orderUuid,
            'schedule_uuid' => $scheduleUuid,
            'reason' => 'schedule_cancelled',
            'timestamp' => current_time('mysql'),
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Finance] Hold reverted for order %s (Schedule cancelled: %s)',
                $orderUuid,
                $scheduleUuid
            ));
        }
    }
}
