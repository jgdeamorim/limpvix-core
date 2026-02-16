<?php
/**
 * CustomerNotifier Service
 *
 * Centraliza notificações para clientes sobre eventos do serviço
 *
 * GAP #3: Client Notifications at Check-in
 * - Notifica cliente quando professional faz check-in
 * - Notifica sobre mudanças de status da execução
 * - Envia confirmações e alertas
 *
 * RESPONSABILIDADES:
 * - Enviar SMS via NVoip/Twilio
 * - Enviar WhatsApp (fallback para SMS)
 * - Enviar Email (fallback se SMS falhar)
 * - Log de notificações enviadas
 *
 * CANAIS (Priority Order):
 * 1. WhatsApp (via Twilio/NVoip)
 * 2. SMS (fallback)
 * 3. Email (último recurso)
 *
 * @package LimpVix\Application\Services
 * @since 1.0.0 (GAP #3 Implementation)
 */

namespace LimpVix\Application\Services;

use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Order\OrderRepositoryInterface;
use LimpVix\Infrastructure\SMS\NVoipOtpProvider;
use LimpVix\Infrastructure\SMS\TwilioOtpProvider;

defined('ABSPATH') || exit;

final class CustomerNotifier
{
    public function __construct(
        private ExecutionRepositoryInterface $executionRepository,
        private OrderRepositoryInterface $orderRepository
    ) {}

