<?php
/**
 * Execution Checked-In Listener
 *
 * Listens to execution check-in events and sends notifications to customers
 *
 * GAP #3: Client Notifications at Check-in
 * - Sends SMS/Email/WhatsApp notification when professional arrives
 * - Message: "✅ Seu profissional chegou! Serviço em execução."
 *
 * EVENTS:
 * - limpvix_execution_checked_in: Professional checked in at location
 *
 * @package LimpVix\Infrastructure\Integration
 * @since 1.0.0 (GAP #3 Implementation)
 */

namespace LimpVix\Infrastructure\Integration;

use LimpVix\Application\Services\CustomerNotifier;
use LimpVix\Infrastructure\Persistence\WpExecutionRepository;
use LimpVix\Infrastructure\Persistence\WpOrderRepository;

defined('ABSPATH') || exit;

final class ExecutionCheckedInListener
{
    /**
     * Initialize listener
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        // Listen to check-in event
        add_action('limpvix_execution_checked_in', [$instance, 'handleCheckedIn'], 10, 3);
    }

    /**
     * Handle execution checked-in event
     *
     * @param string $executionUuid Execution UUID
     * @param int $orderId Order ID
     * @param int $professionalId Professional ID
     * @return void
     */
    public function handleCheckedIn(string $executionUuid, int $orderId, int $professionalId): void
    {
        try {
            // Initialize repositories
            $executionRepo = $GLOBALS['limpvix_execution_repository']
                ?? new WpExecutionRepository();
            $orderRepo = $GLOBALS['limpvix_order_repository']
                ?? new WpOrderRepository();

            // Get execution details
            $execution = $executionRepo->findByUuid($executionUuid);
            if (!$execution) {
                error_log(sprintf(
                    '[LimpVix] ExecutionCheckedInListener: Execution not found: %s',
                    $executionUuid
                ));
                return;
            }

            // Get order to find customer
            $order = $orderRepo->findById($orderId);
            if (!$order) {
                error_log(sprintf(
                    '[LimpVix] ExecutionCheckedInListener: Order not found: ID %d',
                    $orderId
                ));
                return;
            }

            // Initialize Customer Notifier service
            $notifier = new CustomerNotifier($executionRepo, $orderRepo);

            // Send notification to customer
            $sent = $notifier->sendCheckInNotification($executionUuid, $orderId);

            if ($sent) {
                error_log(sprintf(
                    '[LimpVix] ExecutionCheckedInListener: Check-in notification sent to customer (Order #%d, Execution %s)',
                    $orderId,
                    $executionUuid
                ));
            } else {
                error_log(sprintf(
                    '[LimpVix] ExecutionCheckedInListener: Failed to send check-in notification (Order #%d, Execution %s)',
                    $orderId,
                    $executionUuid
                ));
            }

        } catch (\Exception $e) {
            error_log('[LimpVix] ExecutionCheckedInListener: Error - ' . $e->getMessage());
        }
    }
}
