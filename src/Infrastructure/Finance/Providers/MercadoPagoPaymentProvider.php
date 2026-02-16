<?php
/**
 * MercadoPagoPaymentProvider - Payment Collection Provider (Inbound)
 *
 * RESPONSABILIDADE:
 * - Criar cobranças de pagamento via MercadoPago API
 * - Consultar status de pagamentos
 * - Cancelar pagamentos pendentes
 * - Distinct from MercadoPagoPayoutProvider (payouts = outbound, payments = inbound)
 *
 * API ENDPOINTS:
 * - POST /v1/payments - Criar cobrança (PIX, credit card, boleto)
 * - GET /v1/payments/{id} - Consultar status
 * - PUT /v1/payments/{id} - Cancelar pagamento
 *
 * SUPPORTED PAYMENT METHODS:
 * - PIX (instant, QR code)
 * - Credit card (stored token)
 * - Boleto (bank slip)
 *
 * @package LimpVix\Infrastructure\Finance\Providers
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Infrastructure\Finance\Providers;

use LimpVix\Domain\Finance\RecurringPayment;

defined('ABSPATH') || exit;

class MercadoPagoPaymentProvider
{
    private ?string $accessToken;
    private string $apiUrl;
    private bool $isSandbox;

    /**
     * Constructor
     *
     * @param string|null $accessToken Override for testing
     * @param bool $useSandbox Force sandbox mode
     */
    public function __construct(?string $accessToken = null, bool $useSandbox = false)
    {
        $this->isSandbox = $useSandbox || (defined('WP_DEBUG') && WP_DEBUG);
        // NÃO lançar exceção no construtor - validar apenas quando usar
        $this->accessToken = $accessToken;
        $this->apiUrl = 'https://api.mercadopago.com';
    }

    /**
     * Create payment charge for recurring contract
     *
     * @param RecurringPayment $payment
     * @param array $paymentMethodData Contract payment method details
     * @return array MercadoPago API response
     * @throws \RuntimeException if API call fails
     */
    public function createPaymentCharge(
        RecurringPayment $payment,
        array $paymentMethodData
    ): array {
        // Validar credenciais apenas quando usar (lazy validation)
        $this->ensureAccessToken();

        $payload = $this->buildPaymentPayload($payment, $paymentMethodData);

        $response = wp_remote_post(
            $this->apiUrl . '/v1/payments',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                    'X-Idempotency-Key' => $payment->getPaymentUuid(),
                ],
                'body' => json_encode($payload),
                'timeout' => 30,
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                sprintf(
                    'MercadoPago API request failed: %s',
                    $response->get_error_message()
                )
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode >= 400) {
            throw new \RuntimeException(
                sprintf(
                    'MercadoPago API error %d: %s',
                    $statusCode,
                    $body['message'] ?? 'Unknown error'
                )
            );
        }

        return $body;
    }

    /**
     * Get payment status from MercadoPago
     *
     * @param string $paymentId MercadoPago payment ID
     * @return array Payment data
     * @throws \RuntimeException if API call fails
     */
    public function getPaymentStatus(string $paymentId): array
    {
        // Validar credenciais apenas quando usar (lazy validation)
        $this->ensureAccessToken();

        $response = wp_remote_get(
            $this->apiUrl . '/v1/payments/' . $paymentId,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to get payment status: %s',
                    $response->get_error_message()
                )
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode >= 400) {
            throw new \RuntimeException(
                sprintf(
                    'MercadoPago API error %d: %s',
                    $statusCode,
                    $body['message'] ?? 'Unknown error'
                )
            );
        }

        return $body;
    }

    /**
     * Cancel pending payment
     *
     * @param string $paymentId
     * @return array
     * @throws \RuntimeException
     */
    public function cancelPayment(string $paymentId): array
    {
        // Validar credenciais apenas quando usar (lazy validation)
        $this->ensureAccessToken();

        $response = wp_remote_request(
            $this->apiUrl . '/v1/payments/' . $paymentId,
            [
                'method' => 'PUT',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode(['status' => 'cancelled']),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to cancel payment: %s',
                    $response->get_error_message()
                )
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }

    /**
     * Build payment payload for MercadoPago API
     *
     * @param RecurringPayment $payment
     * @param array $paymentMethodData
     * @return array
     */
    private function buildPaymentPayload(
        RecurringPayment $payment,
        array $paymentMethodData
    ): array {
        $basePayload = [
            'transaction_amount' => $payment->getAmount(),
            'description' => sprintf(
                'LimpVix - Contrato #%d - Ciclo %d',
                $payment->getContractId(),
                $payment->getBillingCycleNumber()
            ),
            'payment_method_id' => $paymentMethodData['method_id'] ?? 'pix',
            'payer' => [
                'email' => $paymentMethodData['payer_email'] ?? '',
                'first_name' => $paymentMethodData['payer_name'] ?? '',
                'identification' => [
                    'type' => $paymentMethodData['doc_type'] ?? 'CPF',
                    'number' => $paymentMethodData['doc_number'] ?? '',
                ],
            ],
            'external_reference' => $payment->getPaymentUuid(),
            'notification_url' => $this->getWebhookUrl(),
            'statement_descriptor' => 'LIMPVIX',
        ];

        // Add payment method specific fields
        if (isset($paymentMethodData['card_token'])) {
            $basePayload['token'] = $paymentMethodData['card_token'];
            $basePayload['installments'] = 1;
        }

        // Sandbox mode
        if ($this->isSandbox) {
            $basePayload['description'] .= ' [SANDBOX]';
        }

        return $basePayload;
    }

    /**
     * Get webhook URL for MercadoPago callbacks
     */
    private function getWebhookUrl(): string
    {
        return rest_url('limpvix/v1/webhooks/mercadopago');
    }

    /**
     * Ensure access token is loaded (lazy loading)
     *
     * @throws \RuntimeException if not configured when trying to use
     */
    private function ensureAccessToken(): void
    {
        if ($this->accessToken === null) {
            $this->accessToken = $this->getAccessTokenFromConfig();
        }
    }

    /**
     * Get access token from WordPress options or official plugin
     */
    private function getAccessTokenFromConfig(): string
    {
        // Try to get from LimpVix settings
        $token = get_option('limpvix_mercadopago_access_token');

        if (empty($token)) {
            // Try to get from official WooCommerce MercadoPago plugin
            $token = get_option('_mp_access_token_prod');
        }

        if ($this->isSandbox && empty($token)) {
            $token = get_option('_mp_access_token_test');
        }

        if (empty($token)) {
            throw new \RuntimeException(
                'MercadoPago access token not configured. ' .
                'Please configure MercadoPago credentials in LimpVix settings.'
            );
        }

        return $token;
    }

    /**
     * Verify webhook signature (security)
     *
     * @param array $headers
     * @param string $body
     * @return bool
     */
    public function verifyWebhookSignature(array $headers, string $body): bool
    {
        // MercadoPago sends x-signature and x-request-id headers
        $signature = $headers['x-signature'] ?? $headers['X-Signature'] ?? null;
        $requestId = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? null;

        if (empty($signature) || empty($requestId)) {
            return false;
        }

        // Extract ts and v1 from signature
        // Format: ts=1234567890,v1=hash_value
        preg_match('/ts=(\d+),v1=([a-f0-9]+)/', $signature, $matches);

        if (count($matches) !== 3) {
            return false;
        }

        $timestamp = $matches[1];
        $receivedHash = $matches[2];

        // Get secret from settings
        $secret = get_option('limpvix_mercadopago_webhook_secret');

        if (empty($secret)) {
            error_log('[LimpVix] MercadoPago webhook secret not configured');
            return false;
        }

        // Compute expected signature
        $manifest = "id:{$requestId};request-id:{$requestId};ts:{$timestamp};";
        $expectedHash = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expectedHash, $receivedHash);
    }

    /**
     * Parse webhook payload
     *
     * @param array $payload
     * @return array Normalized data
     */
    public function parseWebhookPayload(array $payload): array
    {
        return [
            'event_type' => $payload['type'] ?? '',
            'payment_id' => $payload['data']['id'] ?? null,
            'action' => $payload['action'] ?? '',
            'date_created' => $payload['date_created'] ?? null,
        ];
    }

    /**
     * Map MercadoPago status to RecurringPaymentStatus
     *
     * @param string $mpStatus MercadoPago status
     * @return string RecurringPaymentStatus value
     */
    public function mapStatusToRecurringPayment(string $mpStatus): string
    {
        $statusMap = [
            'pending' => 'pending',
            'in_process' => 'processing',
            'in_mediation' => 'processing',
            'approved' => 'completed',
            'authorized' => 'completed',
            'rejected' => 'failed',
            'cancelled' => 'cancelled',
            'refunded' => 'cancelled',
            'charged_back' => 'failed',
        ];

        return $statusMap[$mpStatus] ?? 'failed';
    }

    /**
     * Get failure reason from MercadoPago status detail
     *
     * @param string $statusDetail
     * @return string
     */
    public function getFailureReason(string $statusDetail): string
    {
        $reasonMap = [
            'cc_rejected_insufficient_amount' => 'Saldo insuficiente',
            'cc_rejected_bad_filled_security_code' => 'Código de segurança inválido',
            'cc_rejected_call_for_authorize' => 'Autorização necessária',
            'cc_rejected_card_disabled' => 'Cartão desabilitado',
            'cc_rejected_duplicated_payment' => 'Pagamento duplicado',
            'cc_rejected_high_risk' => 'Pagamento de alto risco',
            'cc_rejected_invalid_installments' => 'Parcelas inválidas',
            'cc_rejected_max_attempts' => 'Máximo de tentativas excedido',
            'cc_rejected_other_reason' => 'Rejeitado pela operadora',
        ];

        return $reasonMap[$statusDetail] ?? $statusDetail;
    }
}
