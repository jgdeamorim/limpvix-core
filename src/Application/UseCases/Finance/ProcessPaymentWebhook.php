<?php
/**
 * ProcessPaymentWebhook - Use Case para Processar Webhook do MercadoPago
 *
 * RESPONSABILIDADE:
 * - Processar callbacks do MercadoPago quando status de pagamento muda
 * - Verificar assinatura do webhook (segurança)
 * - Buscar pagamento no banco via gateway_transaction_id
 * - Atualizar status do pagamento
 * - Se completed: Renovar contrato
 * - Se failed: Registrar falha e verificar retry
 * - Dispatch domain events
 *
 * WORKFLOW:
 * 1. Verificar assinatura do webhook (HMAC SHA256)
 * 2. Extrair payment_id do payload
 * 3. Consultar MercadoPago API para obter status atual (anti-fraude)
 * 4. Buscar RecurringPayment pelo gateway_transaction_id
 * 5. Verificar se status mudou (idempotency)
 * 6. Atualizar status do payment baseado no MP status
 * 7. Se completed: Renovar contrato via Contract::renewWithPayment()
 * 8. Se failed: Marcar falha e verificar retry
 * 9. Salvar payment atualizado
 * 10. Dispatch domain events (RecurringPaymentCompleted/Failed)
 *
 * SECURITY:
 * - Webhook signature verification (HMAC)
 * - Double-check with MercadoPago API (prevent fake webhooks)
 * - Idempotency (ignore duplicate webhooks)
 *
 * @package LimpVix\Application\UseCases\Finance
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Application\UseCases\Finance;

use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Finance\RecurringPaymentRepositoryInterface;
use LimpVix\Domain\Finance\PaymentProviderInterface;
use LimpVix\Shared\Result;

defined('ABSPATH') || exit;

final class ProcessPaymentWebhook
{
    private RecurringPaymentRepositoryInterface $paymentRepository;
    private ContractRepositoryInterface $contractRepository;
    private PaymentProviderInterface $paymentProvider;

    public function __construct(
        RecurringPaymentRepositoryInterface $paymentRepository,
        ContractRepositoryInterface $contractRepository,
        PaymentProviderInterface $paymentProvider
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->contractRepository = $contractRepository;
        $this->paymentProvider = $paymentProvider;
    }

    /**
     * Execute use case
     *
     * @param array $webhookPayload MercadoPago webhook data
     * @param array $headers HTTP headers
     * @return Result
     */
    public function execute(array $webhookPayload, array $headers = []): Result
    {
        try {
            // 1. Verify webhook signature (security)
            if (!empty($headers)) {
                $isValid = $this->paymentProvider->verifyWebhookSignature(
                    $headers,
                    json_encode($webhookPayload)
                );

                if (!$isValid) {
                    return Result::fail('Invalid webhook signature');
                }
            }

            // 2. Parse webhook payload
            $parsed = $this->paymentProvider->parseWebhookPayload($webhookPayload);

            if (empty($parsed['payment_id'])) {
                return Result::fail('Missing payment_id in webhook payload');
            }

            // Skip non-payment events
            if ($parsed['event_type'] !== 'payment' && $parsed['event_type'] !== 'payment.updated') {
                return Result::ok(['message' => 'Ignored non-payment event']);
            }

            $paymentId = (string) $parsed['payment_id'];

            // 3. Query MercadoPago API for current status (anti-fraud)
            $mpPaymentData = $this->paymentProvider->getPaymentStatus($paymentId);

            // 4. Find RecurringPayment by gateway_transaction_id
            $payment = $this->paymentRepository->findByGatewayTransactionId($paymentId);

            if ($payment === null) {
                // Payment not found - might be a non-recurring payment
                return Result::fail(
                    sprintf('RecurringPayment not found for gateway ID: %s', $paymentId)
                );
            }

            // 5. Check if status changed (idempotency - ignore duplicate webhooks)
            $currentStatus = $payment->getStatus()->getValue();
            $newStatus = $this->paymentProvider->mapStatusToRecurringPayment(
                $mpPaymentData['status']
            );

            if ($currentStatus === $newStatus) {
                // Status hasn't changed, ignore webhook
                return Result::ok([
                    'message' => 'Status unchanged, webhook ignored',
                    'payment_uuid' => $payment->getPaymentUuid(),
                    'status' => $currentStatus,
                ]);
            }

            // 6. Update payment status
            if ($newStatus === 'completed') {
                $paidAt = new \DateTimeImmutable($mpPaymentData['date_approved'] ?? 'now');
                $payment->markAsCompleted($paidAt);

                // 7. Renew contract
                $this->renewContract($payment);

            } elseif ($newStatus === 'failed') {
                $failureReason = $this->paymentProvider->getFailureReason(
                    $mpPaymentData['status_detail'] ?? 'unknown'
                );
                $payment->markAsFailed($failureReason);
            }

            // 9. Save updated payment
            $this->paymentRepository->save($payment);

            // 10. Domain events are automatically dispatched via aggregate

            return Result::ok([
                'payment_uuid' => $payment->getPaymentUuid(),
                'old_status' => $currentStatus,
                'new_status' => $payment->getStatus()->getValue(),
                'mp_status' => $mpPaymentData['status'],
                'contract_id' => $payment->getContractId(),
            ]);

        } catch (\Exception $e) {
            return Result::fail(
                sprintf('Failed to process webhook: %s', $e->getMessage())
            );
        }
    }

    /**
     * Renew contract after successful payment
     *
     * @param \LimpVix\Domain\Finance\RecurringPayment $payment
     * @return void
     * @throws \Exception
     */
    private function renewContract($payment): void
    {
        $contract = $this->contractRepository->findById(
            ContractId::fromInt($payment->getContractId())
        );

        if ($contract === null) {
            throw new \RuntimeException(
                sprintf('Contract %d not found', $payment->getContractId())
            );
        }

        // Calculate new end date (extend by contract period)
        $currentEndDate = $contract->getEndDate() ?? new \DateTimeImmutable();
        $newEndDate = $this->calculateNewEndDate($currentEndDate, $contract->getContractType());

        // Renew contract with payment
        $contract->renewWithPayment($payment, $newEndDate);

        // Save contract
        $this->contractRepository->save($contract);

        // Log renewal
        error_log(sprintf(
            '[LimpVix] Contract %d renewed via payment %s. New end_date: %s',
            $contract->getId()->toInt(),
            $payment->getPaymentUuid(),
            $newEndDate->format('Y-m-d')
        ));
    }

    /**
     * Calculate new end date based on contract type
     *
     * @param \DateTimeImmutable $currentEndDate
     * @param string $contractType weekly, biweekly, monthly
     * @return \DateTimeImmutable
     */
    private function calculateNewEndDate(
        \DateTimeImmutable $currentEndDate,
        string $contractType
    ): \DateTimeImmutable {
        switch ($contractType) {
            case 'weekly':
                return $currentEndDate->modify('+1 week');

            case 'biweekly':
                return $currentEndDate->modify('+2 weeks');

            case 'monthly':
            default:
                return $currentEndDate->modify('+1 month');
        }
    }
}
