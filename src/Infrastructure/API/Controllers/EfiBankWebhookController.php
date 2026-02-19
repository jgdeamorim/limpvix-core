<?php
/**
 * EfiBankWebhookController - REST API Controller para Webhooks PIX do EFI Bank
 *
 * RESPONSABILIDADE:
 * - Expor endpoint público para callbacks PIX do EFI Bank
 * - Validar estrutura do webhook
 * - Processar notificações de pagamento PIX recebido
 * - Chamar ProcessPaymentWebhook use case
 * - Retornar HTTP responses apropriados
 *
 * ENDPOINT:
 * - Method: POST
 * - URL: /wp-json/limpvix/v1/webhooks/efi-bank
 * - Auth: mTLS (verificado no nível do servidor)
 * - Rate Limit: None (EFI Bank controlled)
 *
 * SECURITY:
 * - EFI Bank usa mTLS para autenticar webhooks (não HMAC)
 * - Validação de estrutura do payload
 * - Idempotency (handled by use case)
 *
 * WEBHOOK PAYLOAD (EFI Bank PIX):
 * {
 *   "pix": [
 *     {
 *       "endToEndId": "E18236120202602191200s0289328944",
 *       "txid": "lmpx00001001abcdef1234567890",
 *       "chave": "sua-chave-pix@empresa.com",
 *       "valor": "150.00",
 *       "horario": "2026-02-19T12:00:00.000Z",
 *       "infoPagador": "Pagamento LimpVix"
 *     }
 *   ]
 * }
 *
 * HTTP RESPONSES:
 * - 200: Webhook processado com sucesso
 * - 400: Payload inválido
 * - 500: Erro interno
 *
 * @package LimpVix\Infrastructure\API\Controllers
 * @since 0.11.0
 */

namespace LimpVix\Infrastructure\API\Controllers;

use LimpVix\Application\UseCases\Finance\ProcessPaymentWebhook;

defined('ABSPATH') || exit;

final class EfiBankWebhookController
{
    private ProcessPaymentWebhook $processPaymentWebhook;

    public function __construct(ProcessPaymentWebhook $processPaymentWebhook)
    {
        $this->processPaymentWebhook = $processPaymentWebhook;
    }

    /**
     * Register REST API routes
     */
    public function register(): void
    {
        // POST /limpvix/v1/webhooks/efi-bank — recebe notificação PIX
        register_rest_route('limpvix/v1', '/webhooks/efi-bank', [
            'methods' => 'POST',
            'callback' => [$this, 'handleWebhook'],
            'permission_callback' => '__return_true', // Public (mTLS at server level)
        ]);

        // POST /limpvix/v1/webhooks/efi-bank/pix — alias para compatibilidade
        register_rest_route('limpvix/v1', '/webhooks/efi-bank/pix', [
            'methods' => 'POST',
            'callback' => [$this, 'handleWebhook'],
            'permission_callback' => '__return_true',
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
        error_log(sprintf(
            '[LimpVix][EFI-Webhook] PIX webhook received: %s',
            substr($request->get_body(), 0, 1000)
        ));

        try {
            $payload = $request->get_json_params();

            if (empty($payload)) {
                error_log('[LimpVix][EFI-Webhook] Empty payload');
                return new \WP_REST_Response([
                    'error' => 'Invalid payload',
                    'message' => 'Webhook payload is empty or invalid JSON',
                ], 400);
            }

            // Validar estrutura do webhook EFI Bank
            if (!isset($payload['pix']) || !is_array($payload['pix'])) {
                error_log('[LimpVix][EFI-Webhook] Invalid structure: missing pix array');
                return new \WP_REST_Response([
                    'error' => 'Invalid payload structure',
                    'message' => 'Expected pix array in webhook payload',
                ], 400);
            }

            $pixEvents = $payload['pix'];

            if (empty($pixEvents)) {
                return new \WP_REST_Response([
                    'success' => true,
                    'message' => 'No PIX events to process',
                ], 200);
            }

            $results = [];
            $hasErrors = false;

            // Processar cada evento PIX (normalmente só vem 1)
            foreach ($pixEvents as $index => $pixEvent) {
                $txid = $pixEvent['txid'] ?? null;

                if (empty($txid)) {
                    error_log(sprintf('[LimpVix][EFI-Webhook] PIX event #%d missing txid', $index));
                    $results[] = ['index' => $index, 'error' => 'Missing txid'];
                    $hasErrors = true;
                    continue;
                }

                error_log(sprintf(
                    '[LimpVix][EFI-Webhook] Processing PIX: txid=%s, valor=%s, e2e=%s',
                    $txid,
                    $pixEvent['valor'] ?? '?',
                    $pixEvent['endToEndId'] ?? '?'
                ));

                // Montar payload no formato esperado pelo use case
                $webhookPayload = ['pix' => [$pixEvent]];
                $headers = $this->extractHeaders($request);

                $result = $this->processPaymentWebhook->execute($webhookPayload, $headers);

                if ($result->isSuccess()) {
                    $data = $result->getValue();
                    error_log(sprintf(
                        '[LimpVix][EFI-Webhook] PIX processed: txid=%s, %s',
                        $txid,
                        json_encode($data)
                    ));
                    $results[] = [
                        'txid' => $txid,
                        'success' => true,
                        'data' => $data,
                    ];
                } else {
                    $error = $result->getError();
                    error_log(sprintf(
                        '[LimpVix][EFI-Webhook] PIX processing failed: txid=%s, error=%s',
                        $txid,
                        $error
                    ));
                    $results[] = [
                        'txid' => $txid,
                        'success' => false,
                        'error' => $error,
                    ];
                    $hasErrors = true;
                }
            }

            $statusCode = $hasErrors ? 200 : 200; // Always 200 to prevent EFI retries

            return new \WP_REST_Response([
                'success' => true,
                'message' => sprintf('Processed %d PIX event(s)', count($pixEvents)),
                'results' => $results,
            ], $statusCode);

        } catch (\Exception $e) {
            error_log(sprintf(
                '[LimpVix][EFI-Webhook] Exception: %s',
                $e->getMessage()
            ));

            // Retornar 200 mesmo em erro para evitar retries infinitos do EFI Bank
            return new \WP_REST_Response([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 200);
        }
    }

    /**
     * Extract headers for logging/tracing
     */
    private function extractHeaders(\WP_REST_Request $request): array
    {
        $headers = [];

        $tracingHeaders = [
            'x-request-id',
            'x-correlation-id',
            'user-agent',
        ];

        foreach ($tracingHeaders as $header) {
            $value = $request->get_header($header);
            if ($value !== null) {
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    /**
     * Get webhook endpoint URL (for admin display / EFI Bank configuration)
     */
    public static function getWebhookUrl(): string
    {
        return rest_url('limpvix/v1/webhooks/efi-bank');
    }
}
