<?php
/**
 * MercadoPagoPayoutProvider
 *
 * Provider de payouts via Mercado Pago API REST
 *
 * ✅ SEM SDK - Usa wp_remote_post() para máxima compatibilidade
 * ✅ Funciona em qualquer hospedagem WordPress
 * ✅ Suporta PIX, transferência bancária e saldo MP
 * ✅ Arquitetura enterprise-grade
 *
 * @package LimpVix\Infrastructure\Finance\Providers
 * @since 0.1.3
 */

namespace LimpVix\Infrastructure\Finance\Providers;

use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;
use LimpVix\Core\Environment;

class MercadoPagoPayoutProvider
{
    private string $accessToken;
    private string $apiUrl = 'https://api.mercadopago.com/v1/payouts';
    private WpPayoutRepository $payoutRepository;
    private bool $enabled = false;
    private bool $sandbox = false;

    public function __construct()
    {
        $this->payoutRepository = new WpPayoutRepository();

        // PRIORITY 1: Environment variables (.env)
        // PRIORITY 2: wp_options (sincronizado pelo MercadoPagoDetector)
        // PRIORITY 3: Fallback para configuração antiga

        // Determinar environment (sandbox ou production)
        $environment = $this->determineEnvironment();

        // Obter access token baseado no environment
        $accessToken = $this->getAccessToken($environment);

        if (empty($accessToken)) {
            error_log('[LimpVix] Mercado Pago não configurado (access_token ausente)');
            return;
        }

        $this->accessToken = $accessToken;
        $this->sandbox = ($environment === 'sandbox');
        $this->enabled = true;

        // Ajustar URL se sandbox
        if ($this->sandbox) {
            $this->apiUrl = 'https://api.mercadopago.com/v1/sandbox/payouts';
        }

        error_log("[LimpVix] MercadoPagoPayoutProvider inicializado - Environment: {$environment}, Enabled: true");
    }

    /**
     * Determinar environment do MercadoPago
     *
     * PRIORITY ORDER:
     * 1. LIMPVIX_MP_ENVIRONMENT (.env)
     * 2. limpvix_mp_status['environment'] (wp_options)
     * 3. Default: 'production'
     *
     * @return string 'sandbox' ou 'production'
     */
    private function determineEnvironment(): string
    {
        // 1. Tentar .env
        if (class_exists('LimpVix\\Core\\Environment')) {
            try {
                $envVar = Environment::get('LIMPVIX_MP_ENVIRONMENT');
                if (in_array($envVar, ['sandbox', 'production'], true)) {
                    return $envVar;
                }
            } catch (\RuntimeException $e) {
                // Variable não existe, continuar para fallback
            }
        }

        // 2. Fallback: wp_options (backward compatibility)
        $mpStatus = get_option('limpvix_mp_status', []);
        return $mpStatus['environment'] ?? 'production';
    }

    /**
     * Obter access token do MercadoPago
     *
     * PRIORITY ORDER:
     * 1. LIMPVIX_MP_ACCESS_TOKEN_PROD/TEST (.env)
     * 2. limpvix_mp_access_token_prod/test (wp_options)
     * 3. Empty string (não configurado)
     *
     * @param string $environment 'sandbox' ou 'production'
     * @return string Access token ou empty string
     */
    private function getAccessToken(string $environment): string
    {
        $envKey = ($environment === 'sandbox')
            ? 'LIMPVIX_MP_ACCESS_TOKEN_TEST'
            : 'LIMPVIX_MP_ACCESS_TOKEN_PROD';

        $optionKey = ($environment === 'sandbox')
            ? 'limpvix_mp_access_token_test'
            : 'limpvix_mp_access_token_prod';

        // 1. Tentar .env
        if (class_exists('LimpVix\\Core\\Environment')) {
            try {
                $token = Environment::get($envKey);
                if (!empty($token)) {
                    error_log("[LimpVix] Using MercadoPago token from .env ({$envKey})");
                    return $token;
                }
            } catch (\RuntimeException $e) {
                // Variable não existe, continuar para fallback
            }
        }

        // 2. Fallback: wp_options (backward compatibility)
        $token = get_option($optionKey);
        if (!empty($token)) {
            error_log("[LimpVix] Using MercadoPago token from wp_options ({$optionKey})");
            return $token;
        }

        return '';
    }

    /**
     * Criar payout via Mercado Pago
     *
     * @param int $payout_id ID do payout no banco local
     * @return bool True se sucesso
     */
    public function createPayout(int $payout_id): bool
    {
        if (!$this->enabled) {
            error_log('[LimpVix] Mercado Pago não configurado');
            $this->payoutRepository->registerFailure($payout_id, 'Mercado Pago não configurado');
            return false;
        }

        // Buscar dados do payout
        $payout = $this->payoutRepository->getById($payout_id);

        if (!$payout) {
            error_log("[LimpVix] Payout #{$payout_id} não encontrado");
            return false;
        }

        // Validar status
        if ($payout['status'] !== 'approved') {
            error_log("[LimpVix] Payout #{$payout_id} não está aprovado (status: {$payout['status']})");
            return false;
        }

        // Atualizar status para processing
        $this->payoutRepository->updateStatus($payout_id, 'processing');

        try {
            // Montar payload conforme tipo de destinatário
            $payload = $this->buildPayload($payout);

            // Request para Mercado Pago
            $response = wp_remote_post($this->apiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                    'X-Idempotency-Key' => $this->generateIdempotencyKey($payout_id),
                ],
                'body' => json_encode($payload),
                'timeout' => 30,
            ]);

