<?php
/**
 * MercadoPagoPayoutProvider - Implementação MP do Provider
 *
 * RESPONSABILIDADE:
 * - Implementar PayoutProviderInterface para Mercado Pago
 * - Traduzir Payout → API payload
 * - Traduzir API response → PayoutResult
 * - Tratamento de erros específicos do MP
 *
 * PRINCÍPIOS:
 * - Adapter Pattern
 * - Single Responsibility
 * - Fail-fast
 * - Zero retry automático
 *
 * IMPORTANTE:
 * - NÃO decide se pode executar (Use Case decide)
 * - NÃO garante idempotência (Repository garante)
 * - Apenas executa tecnicamente
 *
 * CÓDIGOS DE ERRO MP:
 * - 400: Payload inválido
 * - 401: Token inválido
 * - 403: Saldo insuficiente
 * - 404: Receiver ID inválido
 * - 500: Erro interno MP
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Modules\Payouts\MercadoPago
 */

namespace LimpVix\Modules\Payouts\MercadoPago;

use LimpVix\Modules\Payouts\PayoutProviderInterface;

defined('ABSPATH') || exit;

class MercadoPagoPayoutProvider implements PayoutProviderInterface
{
    /**
     * Cliente HTTP
     *
     * @var MercadoPagoClient
     */
    private $client;

    /**
     * Construtor
     *
     * @param MercadoPagoClient $client
     */
    public function __construct(MercadoPagoClient $client)
    {
        $this->client = $client;
    }

    /**
     * Executar transferência
     *
     * @param Payout $payout
     * @return PayoutResult
     */
    public function transfer(Payout $payout): PayoutResult
    {
        try {
            // 1. Converter para payload da API MP
            $payload = $payout->toMercadoPagoPayload();

            // 2. Executar request (com idempotency key)
            $response = $this->client->transfer($payload, $payout->getPayoutId());

            // 3. Processar resposta
            return $this->processResponse($response);

        } catch (\RuntimeException $e) {
            // Erro de comunicação (timeout, network, etc)
            return PayoutResult::failure(
                'COMMUNICATION_ERROR',
                $e->getMessage(),
                null,
                null
            );
        } catch (\Exception $e) {
            // Erro inesperado
            return PayoutResult::failure(
                'UNEXPECTED_ERROR',
                $e->getMessage(),
                null,
                null
            );
        }
    }

    /**
     * Processar resposta da API
     *
     * @param array $response [status, body, headers]
     * @return PayoutResult
     */
    private function processResponse(array $response): PayoutResult
    {
        $status = $response['status'];
        $body = $response['body'];

        // Sucesso (201 Created ou 200 OK)
        if ($status === 201 || $status === 200) {
            return $this->processSuccess($status, $body);
        }

        // Falha
        return $this->processFailure($status, $body);
    }

    /**
     * Processar sucesso
     *
     * @param int $status
     * @param array $body
     * @return PayoutResult
     */
    private function processSuccess(int $status, array $body): PayoutResult
    {
        // Extrair Transfer ID
        $transferId = $body['id'] ?? null;

        if ($transferId === null) {
            return PayoutResult::failure(
                'INVALID_RESPONSE',
                'Resposta do MP não contém Transfer ID',
                $status,
                $body
            );
        }

        // Verificar status da transferência
        $transferStatus = $body['status'] ?? 'unknown';

        if ($transferStatus !== 'approved') {
            return PayoutResult::failure(
                'TRANSFER_NOT_APPROVED',
                "Transfer status: {$transferStatus}",
                $status,
                $body
            );
        }

        return PayoutResult::success(
            (string) $transferId,
            $status,
            $body
        );
    }

    /**
     * Processar falha
     *
     * @param int $status
     * @param array $body
     * @return PayoutResult
     */
    private function processFailure(int $status, array $body): PayoutResult
    {
        // Extrair código e mensagem de erro
        $errorCode = $this->extractErrorCode($status, $body);
        $errorMessage = $this->extractErrorMessage($status, $body);

        return PayoutResult::failure(
            $errorCode,
            $errorMessage,
            $status,
            $body
        );
    }

    /**
     * Extrair código de erro
     *
     * @param int $status
     * @param array $body
     * @return string
     */
    private function extractErrorCode(int $status, array $body): string
    {
        // MP retorna erro em diferentes formatos
        // Prioridade: body['error'] > body['message'] > HTTP status

        if (isset($body['error'])) {
            return (string) $body['error'];
        }

        if (isset($body['cause'])) {
            return (string) $body['cause'];
        }

        // Mapear HTTP status para código
        return $this->mapHttpStatusToErrorCode($status);
    }

    /**
     * Extrair mensagem de erro
     *
     * @param int $status
     * @param array $body
     * @return string
     */
    private function extractErrorMessage(int $status, array $body): string
    {
        // MP pode retornar mensagem em diferentes campos
        if (isset($body['message'])) {
            return (string) $body['message'];
        }

        if (isset($body['error_message'])) {
            return (string) $body['error_message'];
        }

        // Fallback: mensagem genérica
        return $this->getGenericErrorMessage($status);
    }

    /**
     * Mapear HTTP status para código de erro
     *
     * @param int $status
     * @return string
     */
    private function mapHttpStatusToErrorCode(int $status): string
    {
        $map = [
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            500 => 'INTERNAL_SERVER_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
            504 => 'GATEWAY_TIMEOUT'
        ];

        return $map[$status] ?? 'HTTP_' . $status;
    }

    /**
     * Obter mensagem de erro genérica
     *
     * @param int $status
     * @return string
     */
    private function getGenericErrorMessage(int $status): string
    {
        $messages = [
            400 => 'Payload inválido',
            401 => 'Token de autenticação inválido',
            403 => 'Operação não permitida (saldo insuficiente ou outro bloqueio)',
            404 => 'Receiver ID não encontrado',
            409 => 'Conflito na operação',
            500 => 'Erro interno do Mercado Pago',
            503 => 'Serviço temporariamente indisponível',
            504 => 'Timeout na comunicação com Mercado Pago'
        ];

        return $messages[$status] ?? "Erro HTTP {$status}";
    }

    /**
     * Verificar disponibilidade
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        // Verificar se cliente está configurado
        try {
            $tokenPreview = $this->client->getAccessTokenPreview();
            return !empty($tokenPreview);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obter nome do provider
     *
     * @return string
     */
    public function getName(): string
    {
        return 'mercadopago';
    }
}
