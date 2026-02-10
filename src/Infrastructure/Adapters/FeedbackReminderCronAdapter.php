<?php
declare(strict_types=1);

/**
 * FeedbackReminderCronAdapter (GAP #1)
 *
 * Cron adapter para enviar reminders de feedback window
 * aos clientes em 12h e 23h após o início da janela.
 *
 * HOOKS:
 * - limpvix_feedback_reminder_12h: Send reminder 12h after window start
 * - limpvix_feedback_reminder_final: Send reminder 1h before deadline (23h)
 *
 * NOTIFICATION FLOW:
 * 1. Window starts → Checkout email sent (immediate)
 * 2. +12h → First reminder (limpvix_feedback_reminder_12h)
 * 3. +23h → Final reminder (limpvix_feedback_reminder_final)
 * 4. +24h → Window expires (auto-approve if no feedback)
 *
 * @package LimpVix\Infrastructure\Adapters
 * @since GAP #1
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\SendTemplatedMessage;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Infrastructure\Persistence\WpStructuredFeedbackRepository;

defined('ABSPATH') || exit;

class FeedbackReminderCronAdapter
{
    private const HOOK_12H_REMINDER = 'limpvix_feedback_reminder_12h';
    private const HOOK_FINAL_REMINDER = 'limpvix_feedback_reminder_final';

    private ExecutionRepositoryInterface $executionRepository;
    private WpStructuredFeedbackRepository $feedbackRepository;
    private ?SendTemplatedMessage $sendMessage;

    public function __construct(
        ExecutionRepositoryInterface $executionRepository,
        WpStructuredFeedbackRepository $feedbackRepository,
        ?SendTemplatedMessage $sendMessage = null
    ) {
        $this->executionRepository = $executionRepository;
        $this->feedbackRepository = $feedbackRepository;
        $this->sendMessage = $sendMessage;
    }

    /**
     * Register cron hooks
     */
    public function register(): void
    {
        add_action(self::HOOK_12H_REMINDER, [$this, 'send12HourReminder'], 10, 1);
        add_action(self::HOOK_FINAL_REMINDER, [$this, 'sendFinalReminder'], 10, 1);
    }

    /**
     * Send 12-hour reminder
     *
     * @param string $orderUuid
     */
    public function send12HourReminder(string $orderUuid): void
    {
        $this->sendReminder($orderUuid, '12h', [
            'subject' => 'Como foi seu atendimento?',
            'template' => 'feedback_reminder_12h',
            'message' => 'Você ainda tem 12 horas para avaliar seu atendimento e garantir a qualidade do serviço.'
        ]);
    }

    /**
     * Send final reminder (1h before deadline)
     *
     * @param string $orderUuid
     */
    public function sendFinalReminder(string $orderUuid): void
    {
        $this->sendReminder($orderUuid, 'final', [
            'subject' => 'Última chance para avaliar seu atendimento',
            'template' => 'feedback_reminder_final',
            'message' => 'Sua janela de feedback expira em 1 hora! Avalie agora para garantir que sua opinião seja considerada.'
        ]);
    }

    /**
     * Send reminder notification
     *
     * @param string $orderUuid
     * @param string $reminderType '12h' or 'final'
     * @param array $notificationData
     */
    private function sendReminder(string $orderUuid, string $reminderType, array $notificationData): void
    {
        try {
            // 1. Check if feedback already submitted (skip reminder if yes)
            $feedback = $this->feedbackRepository->findByOrderUuid($orderUuid);

            if ($feedback) {
                error_log("[LimpVix] Feedback already submitted for order {$orderUuid}, skipping {$reminderType} reminder");
                return;
            }

            // 2. Get execution to verify window is still active
            $execution = $this->executionRepository->findByOrderUuid($orderUuid);

            if (!$execution) {
                error_log("[LimpVix] Execution not found for order {$orderUuid}, cannot send {$reminderType} reminder");
                return;
            }

            // 3. Verify feedback window is still active
            if (!$execution->isFeedbackWindowActive()) {
                error_log("[LimpVix] Feedback window already expired for order {$orderUuid}, skipping {$reminderType} reminder");
                return;
            }

            // 4. Get customer contact info (from Order or directly)
            $customerData = $this->getCustomerData($orderUuid);

            if (!$customerData) {
                error_log("[LimpVix] Customer data not found for order {$orderUuid}, cannot send {$reminderType} reminder");
                return;
            }

            // 5. Send notification via SendTemplatedMessage use case
            if ($this->sendMessage) {
                $feedbackUrl = $this->generateFeedbackUrl($orderUuid);
                $expiresAt = $execution->getFeedbackWindowExpiresAt();
                $timeRemaining = $expiresAt ? $this->calculateTimeRemaining($expiresAt) : '24 hours';

                $result = $this->sendMessage->execute([
                    'recipient' => $customerData['phone'] ?? $customerData['email'],
                    'channel' => $customerData['phone'] ? 'whatsapp' : 'email',
                    'template' => $notificationData['template'],
                    'data' => [
                        'customer_name' => $customerData['name'],
                        'order_uuid' => $orderUuid,
                        'feedback_url' => $feedbackUrl,
                        'time_remaining' => $timeRemaining,
                        'expires_at' => $expiresAt?->format('d/m/Y H:i'),
                    ]
                ]);

                if ($result->isSuccess()) {
                    error_log("[LimpVix] {$reminderType} reminder sent successfully for order {$orderUuid}");
                } else {
                    error_log("[LimpVix] Failed to send {$reminderType} reminder for order {$orderUuid}: " . $result->getError());
                }
            } else {
                // Fallback: Direct email send (if SendTemplatedMessage not available)
                $this->sendFallbackEmail($orderUuid, $customerData, $notificationData);
            }

        } catch (\Exception $e) {
            error_log("[LimpVix] Exception sending {$reminderType} reminder for order {$orderUuid}: " . $e->getMessage());
        }
    }

    /**
     * Get customer data for notifications
     *
     * @param string $orderUuid
     * @return array|null
     */
    private function getCustomerData(string $orderUuid): ?array
    {
        global $wpdb;

        // Try to get from wp_limpvix_orders table
        $order = $wpdb->get_row($wpdb->prepare(
            "SELECT customer_id FROM {$wpdb->prefix}limpvix_orders WHERE order_uuid = %s",
            $orderUuid
        ), ARRAY_A);

        if (!$order || !$order['customer_id']) {
            return null;
        }

        $customerId = $order['customer_id'];

        // Get customer data from WordPress users
        $user = get_userdata($customerId);

        if (!$user) {
            return null;
        }

        return [
            'name' => $user->display_name ?: $user->user_login,
            'email' => $user->user_email,
            'phone' => get_user_meta($customerId, 'billing_phone', true) ?: null
        ];
    }

    /**
     * Generate feedback submission URL
     *
     * @param string $orderUuid
     * @return string
     */
    private function generateFeedbackUrl(string $orderUuid): string
    {
        return home_url("/minha-conta/feedback/?order={$orderUuid}");
    }

    /**
     * Calculate human-readable time remaining
     *
     * @param \DateTimeImmutable $expiresAt
     * @return string
     */
    private function calculateTimeRemaining(\DateTimeImmutable $expiresAt): string
    {
        $now = new \DateTimeImmutable();
        $diff = $expiresAt->getTimestamp() - $now->getTimestamp();

        if ($diff <= 0) {
            return 'expirado';
        }

        $hours = floor($diff / 3600);
        $minutes = floor(($diff % 3600) / 60);

        if ($hours > 0) {
            return "{$hours} hora" . ($hours > 1 ? 's' : '');
        }

        return "{$minutes} minuto" . ($minutes > 1 ? 's' : '');
    }

    /**
     * Fallback email send (if SendTemplatedMessage not available)
     *
     * @param string $orderUuid
     * @param array $customerData
     * @param array $notificationData
     */
    private function sendFallbackEmail(string $orderUuid, array $customerData, array $notificationData): void
    {
        $feedbackUrl = $this->generateFeedbackUrl($orderUuid);

        $message = sprintf(
            "Olá %s,\n\n%s\n\nAvalie agora: %s\n\nEquipe LimpVix",
            $customerData['name'],
            $notificationData['message'],
            $feedbackUrl
        );

        $sent = wp_mail(
            $customerData['email'],
            $notificationData['subject'],
            $message,
            ['Content-Type: text/plain; charset=UTF-8']
        );

        if ($sent) {
            error_log("[LimpVix] Fallback email sent for order {$orderUuid}");
        } else {
            error_log("[LimpVix] Failed to send fallback email for order {$orderUuid}");
        }
    }
}
