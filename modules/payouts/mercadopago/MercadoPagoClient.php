<?php
/**
 * MercadoPagoClient - Cliente HTTP para API Mercado Pago
 *
 * RESPONSABILIDADE:
 * - Comunicação HTTP com API do Mercado Pago
 * - Autenticação via Bearer Token
 * - Tratamento de respostas
 * - Timeouts controlados
 *
 * PRINCÍPIOS:
 * - HTTP puro (wp_remote_post)
 * - Fail-fast
 * - Sem retry automático
 * - Logging de todas as chamadas
 *
 * IMPORTANTE:
 * - NÃO contém lógica de negócio
 * - NÃO decide se pode chamar API
 * - Apenas executa HTTP
 *
 * API:
 * - Endpoint: POST https://api.mercadopago.com/v1/transfers
 * - Auth: Bearer {ACCESS_TOKEN}
 * - Content-Type: application/json
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Modules\Payouts\MercadoPago
 */

namespace LimpVix\Modules\Payouts\MercadoPago;

defined('ABSPATH') || exit;

class MercadoPagoClient
{
    /**
     * Base URL da API (Produção)
     */
    private const API_BASE_URL = 'https://api.mercadopago.com';

    /**
     * Endpoint de transfers
     */
    private const TRANSFERS_ENDPOINT = '/v1/transfers';

    /**
     * Access Token
     *
     * @var string
     */
    private $accessToken;

    /**
     * Timeout (segundos)
     *
     * @var int
     */
    private $timeout;

    /**
     * Construtor
     *
     * @param string $accessToken Bearer token do MP
     * @param int $timeout Timeout em segundos (default: 30)
     */
    public function __construct(string $accessToken, int $timeout = 30)
    {
        if (empty($accessToken)) {
            throw new \InvalidArgumentException('Access Token não pode ser vazio');
        }

        $this->accessToken = $accessToken;
        $this->timeout = $timeout;
    }

    /**
     * Executar transferência
     *
     * @param array $payload Payload da transferência
     * @param string|null $idempotencyKey UUID para idempotência (recomendado)
     * @return array Resposta da API [status, body, headers]
     * @throws \RuntimeException
     */
    public function transfer(array $payload, ?string $idempotencyKey = null): array
    {
        $url = self::API_BASE_URL . self::TRANSFERS_ENDPOINT;

        // Headers obrigatórios
        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ];

        // Adicionar X-Idempotency-Key (previne duplicatas em retry)
        if ($idempotencyKey !== null) {
            $headers['X-Idempotency-Key'] = $idempotencyKey;
        }

        // Configuração do request
        $args = [
            'method' => 'POST',
            'timeout' => $this->timeout,
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'sslverify' => true, // SEMPRE validar SSL em produção
            'user-agent' => 'LimpVix-Core/1.0 WordPress/' . get_bloginfo('version')
        ];

        // Log do request (sem token)
        $this->logRequest($url, $payload);

        // Executar request
        $response = wp_remote_post($url, $args);

        // Verificar erro de WordPress (network, SSL, etc)
        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            $this->logError('WP_Error', $error);

            throw new \RuntimeException("Falha ao comunicar com Mercado Pago: {$error}");
        }

        // Extrair dados da resposta
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $headers_response = wp_remote_retrieve_headers($response);

        // Parse do JSON
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logError('JSON_DECODE_ERROR', json_last_error_msg());
            $data = ['raw_body' => $body];
        }

        // Log da resposta
        $this->logResponse($status, $data);

        return [
            'status' => $status,
            'body' => $data,
            'headers' => $headers_response
        ];
    }

    /**
     * Log do request
     *
     * @param string $url
     * @param array $payload
     * @return void
     */
    private function logRequest(string $url, array $payload): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_mp_api_request', [
            'url' => $url,
            'payload' => $payload,
            'timestamp' => current_time('mysql')
        ]);
    }

    /**
     * Log da resposta
     *
     * @param int $status
     * @param array $body
     * @return void
     */
    private function logResponse(int $status, array $body): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_mp_api_response', [
            'status' => $status,
            'body' => $body,
            'timestamp' => current_time('mysql')
        ]);
    }

    /**
     * Log de erro
     *
     * @param string $errorType
     * @param string $errorMessage
     * @return void
     */
    private function logError(string $errorType, string $errorMessage): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_mp_api_error', [
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'timestamp' => current_time('mysql')
        ]);
    }

    /**
     * Obter Access Token (para debug/testes)
     *
     * @return string Apenas primeiros 10 caracteres
     */
    public function getAccessTokenPreview(): string
    {
        return substr($this->accessToken, 0, 10) . '...';
    }
}
