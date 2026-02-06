<?php
/**
 * TwilioSmsProvider
 *
 * Provider real de SMS via Twilio
 *
 * @package LimpVix\Infrastructure\Communication\Providers
 * @since 0.1.2
 */

namespace LimpVix\Infrastructure\Communication\Providers;

use LimpVix\Infrastructure\Communication\Repositories\MessageRepository;

class TwilioSmsProvider
{
    private ?object $client = null;
    private string $fromNumber;
    private MessageRepository $messageRepository;
    private bool $enabled = false;

    public function __construct()
    {
        $this->messageRepository = new MessageRepository();

        $settings = get_option('limpvix_twilio_settings', []);

        if (empty($settings['account_sid']) || empty($settings['auth_token']) || empty($settings['from_number'])) {
            error_log('[LimpVix] Twilio não configurado corretamente');
            return;
        }

        $this->fromNumber = $settings['from_number'];
        $this->enabled = true;

        // Twilio SDK (requer composer require twilio/sdk)
        if (class_exists('\Twilio\Rest\Client')) {
            try {
                $this->client = new \Twilio\Rest\Client(
                    $settings['account_sid'],
                    $settings['auth_token']
                );
            } catch (\Exception $e) {
                error_log('[LimpVix] Erro ao inicializar Twilio: ' . $e->getMessage());
                $this->enabled = false;
            }
        }
    }

    /**
     * Enviar SMS via Twilio
     *
     * @param string $to Telefone destino (formato: +5527999999999)
     * @param string $message Conteúdo da mensagem
     * @param array $context Contexto adicional (order_id, template_id, etc)
     * @return bool True se sucesso
     */
    public function send(string $to, string $message, array $context = []): bool
    {
        // Criar log na tabela
        $log_id = $this->messageRepository->create([
            'order_id' => $context['order_id'] ?? null,
            'booking_id' => $context['booking_id'] ?? null,
            'recipient_phone' => $to,
            'recipient_type' => $context['recipient_type'] ?? 'client',
            'channel' => 'sms',
            'template_id' => $context['template_id'] ?? '',
            'flow_id' => $context['flow_id'] ?? '',
            'message_content' => $message,
            'status' => 'pending'
        ]);

        if (!$this->enabled || !$this->client) {
            error_log('[LimpVix] Twilio não disponível (configuração ou SDK)');
            $this->messageRepository->updateStatus($log_id, 'failed', 'Twilio não configurado ou SDK não instalado');
            return false;
        }

        try {
            $result = $this->client->messages->create($to, [
                'from' => $this->fromNumber,
                'body' => $message
            ]);

            $response = json_encode([
                'sid' => $result->sid,
                'status' => $result->status,
                'to' => $result->to,
                'from' => $result->from
            ]);

            error_log("[LimpVix] SMS enviado com sucesso: SID={$result->sid}, To={$to}");

            $this->messageRepository->updateStatus($log_id, 'sent', $response);

            return true;

        } catch (\Exception $e) {
            error_log('[LimpVix] Erro ao enviar SMS: ' . $e->getMessage());

            $this->messageRepository->updateStatus($log_id, 'failed', $e->getMessage());

            return false;
        }
    }

    /**
     * Verificar se Twilio está configurado e disponível
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->enabled && $this->client !== null;
    }
}