    /**
     * Enviar notificação de check-in ao cliente
     *
     * GAP #3: "✅ Seu profissional chegou! Serviço em execução."
     *
     * @param string $executionUuid Execution UUID
     * @param int $orderId Order ID
     * @return bool True if notification sent successfully
     */
    public function sendCheckInNotification(string $executionUuid, int $orderId): bool
    {
        try {
            // Get execution details
            $execution = $this->executionRepository->findByUuid($executionUuid);
            if (!$execution) {
                error_log("[LimpVix] CustomerNotifier: Execution not found: {$executionUuid}");
                return false;
            }

            // Get order to find customer
            $order = $this->orderRepository->findById($orderId);
            if (!$order) {
                error_log("[LimpVix] CustomerNotifier: Order not found: ID {$orderId}");
                return false;
            }

            // Get customer phone from order
            $customerPhone = $order->getCustomerPhone();
            if (empty($customerPhone)) {
                error_log("[LimpVix] CustomerNotifier: Customer phone not found for Order #{$orderId}");
                return false;
            }

            // Get professional name (optional)
            $professionalName = $this->getProfessionalName($execution->getProfessionalId());

            // Build message
            $message = $this->buildCheckInMessage($professionalName, $executionUuid);

            // Try to send via WhatsApp first (if available)
            $sent = $this->sendViaWhatsApp($customerPhone, $message);

            // Fallback to SMS if WhatsApp fails
            if (!$sent) {
                $sent = $this->sendViaSMS($customerPhone, $message);
            }

            // Last resort: Email (if phone notification fails)
            if (!$sent) {
                $sent = $this->sendViaEmail($order->getCustomerEmail(), $message);
            }

            // Log result
            if ($sent) {
                error_log(sprintf(
                    '[LimpVix] CustomerNotifier: Check-in notification sent to customer (Order #%d, Phone: %s)',
                    $orderId,
                    $this->maskPhone($customerPhone)
                ));
            } else {
                error_log(sprintf(
                    '[LimpVix] CustomerNotifier: ALL notification channels failed for Order #%d',
                    $orderId
                ));
            }

            return $sent;

        } catch (\Exception $e) {
            error_log('[LimpVix] CustomerNotifier: Error sending check-in notification - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Build check-in message
     *
     * @param string|null $professionalName Professional name
     * @param string $executionUuid Execution UUID
     * @return string Message text
     */
    private function buildCheckInMessage(?string $professionalName, string $executionUuid): string
    {
        $companyName = get_option('limpvix_company_name', 'LimpVix');

        if ($professionalName) {
            return sprintf(
                "✅ *%s*\n\nSeu profissional *%s* chegou!\n\n🔄 Serviço em execução.\n\n📱 Acompanhe em tempo real pelo app.\n\nCódigo: %s",
                $companyName,
                $professionalName,
                substr($executionUuid, 0, 8)
            );
        }

        return sprintf(
            "✅ *%s*\n\nSeu profissional chegou!\n\n🔄 Serviço em execução.\n\n📱 Acompanhe em tempo real pelo app.\n\nCódigo: %s",
            $companyName,
            substr($executionUuid, 0, 8)
        );
    }

    /**
     * Send notification via WhatsApp (using Twilio/NVoip)
     *
     * @param string $phone Customer phone
     * @param string $message Message text
     * @return bool Success
     */
    private function sendViaWhatsApp(string $phone, string $message): bool
    {
        try {
            // Check if WhatsApp is enabled
            $whatsappEnabled = get_option('limpvix_whatsapp_notifications_enabled', false);
            if (!$whatsappEnabled) {
                return false;
            }

            // Try NVoip first (if configured)
            if (get_option('limpvix_nvoip_api_key')) {
                $provider = new NVoipOtpProvider();
                // Note: NVoipOtpProvider needs sendWhatsApp method (to be added)
                // For now, fallback to SMS
                return false;
            }

            // Try Twilio WhatsApp
            if (get_option('limpvix_twilio_account_sid')) {
                $provider = new TwilioOtpProvider();
                // Note: TwilioOtpProvider needs sendWhatsApp method (to be added)
                // For now, fallback to SMS
                return false;
            }

            return false;

        } catch (\Exception $e) {
            error_log('[LimpVix] CustomerNotifier: WhatsApp error - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification via SMS
     *
     * @param string $phone Customer phone
     * @param string $message Message text
     * @return bool Success
     */
    private function sendViaSMS(string $phone, string $message): bool
    {
        try {
            // Try NVoip first (if configured)
            if (get_option('limpvix_nvoip_api_key')) {
                $provider = new NVoipOtpProvider();
                return $provider->sendSMS($phone, $message);
            }

            // Try Twilio as fallback
            if (get_option('limpvix_twilio_account_sid')) {
                $provider = new TwilioOtpProvider();
                return $provider->sendSMS($phone, $message);
            }

            error_log('[LimpVix] CustomerNotifier: No SMS provider configured');
            return false;

        } catch (\Exception $e) {
            error_log('[LimpVix] CustomerNotifier: SMS error - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification via Email (last resort)
     *
     * @param string|null $email Customer email
     * @param string $message Message text
     * @return bool Success
     */
    private function sendViaEmail(?string $email, string $message): bool
    {
        if (empty($email)) {
            return false;
        }

        try {
            $companyName = get_option('limpvix_company_name', 'LimpVix');
            $subject = "✅ Seu profissional chegou! - {$companyName}";

            // Convert message to HTML
            $htmlMessage = nl2br(str_replace('*', '<strong>', str_replace('*', '</strong>', $message)));

            $headers = ['Content-Type: text/html; charset=UTF-8'];

            return wp_mail($email, $subject, $htmlMessage, $headers);

        } catch (\Exception $e) {
            error_log('[LimpVix] CustomerNotifier: Email error - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get professional name by ID
     *
     * @param int $professionalId Professional ID
     * @return string|null Professional name
     */
    private function getProfessionalName(int $professionalId): ?string
    {
        global $wpdb;

        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}limpvix_professionals WHERE id = %d",
            $professionalId
        ));

        return $name ?: null;
    }

    /**
     * Mask phone number for logs
     *
     * @param string $phone Phone number
     * @return string Masked phone
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 4) {
            return '****';
        }

        return substr($phone, 0, 2) . str_repeat('*', strlen($phone) - 4) . substr($phone, -2);
    }
}