            // Verificar erro de rede
            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('[LimpVix] Erro de rede ao criar payout MP: ' . $error_message);
                $this->payoutRepository->registerFailure($payout_id, $error_message);
                return false;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            // Sucesso (200, 201)
            if ($status_code >= 200 && $status_code < 300) {
                $transfer_id = $body['id'] ?? null;
                $mp_status = $body['status'] ?? 'unknown';

                error_log("[LimpVix] Payout criado no MP: ID={$transfer_id}, Status={$mp_status}");

                // Salvar transfer_id
                if ($transfer_id) {
                    $this->payoutRepository->setTransferId($payout_id, (string) $transfer_id);
                }

                // Atualizar status local baseado no status do MP
                $local_status = $this->mapMpStatusToLocal($mp_status);
                $this->payoutRepository->updateStatus($payout_id, $local_status, json_encode($body));

                return true;
            }

            // Erro da API Mercado Pago (4xx, 5xx)
            $error_message = $body['message'] ?? ($body['error'] ?? 'Erro desconhecido da API MP');
            $error_code = $body['cause'][0]['code'] ?? $status_code;

            error_log("[LimpVix] Erro MP API: Code={$error_code}, Message={$error_message}");

            $this->payoutRepository->registerFailure($payout_id, "MP Error {$error_code}: {$error_message}");

            return false;

        } catch (\Exception $e) {
            error_log('[LimpVix] Exceção ao criar payout MP: ' . $e->getMessage());
            $this->payoutRepository->registerFailure($payout_id, $e->getMessage());
            return false;
        }
    }

    /**
     * Consultar status de um payout no MP
     *
     * @param string $transfer_id
     * @return array|null
     */
    public function getPayoutStatus(string $transfer_id): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $url = $this->apiUrl . '/' . $transfer_id;

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[LimpVix] Erro ao consultar status MP: ' . $response->get_error_message());
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 200 && $status_code < 300) {
            return $body;
        }

        return null;
    }

    /**
     * Sincronizar status de payouts em processamento
     *
     * @return int Número de payouts sincronizados
     */
    public function syncProcessingPayouts(): int
    {
        $processing_payouts = $this->payoutRepository->getByStatus('processing', 50);
        $synced = 0;

        foreach ($processing_payouts as $payout) {
            if (empty($payout['gateway_transfer_id'])) {
                continue;
            }

            $mp_data = $this->getPayoutStatus($payout['gateway_transfer_id']);

            if (!$mp_data) {
                continue;
            }

            $mp_status = $mp_data['status'] ?? 'unknown';
            $local_status = $this->mapMpStatusToLocal($mp_status);

            // Atualizar se status mudou
            if ($local_status !== $payout['status']) {
                $this->payoutRepository->updateStatus(
                    $payout['id'],
                    $local_status,
                    json_encode($mp_data)
                );
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Construir payload da API conforme tipo de destinatário
     *
     * @param array $payout
     * @return array
     */
    private function buildPayload(array $payout): array
    {
        $base = [
            'amount' => (float) $payout['net_amount'],
            'description' => "Repasse LimpVix - Pedido #{$payout['order_id']}",
            'external_reference' => "limpvix_payout_{$payout['id']}",
        ];

        // Tipo de destinatário
        switch ($payout['recipient_type']) {
            case 'pix':
                return array_merge($base, [
                    'payment_method_id' => 'pix',
                    'receiver' => [
                        'type' => 'pix_key',
                        'pix_key' => $payout['recipient_key'],
                        'name' => $payout['recipient_name'],
                        'document' => [
                            'type' => strlen($payout['recipient_document']) === 11 ? 'CPF' : 'CNPJ',
                            'number' => $payout['recipient_document'],
                        ],
                    ],
                ]);

            case 'bank_account':
                // Formato: banco|agencia|conta|tipo
                list($bank, $agency, $account, $account_type) = explode('|', $payout['recipient_key']);
                return array_merge($base, [
                    'payment_method_id' => 'bank_transfer',
                    'receiver' => [
                        'type' => 'bank_account',
                        'bank_code' => $bank,
                        'agency' => $agency,
                        'account' => $account,
                        'account_type' => $account_type, // checking, savings
                        'name' => $payout['recipient_name'],
                        'document' => [
                            'type' => strlen($payout['recipient_document']) === 11 ? 'CPF' : 'CNPJ',
                            'number' => $payout['recipient_document'],
                        ],
                    ],
                ]);

            case 'mp_account':
                return array_merge($base, [
                    'payment_method_id' => 'account_money',
                    'receiver' => [
                        'type' => 'collector',
                        'collector_id' => $payout['recipient_key'], // User ID do MP
                    ],
                ]);

            default:
                throw new \Exception('Tipo de destinatário inválido: ' . $payout['recipient_type']);
        }
    }

    /**
     * Mapear status do MP para status local
     *
     * @param string $mp_status
     * @return string
     */
    private function mapMpStatusToLocal(string $mp_status): string
    {
        $map = [
            'pending' => 'processing',
            'approved' => 'completed',
            'in_process' => 'processing',
            'rejected' => 'failed',
            'cancelled' => 'cancelled',
            'refunded' => 'failed',
        ];

        return $map[$mp_status] ?? 'processing';
    }

    /**
     * Gerar chave de idempotência (evita duplicação)
     *
     * @param int $payout_id
     * @return string
     */
    private function generateIdempotencyKey(int $payout_id): string
    {
        return wp_hash('limpvix_payout_' . $payout_id);
    }

    /**
     * Verificar se MP está configurado
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->enabled;
    }

    /**
     * Verificar se está em modo sandbox
     *
     * @return bool
     */
    public function isSandbox(): bool
    {
        return $this->sandbox;
    }
}
