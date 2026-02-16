<?php
/**
 * MercadoPagoWebhookController - REST API Controller for MercadoPago Webhooks
 *
 * RESPONSABILIDADE:
 * - Expor endpoint público para MercadoPago callbacks
 * - Validar assinatura do webhook (HMAC SHA256)
 * - Processar notificações de mudança de status de pagamento
 * - Chamar ProcessPaymentWebhook use case
 * - Retornar HTTP responses apropriados
 *
 * ENDPOINT:
 * - Method: POST
 * - URL: /wp-json/limpvix/v1/webhooks/mercadopago
 * - Auth: None (signature validation instead)
 * - Rate Limit: None (MercadoPago controlled)
 *
 * SECURITY:
 * - Webhook signature verification (HMAC SHA256)
 * - Payload validation
 * - Idempotency (handled by use case)
 *
 * WEBHOOK PAYLOAD EXAMPLE:
 * {
 *   "id": 12345,
 *   "live_mode": true,
 *   "type": "payment",
 *   "date_created": "2026-02-09T12:00:00Z",
 *   "application_id": 123456789,
 *   "user_id": 987654321,
 *   "version": 1,
 *   "api_version": "v1",
 *   "action": "payment.updated",
 *   "data": {
 *     "id": "67890"
 *   }
 * }
 *
 * HTTP RESPONSES:
 * - 200: Webhook processed successfully
 * - 400: Invalid payload or missing data
 * - 403: Invalid signature (security)
 * - 500: Internal server error
 *
 * @package LimpVix\Infrastructure\API\Controllers
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Infrastructure\API\Controllers;

use LimpVix\Application\UseCases\Finance\ProcessPaymentWebhook;

defined('ABSPATH') || exit;

final class MercadoPagoWebhookController
{
    private ProcessPaymentWebhook $processPaymentWebhook;

    public function __construct(ProcessPaymentWebhook $processPaymentWebhook)
    {
        $this->processPaymentWebhook = $processPaymentWebhook;
    }

    /**
     * Register REST API routes
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route('limpvix/v1', '/webhooks/mercadopago', [
            'methods' => 'POST',
            'callback' => [$this, 'handleWebhook'],
            'permission_callback' => '__return_true', // Public endpoint (signature validation instead)
        ]);
    }

    /**
     * Handle webhook POST request
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handleWebhook(\WP_REST_Request $request): \WP_REST_Response
    {
        // Log webhook received
        error_log(sprintf(
            '[LimpVix] MercadoPago webhook received: %s',
            $request->get_body()
        ));

        try {
            // Extract payload
            $payload = $request->get_json_params();

            if (empty($payload)) {
                error_log('[LimpVix] Webhook error: Empty payload');
                return new \WP_REST_Response([
                    'error' => 'Invalid payload',
                    'message' => 'Webhook payload is empty or invalid JSON',
                ], 400);
            }

            // Extract headers for signature verification
            $headers = $this->extractHeaders($request);

            // Call use case
            $result = $this->processPaymentWebhook->execute($payload, $headers);

            if ($result->isSuccess()) {
                // Success
                $data = $result->getValue();

                error_log(sprintf(
                    '[LimpVix] Webhook processed successfully: %s',
                    json_encode($data)
                ));

                return new \WP_REST_Response([
                    'success' => true,
                    'message' => 'Webhook processed successfully',
                    'data' => $data,
                ], 200);

            } else {
                // Business logic error (invalid signature, payment not found, etc.)
                $error = $result->getError();

                // Check if it's a signature error (security)
                if (strpos($error, 'signature') !== false) {
                    error_log(sprintf(
                        '[LimpVix] Webhook SECURITY ERROR: %s',
                        $error
                    ));

                    return new \WP_REST_Response([
                        'error' => 'Forbidden',
                        'message' => 'Invalid webhook signature',
                    ], 403);
                }

                // Other business errors
                error_log(sprintf(
                    '[LimpVix] Webhook processing failed: %s',
                    $error
                ));

                return new \WP_REST_Response([
                    'error' => 'Processing failed',
                    'message' => $error,
                ], 400);
            }

        } catch (\Exception $e) {
            // Unexpected error
            error_log(sprintf(
                '[LimpVix] Webhook exception: %s',
                $e->getMessage()
            ));

            return new \WP_REST_Response([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract headers from request for signature validation
     *
     * @param \WP_REST_Request $request
     * @return array
     */
    private function extractHeaders(\WP_REST_Request $request): array
    {
        $headers = [];

        // MercadoPago sends these headers for signature verification
        $requiredHeaders = [
            'x-signature',
            'x-request-id',
        ];

        foreach ($requiredHeaders as $header) {
            $value = $request->get_header($header);
            if ($value !== null) {
                $headers[$header] = $value;
            }
        }

        // Also check uppercase variants (HTTP headers are case-insensitive)
        $upperVariants = [
            'X-Signature',
            'X-Request-Id',
        ];

        foreach ($upperVariants as $header) {
            $value = $request->get_header($header);
            if ($value !== null) {
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    /**
     * Get webhook endpoint URL
     * Useful for displaying to user during setup
     *
     * @return string
     */
    public static function getWebhookUrl(): string
    {
        return rest_url('limpvix/v1/webhooks/mercadopago');
    }

    /**
     * Test webhook endpoint manually
     * For debugging purposes
     *
     * @param array $testPayload
     * @return array
     */
    public function testWebhook(array $testPayload): array
    {
        error_log('[LimpVix] Testing webhook with payload: ' . json_encode($testPayload));

        // Create mock request
        $request = new \WP_REST_Request('POST', '/limpvix/v1/webhooks/mercadopago');
        $request->set_body(json_encode($testPayload));
        $request->set_header('Content-Type', 'application/json');

        // Process
        $response = $this->handleWebhook($request);

        return [
            'status_code' => $response->get_status(),
            'data' => $response->get_data(),
        ];
    }
}
