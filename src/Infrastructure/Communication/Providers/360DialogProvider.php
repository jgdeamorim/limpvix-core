<?php
/**
 * 360DialogProvider
 *
 * Provider real de WhatsApp via 360Dialog API
 *
 * @package LimpVix\Infrastructure\Communication\Providers
 * @since 0.1.2
 */

namespace LimpVix\Infrastructure\Communication\Providers;

use LimpVix\Infrastructure\Communication\Repositories\MessageRepository;

class DialogProvider
{
    private string $apiKey;
    private string $apiUrl = 'https://waba.360dialog.io/v1/messages';
    private MessageRepository $messageRepository;
    private bool $enabled = false;

    public function __construct()
    {
        $this->messageRepository = new MessageRepository();

        $settings = get_option('limpvix_360dialog_settings', []);

        if (empty($settings['api_key'])) {
            error_log('[LimpVix] 360Dialog não configurado corretamente');
            return;
        }

        $this->apiKey = $settings['api_key'];
        $this->enabled = true;
    }

    /**
     * Enviar WhatsApp via 360Dialog
     *
     * @param string $to Telefone destino (formato: 5527999999999, sem +)
     * @param string $message Conteúdo da mensagem
     * @param array $context Contexto adicional
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
            'channel' => 'whatsapp',
            'template_id' => $context['template_id'] ?? '',
            'flow_id' => $context['flow_id'] ?? '',
            'message_content' => $message,
            'status' => 'pending'
        ]);

        if (!$this->enabled) {
            error_log('[LimpVix] 360Dialog não disponível (configuração)');
            $this->messageRepository->updateStatus($log_id, 'failed', '360Dialog não configurado');
            return false;
        }

        // Normalizar telefone (remover + se existir)
        $to = ltrim($to, '+');

        $response = wp_remote_post($this->apiUrl, [
            'headers' => [
                'D360-API-KEY' => $this->apiKey,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $message
                ]
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log('[LimpVix] Erro ao enviar WhatsApp: ' . $response->get_error_message());
            $this->messageRepository->updateStatus($log_id, 'failed', $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200 && $status_code !== 201) {
            $error = $body['errors'][0]['title'] ?? 'Erro desconhecido';
            error_log('[LimpVix] Erro 360Dialog (HTTP ' . $status_code . '): ' . $error);
            $this->messageRepository->updateStatus($log_id, 'failed', $error);
            return false;
        }

        if (!isset($body['messages'][0]['id'])) {
            error_log('[LimpVix] Resposta 360Dialog inválida: ' . print_r($body, true));
            $this->messageRepository->updateStatus($log_id, 'failed', 'Resposta inválida da API');
            return false;
        }

        $message_id = $body['messages'][0]['id'];

        error_log("[LimpVix] WhatsApp enviado com sucesso: ID={$message_id}, To={$to}");

        $this->messageRepository->updateStatus($log_id, 'sent', json_encode($body));

        return true;
    }

    /**
     * Verificar se 360Dialog está configurado e disponível
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->enabled;
    }
}
