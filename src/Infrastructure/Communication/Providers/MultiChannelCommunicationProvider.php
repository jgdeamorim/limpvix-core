<?php
/**
 * MultiChannelCommunicationProvider - Adapter para providers de comunicação
 *
 * Implementa CommunicationProviderInterface delegando para os providers
 * concretos (TwilioSmsProvider, WhatsApp360DialogProvider) conforme o canal.
 *
 * @package LimpVix\Infrastructure\Communication\Providers
 * @since 0.3.1
 */

namespace LimpVix\Infrastructure\Communication\Providers;

use LimpVix\Application\Services\Communication\CommunicationProviderInterface;

defined('ABSPATH') || exit;

class MultiChannelCommunicationProvider implements CommunicationProviderInterface
{
    private ?TwilioSmsProvider $twilioProvider = null;
    private ?WhatsApp360DialogProvider $whatsappProvider = null;

    public function __construct()
    {
        // Inicializar providers disponíveis
        if (class_exists(TwilioSmsProvider::class)) {
            $this->twilioProvider = new TwilioSmsProvider();
        }

        if (class_exists(WhatsApp360DialogProvider::class)) {
            $this->whatsappProvider = new WhatsApp360DialogProvider();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send(array $params): array
    {
        $channel = $params['channel'] ?? 'sms';
        $recipient = $params['recipient'] ?? '';
        $content = $params['content'] ?? '';
        $messageId = $params['message_id'] ?? '';

        $context = [
            'message_id' => $messageId,
        ];

        // Delegar para o provider correto conforme canal
        switch ($channel) {
            case 'whatsapp':
                return $this->sendViaWhatsApp($recipient, $content, $context);

            case 'sms':
                return $this->sendViaSms($recipient, $content, $context);

            case 'email':
                return $this->sendViaEmail($recipient, $content, $context);

            default:
                return [
                    'success' => false,
                    'provider_response' => null,
                    'error_type' => 'unsupported_channel',
                    'error_message' => "Canal não suportado: {$channel}",
                ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function checkDeliveryStatus(string $messageId): array
    {
        // Status check não implementado nos providers atuais
        // Retornar status genérico 'sent'
        return [
            'status' => 'sent',
            'delivered_at' => null,
            'read_at' => null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function supportsChannel(string $channel): bool
    {
        return match ($channel) {
            'sms' => $this->twilioProvider !== null && $this->twilioProvider->isAvailable(),
            'whatsapp' => $this->whatsappProvider !== null && $this->whatsappProvider->isAvailable(),
            'email' => true, // wp_mail() sempre disponível
            default => false,
        };
    }

    private function sendViaWhatsApp(string $recipient, string $content, array $context): array
    {
        if (!$this->whatsappProvider || !$this->whatsappProvider->isAvailable()) {
            // Fallback para SMS se WhatsApp indisponível
            if ($this->twilioProvider && $this->twilioProvider->isAvailable()) {
                error_log('[LimpVix] WhatsApp indisponível, fallback para SMS');
                return $this->sendViaSms($recipient, $content, $context);
            }

            return [
                'success' => false,
                'provider_response' => null,
                'error_type' => 'provider_unavailable',
                'error_message' => 'WhatsApp provider não configurado e SMS não disponível como fallback',
            ];
        }

        $success = $this->whatsappProvider->send($recipient, $content, $context);

        return [
            'success' => $success,
            'provider_response' => ['provider' => '360dialog', 'channel' => 'whatsapp'],
            'error_type' => $success ? '' : 'send_failed',
            'error_message' => $success ? '' : 'Falha ao enviar via WhatsApp 360Dialog',
        ];
    }

    private function sendViaSms(string $recipient, string $content, array $context): array
    {
        if (!$this->twilioProvider || !$this->twilioProvider->isAvailable()) {
            return [
                'success' => false,
                'provider_response' => null,
                'error_type' => 'provider_unavailable',
                'error_message' => 'Twilio SMS provider não configurado',
            ];
        }

        $success = $this->twilioProvider->send($recipient, $content, $context);

        return [
            'success' => $success,
            'provider_response' => ['provider' => 'twilio', 'channel' => 'sms'],
            'error_type' => $success ? '' : 'send_failed',
            'error_message' => $success ? '' : 'Falha ao enviar SMS via Twilio',
        ];
    }

    private function sendViaEmail(string $recipient, string $content, array $context): array
    {
        $subject = $context['subject'] ?? 'LimpVix - Notificação';
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $success = wp_mail($recipient, $subject, $content, $headers);

        return [
            'success' => $success,
            'provider_response' => ['provider' => 'wp_mail', 'channel' => 'email'],
            'error_type' => $success ? '' : 'send_failed',
            'error_message' => $success ? '' : 'Falha ao enviar email via wp_mail',
        ];
    }
}
