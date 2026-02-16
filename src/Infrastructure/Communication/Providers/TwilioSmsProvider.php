<?php
/**
 * TwilioSmsProvider
 *
 * Provider real de SMS via Twilio REST API
 *
 * ✅ SEM SDK - Usa wp_remote_post() para máxima compatibilidade
 * ✅ Funciona em qualquer hospedagem WordPress
 * ✅ Sem Composer, sem vendor/, sem dependências externas
 * ✅ Arquitetura enterprise-grade para WordPress
 *
 * @package LimpVix\Infrastructure\Communication\Providers
 * @since 0.1.2
 */

namespace LimpVix\Infrastructure\Communication\Providers;

use LimpVix\Infrastructure\Communication\Repositories\MessageRepository;

class TwilioSmsProvider
{
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;
    private string $apiUrl;
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

        $this->accountSid = $settings['account_sid'];
        $this->authToken = $settings['auth_token'];
        $this->fromNumber = $settings['from_number'];
        $this->apiUrl = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";
        $this->enabled = true;
    }

    /**
     * Enviar SMS via Twilio REST API
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

        if (!$this->enabled) {
            error_log('[LimpVix] Twilio não configurado');
            $this->messageRepository->updateStatus($log_id, 'failed', 'Twilio não configurado');
            return false;
        }

        try {
            // HTTP Basic Auth: base64(account_sid:auth_token)
            $authHeader = 'Basic ' . base64_encode($this->accountSid . ':' . $this->authToken);

            // Request para Twilio API
            $response = wp_remote_post($this->apiUrl, [
                'headers' => [
                    'Authorization' => $authHeader,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => [
                    'From' => $this->fromNumber,
                    'To' => $to,
                    'Body' => $message,
                ],
                'timeout' => 15,
            ]);

            // Verificar erro de rede
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('[LimpVix] Erro de rede ao enviar SMS: ' . $error_message);
                $this->messageRepository->updateStatus($log_id, 'failed', $error_message);
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            // Sucesso (200, 201)
            if ($status_code >= 200 && $status_code < 300) {
                $response_data = json_encode([
                    'sid' => $body['sid'] ?? null,
                    'status' => $body['status'] ?? null,
                    'to' => $body['to'] ?? null,
                    'from' => $body['from'] ?? null,
                    'date_created' => $body['date_created'] ?? null,
                ]);

                error_log("[LimpVix] SMS enviado com sucesso via API REST: SID={$body['sid']}, To={$to}");

                $this->messageRepository->updateStatus($log_id, 'sent', $response_data);

                return true;
            }

            // Erro da API Twilio (4xx, 5xx)
            $error_message = $body['message'] ?? 'Erro desconhecido da API Twilio';
            $error_code = $body['code'] ?? $status_code;

            error_log("[LimpVix] Erro Twilio API: Code={$error_code}, Message={$error_message}");

            $this->messageRepository->updateStatus($log_id, 'failed', "Twilio Error {$error_code}: {$error_message}");

            return false;

        } catch (\Exception $e) {
            error_log('[LimpVix] Exceção ao enviar SMS: ' . $e->getMessage());
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
        return $this->enabled;
    }
}
