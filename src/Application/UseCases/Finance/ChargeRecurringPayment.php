<?php
/**
 * ChargeRecurringPayment - Use Case para Criar Cobrança Recorrente
 *
 * RESPONSABILIDADE:
 * - Orquestrar criação de cobrança recorrente para contrato
 * - Validar elegibilidade do contrato (auto_renew, active)
 * - Prevenir duplicatas (idempotency)
 * - Criar RecurringPayment aggregate
 * - Enviar cobrança ao MercadoPago
 * - Atualizar status com resposta do gateway
 *
 * WORKFLOW:
 * 1. Buscar contrato por ID
 * 2. Validar: auto_renew=true, status=active
 * 3. Calcular billing cycle number
 * 4. Verificar se já existe payment para este ciclo (idempotency)
 * 5. Criar RecurringPayment (status=pending)
 * 6. Buscar payment method do contrato
 * 7. Chamar MercadoPago API para criar cobrança
 * 8. Atualizar payment: status=processing, gateway_transaction_id
 * 9. Salvar no banco
 * 10. Retornar Result com payment_uuid
 *
 * CASOS DE USO:
 * - Cron job diário (checkExpiringContracts)
 * - Retry manual pelo admin
 * - Webhook fallback (se payment não foi criado)
 *
 * @package LimpVix\Application\UseCases\Finance
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Application\UseCases\Finance;

use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Finance\RecurringPayment;
use LimpVix\Domain\Finance\RecurringPaymentRepositoryInterface;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPaymentProvider;
use LimpVix\Common\Result;

defined('ABSPATH') || exit;

class ChargeRecurringPayment
{
    private ContractRepositoryInterface $contractRepository;
    private RecurringPaymentRepositoryInterface $paymentRepository;
    private MercadoPagoPaymentProvider $paymentProvider;

    public function __construct(
        ContractRepositoryInterface $contractRepository,
        RecurringPaymentRepositoryInterface $paymentRepository,
        MercadoPagoPaymentProvider $paymentProvider
    ) {
        $this->contractRepository = $contractRepository;
        $this->paymentRepository = $paymentRepository;
        $this->paymentProvider = $paymentProvider;
    }

    /**
     * Execute use case
     *
     * @param int $contractId
     * @return Result<array{payment_uuid: string, gateway_transaction_id: string, status: string}>
     */
    public function execute(int $contractId): Result
    {
        try {
            // 1. Load contract
            $contract = $this->contractRepository->findById(ContractId::fromInt($contractId));

            if ($contract === null) {
                return Result::fail(sprintf('Contract %d not found', $contractId));
            }

            // 2. Validate contract eligibility
            if (!$contract->isAutoRenew()) {
                return Result::fail('Contract does not have auto-renew enabled');
            }

            if (!$contract->getStatus()->isActive()) {
                return Result::fail(
                    sprintf(
                        'Contract is not active (status: %s)',
                        $contract->getStatus()->toString()
                    )
                );
            }

            // 3. Calculate billing cycle number
            $billingCycleNumber = $this->calculateBillingCycle($contractId);

            // 4. Check if payment already exists (idempotency)
            $existingPayment = $this->paymentRepository->findByContractAndCycle(
                $contractId,
                $billingCycleNumber
            );

            if ($existingPayment !== null) {
                // Payment already exists
                if ($existingPayment->getStatus()->isFailed() && $existingPayment->canRetry()) {
                    // If failed and can retry, continue to retry logic
                    return $this->retryPayment($existingPayment);
                }

                // Payment exists and is not retryable
                return Result::fail(
                    sprintf(
                        'Payment already exists for cycle %d (status: %s)',
                        $billingCycleNumber,
                        $existingPayment->getStatus()->getValue()
                    )
                );
            }

            // 5. Create RecurringPayment aggregate
            $dueDate = $contract->getEndDate() ?? new \DateTimeImmutable();

            $payment = RecurringPayment::create(
                $contractId,
                $billingCycleNumber,
                $contract->getMonthlyValue(),
                $dueDate
            );

            // 6. Get payment method from contract/customer
            $paymentMethodData = $this->getPaymentMethodData($contract);

            // 7. Call MercadoPago API to create charge
            $mpResponse = $this->paymentProvider->createPaymentCharge(
                $payment,
                $paymentMethodData
            );

            // 8. Update payment with gateway response
            if ($this->isSuccessResponse($mpResponse)) {
                $payment->markAsProcessing($mpResponse['id']);
            } else {
                // Payment creation failed immediately
                $failureReason = $this->paymentProvider->getFailureReason(
                    $mpResponse['status_detail'] ?? 'unknown'
                );
                $payment->markAsFailed($failureReason);
            }

            // 9. Save payment
            $this->paymentRepository->save($payment);

            // 10. Return result
            return Result::ok([
                'payment_uuid' => $payment->getPaymentUuid(),
                'gateway_transaction_id' => $payment->getGatewayTransactionId(),
                'status' => $payment->getStatus()->getValue(),
                'mp_status' => $mpResponse['status'] ?? null,
            ]);

        } catch (\Exception $e) {
            return Result::fail(
                sprintf(
                    'Failed to charge recurring payment: %s',
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Retry failed payment
     *
     * @param RecurringPayment $payment
     * @return Result
     */
    private function retryPayment(RecurringPayment $payment): Result
    {
        try {
            // Increment attempt count
            $payment->incrementAttempt();

            // Get contract for payment method
            $contract = $this->contractRepository->findById(
                ContractId::fromInt($payment->getContractId())
            );

            $paymentMethodData = $this->getPaymentMethodData($contract);

            // Retry MercadoPago charge
            $mpResponse = $this->paymentProvider->createPaymentCharge(
                $payment,
                $paymentMethodData
            );

            // Update status based on response
            if ($this->isSuccessResponse($mpResponse)) {
                $payment->markAsProcessing($mpResponse['id']);
            } else {
                $failureReason = $this->paymentProvider->getFailureReason(
                    $mpResponse['status_detail'] ?? 'unknown'
                );
                $payment->markAsFailed($failureReason);
            }

            // Save updated payment
            $this->paymentRepository->save($payment);

            return Result::ok([
                'payment_uuid' => $payment->getPaymentUuid(),
                'gateway_transaction_id' => $payment->getGatewayTransactionId(),
                'status' => $payment->getStatus()->getValue(),
                'attempt_count' => $payment->getAttemptCount(),
            ]);

        } catch (\Exception $e) {
            return Result::fail(
                sprintf('Failed to retry payment: %s', $e->getMessage())
            );
        }
    }

    /**
     * Calculate billing cycle number for contract
     * Cycle 1 = first payment, Cycle 2 = first renewal, etc.
     *
     * @param int $contractId
     * @return int
     */
    private function calculateBillingCycle(int $contractId): int
    {
        $existingPayments = $this->paymentRepository->findByContract($contractId);
        return count($existingPayments) + 1;
    }

    /**
     * Get payment method data from contract/customer
     *
     * @param \LimpVix\Domain\Contract\Contract $contract
     * @return array
     */
    private function getPaymentMethodData($contract): array
    {
        // Get customer user data
        $customer = get_userdata($contract->getClientUserId());

        // TODO: Implement proper payment method storage in Contract aggregate
        // For now, use PIX as default (generates QR code)
        return [
            'method_id' => 'pix', // PIX, credit_card, boleto
            'payer_email' => $customer->user_email ?? '',
            'payer_name' => $customer->display_name ?? '',
            'doc_type' => 'CPF',
            'doc_number' => get_user_meta($contract->getClientUserId(), 'billing_cpf', true) ?: '',
        ];
    }

    /**
     * Check if MercadoPago response indicates success
     *
     * @param array $response
     * @return bool
     */
    private function isSuccessResponse(array $response): bool
    {
        $status = $response['status'] ?? '';

        // Pending and approved are both success (pending for PIX/boleto)
        return in_array($status, ['pending', 'approved', 'in_process', 'authorized'], true);
    }
}
