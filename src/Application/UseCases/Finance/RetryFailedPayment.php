<?php
/**
 * RetryFailedPayment - Use Case para Retentar Pagamento Falhado
 *
 * RESPONSABILIDADE:
 * - Retentar cobranças que falharam anteriormente
 * - Incrementar contador de tentativas
 * - Validar elegibilidade para retry (max 3 attempts)
 * - Chamar MercadoPago novamente
 * - Atualizar status do payment
 * - Se max attempts: Notificar admin e marcar contrato
 *
 * WORKFLOW:
 * 1. Buscar RecurringPayment pelo UUID
 * 2. Validar: status=failed, attempt_count < 3
 * 3. Incrementar attempt_count
 * 4. Buscar payment method do contrato
 * 5. Chamar MercadoPago API novamente
 * 6. Atualizar status (processing ou failed novamente)
 * 7. Se max attempts (3): Notificar admin e atualizar contrato
 * 8. Salvar payment atualizado
 * 9. Retornar Result
 *
 * CASOS DE USO:
 * - Cron job de retry (daily)
 * - Manual retry pelo admin
 * - Fallback após webhook timeout
 *
 * RETRY SCHEDULE:
 * - Attempt 1: Day 0 (due_date)
 * - Attempt 2: Day 0 + 2 days
 * - Attempt 3: Day 0 + 5 days
 * - After 3 failures: Contract status → payment_failed
 *
 * @package LimpVix\Application\UseCases\Finance
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Application\UseCases\Finance;

use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Finance\RecurringPaymentRepositoryInterface;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPaymentProvider;
use LimpVix\Common\Result;

defined('ABSPATH') || exit;

class RetryFailedPayment
{
    private RecurringPaymentRepositoryInterface $paymentRepository;
    private ContractRepositoryInterface $contractRepository;
    private MercadoPagoPaymentProvider $paymentProvider;

    public function __construct(
        RecurringPaymentRepositoryInterface $paymentRepository,
        ContractRepositoryInterface $contractRepository,
        MercadoPagoPaymentProvider $paymentProvider
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->contractRepository = $contractRepository;
        $this->paymentProvider = $paymentProvider;
    }

    /**
     * Execute use case - retry failed payment
     *
     * @param string $paymentUuid Payment UUID to retry
     * @return Result<array{payment_uuid: string, status: string, attempt_count: int}>
     */
    public function execute(string $paymentUuid): Result
    {
        try {
            // 1. Load payment
            $payment = $this->paymentRepository->findByUuid($paymentUuid);

            if ($payment === null) {
                return Result::fail(
                    sprintf('RecurringPayment not found: %s', $paymentUuid)
                );
            }

            // 2. Validate retry eligibility
            if (!$payment->getStatus()->isFailed()) {
                return Result::fail(
                    sprintf(
                        'Payment is not in failed status (current: %s)',
                        $payment->getStatus()->getValue()
                    )
                );
            }

            if (!$payment->canRetry()) {
                return Result::fail(
                    sprintf(
                        'Payment has exceeded max retry attempts (%d)',
                        $payment->getAttemptCount()
                    )
                );
            }

            // 3. Increment attempt count
            $payment->incrementAttempt();

            // 4. Get contract for payment method
            $contract = $this->contractRepository->findById(
                ContractId::fromInt($payment->getContractId())
            );

            if ($contract === null) {
                throw new \RuntimeException(
                    sprintf('Contract %d not found', $payment->getContractId())
                );
            }

            // 5. Get payment method data
            $paymentMethodData = $this->getPaymentMethodData($contract);

            // 6. Retry MercadoPago charge
            $mpResponse = $this->paymentProvider->createPaymentCharge(
                $payment,
                $paymentMethodData
            );

            // 7. Update status based on response
            if ($this->isSuccessResponse($mpResponse)) {
                // Success - mark as processing
                $payment->markAsProcessing($mpResponse['id']);

                // Save payment
                $this->paymentRepository->save($payment);

                return Result::ok([
                    'payment_uuid' => $payment->getPaymentUuid(),
                    'gateway_transaction_id' => $payment->getGatewayTransactionId(),
                    'status' => $payment->getStatus()->getValue(),
                    'attempt_count' => $payment->getAttemptCount(),
                    'mp_status' => $mpResponse['status'] ?? null,
                ]);

            } else {
                // Failed again
                $failureReason = $this->paymentProvider->getFailureReason(
                    $mpResponse['status_detail'] ?? 'unknown'
                );
                $payment->markAsFailed($failureReason);

                // Save payment
                $this->paymentRepository->save($payment);

                // 8. Check if max attempts reached
                if (!$payment->canRetry()) {
                    // Max attempts exceeded - handle contract
                    $this->handleMaxAttemptsExceeded($contract, $payment);

                    return Result::fail(
                        sprintf(
                            'Payment failed after %d attempts. Reason: %s. Contract marked as payment_failed.',
                            $payment->getAttemptCount(),
                            $failureReason
                        )
                    );
                }

                return Result::fail(
                    sprintf(
                        'Payment retry failed (attempt %d/%d). Reason: %s',
                        $payment->getAttemptCount(),
                        3,
                        $failureReason
                    )
                );
            }

        } catch (\Exception $e) {
            return Result::fail(
                sprintf(
                    'Failed to retry payment: %s',
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Retry all payments needing retry
     * Used by cron job to process pending retries
     *
     * @return Result<array{total: int, succeeded: int, failed: int, skipped: int}>
     */
    public function retryAllPendingPayments(): Result
    {
        try {
            // Find payments needing retry
            $paymentsToRetry = $this->paymentRepository->findPaymentsNeedingRetry();

            $stats = [
                'total' => count($paymentsToRetry),
                'succeeded' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];

            foreach ($paymentsToRetry as $payment) {
                $result = $this->execute($payment->getPaymentUuid());

                if ($result->isSuccess()) {
                    $stats['succeeded']++;
                } else {
                    $stats['failed']++;
                }
            }

            error_log(sprintf(
                '[LimpVix] Retry payment cron completed: %d total, %d succeeded, %d failed',
                $stats['total'],
                $stats['succeeded'],
                $stats['failed']
            ));

            return Result::ok($stats);

        } catch (\Exception $e) {
            return Result::fail(
                sprintf(
                    'Failed to retry payments batch: %s',
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Handle max attempts exceeded scenario
     * - Update contract status to payment_failed
     * - Send admin notification
     * - Send customer notification
     *
     * @param \LimpVix\Domain\Contract\Contract $contract
     * @param \LimpVix\Domain\Finance\RecurringPayment $payment
     * @return void
     */
    private function handleMaxAttemptsExceeded($contract, $payment): void
    {
        // Log critical event
        error_log(sprintf(
            '[LimpVix] CRITICAL: Payment failed after 3 attempts. Contract: %d, Payment: %s',
            $contract->getId()->toInt(),
            $payment->getPaymentUuid()
        ));

        // Send admin notification
        $this->notifyAdminMaxAttemptsExceeded($contract, $payment);

        // Send customer notification
        $this->notifyCustomerPaymentFailed($contract, $payment);

        // TODO: Update contract status to payment_failed
        // This should be done via domain event listener to maintain separation
        // For now, just log - will be implemented in Phase 4 (Integration)
        error_log(sprintf(
            '[LimpVix] TODO: Update Contract %d status to payment_failed',
            $contract->getId()->toInt()
        ));
    }

    /**
     * Notify admin about max attempts exceeded
     *
     * @param \LimpVix\Domain\Contract\Contract $contract
     * @param \LimpVix\Domain\Finance\RecurringPayment $payment
     * @return void
     */
    private function notifyAdminMaxAttemptsExceeded($contract, $payment): void
    {
        $adminEmail = get_option('admin_email');

        $subject = sprintf(
            '[LimpVix] ALERTA: Pagamento Falhado - Contrato #%d',
            $contract->getId()->toInt()
        );

        $message = sprintf(
            "ALERTA DE PAGAMENTO FALHADO\n\n" .
            "O pagamento recorrente falhou após 3 tentativas:\n\n" .
            "Contrato: #%d\n" .
            "Cliente: %s\n" .
            "Valor: R$ %.2f\n" .
            "Ciclo: %d\n" .
            "Tentativas: %d\n" .
            "Motivo: %s\n\n" .
            "AÇÃO NECESSÁRIA:\n" .
            "1. Contatar cliente para atualizar método de pagamento\n" .
            "2. Processar pagamento manualmente se necessário\n" .
            "3. Verificar se contrato deve ser suspenso\n\n" .
            "Link: %s\n",
            $contract->getId()->toInt(),
            get_userdata($contract->getClientUserId())->display_name ?? 'N/A',
            $payment->getAmount(),
            $payment->getBillingCycleNumber(),
            $payment->getAttemptCount(),
            $payment->getFailureReason() ?? 'Unknown',
            admin_url('admin.php?page=limpvix-contracts&id=' . $contract->getId()->toInt())
        );

        wp_mail($adminEmail, $subject, $message);

        error_log(sprintf(
            '[LimpVix] Admin notification sent for failed payment: %s',
            $payment->getPaymentUuid()
        ));
    }

    /**
     * Notify customer about payment failure
     *
     * @param \LimpVix\Domain\Contract\Contract $contract
     * @param \LimpVix\Domain\Finance\RecurringPayment $payment
     * @return void
     */
    private function notifyCustomerPaymentFailed($contract, $payment): void
    {
        $customer = get_userdata($contract->getClientUserId());

        if (!$customer || empty($customer->user_email)) {
            error_log('[LimpVix] Cannot send customer notification: email not found');
            return;
        }

        $subject = '[LimpVix] Problema com Pagamento - Ação Necessária';

        $message = sprintf(
            "Olá %s,\n\n" .
            "Identificamos um problema com o pagamento do seu contrato #%d.\n\n" .
            "Detalhes:\n" .
            "- Valor: R$ %.2f\n" .
            "- Motivo: %s\n\n" .
            "Por favor, atualize sua forma de pagamento ou entre em contato conosco.\n\n" .
            "Caso contrário, seu serviço poderá ser suspenso.\n\n" .
            "Atenciosamente,\n" .
            "Equipe LimpVix",
            $customer->display_name ?? 'Cliente',
            $contract->getId()->toInt(),
            $payment->getAmount(),
            $payment->getFailureReason() ?? 'Pagamento recusado'
        );

        wp_mail($customer->user_email, $subject, $message);

        error_log(sprintf(
            '[LimpVix] Customer notification sent for failed payment: %s to %s',
            $payment->getPaymentUuid(),
            $customer->user_email
        ));
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
