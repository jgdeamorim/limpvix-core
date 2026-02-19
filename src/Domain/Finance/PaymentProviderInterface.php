<?php
/**
 * PaymentProviderInterface — Contrato para Providers de Pagamento (Cash-In)
 *
 * Abstrai a criação de cobranças recorrentes (PIX QR Code, cartão, boleto)
 * independente do gateway (EFI Bank, MercadoPago, etc).
 *
 * @package LimpVix\Domain\Finance
 * @since 0.3.0
 */

namespace LimpVix\Domain\Finance;

use LimpVix\Domain\Finance\RecurringPayment;

defined('ABSPATH') || exit;

interface PaymentProviderInterface
{
    /**
     * Criar cobrança para um pagamento recorrente
     *
     * @param RecurringPayment $payment  Aggregate de pagamento recorrente
     * @param array            $paymentData  Dados do pagador (email, nome, CPF, etc.)
     * @return array  Resposta normalizada do gateway:
     *                - 'id'           => string   ID da transação no gateway
     *                - 'status'       => string   pending|approved|failed|in_process
     *                - 'status_detail'=> string   Detalhe do status
     *                - 'pix_qrcode'   => string|null  Código copia-e-cola PIX (quando PIX)
     *                - 'pix_qrimage'  => string|null  QR code em base64 (quando PIX)
     *                - 'raw'          => array    Resposta completa do gateway
     * @throws \RuntimeException em caso de falha de comunicação
     */
    public function createPaymentCharge(RecurringPayment $payment, array $paymentData): array;

    /**
     * Verificar se o provider está configurado e disponível
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Obter descrição humana de uma falha de pagamento
     *
     * @param string $statusDetail  Código de status detalhado do gateway
     * @return string  Mensagem em português para o cliente/admin
     */
    public function getFailureReason(string $statusDetail): string;

    /**
     * Consultar status atual de um pagamento no gateway
     *
     * @param string $paymentId  ID da transação no gateway (txid para EFI Bank)
     * @return array  Dados do pagamento incluindo 'status'
     */
    public function getPaymentStatus(string $paymentId): array;

    /**
     * Verificar assinatura do webhook (segurança)
     *
     * @param array  $headers  Headers HTTP do request
     * @param string $body     Body cru do request
     * @return bool  True se assinatura válida
     */
    public function verifyWebhookSignature(array $headers, string $body): bool;

    /**
     * Parsear payload do webhook do gateway
     *
     * @param array $payload  Payload JSON decodificado
     * @return array  Dados normalizados: payment_id, event_type, status
     */
    public function parseWebhookPayload(array $payload): array;

    /**
     * Mapear status do gateway para status do RecurringPayment
     *
     * @param string $gatewayStatus  Status no formato do gateway
     * @return string  Status normalizado: pending, completed, failed
     */
    public function mapStatusToRecurringPayment(string $gatewayStatus): string;
}
