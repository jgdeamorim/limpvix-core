<?php
/**
 * FeedbackAdapter - Adaptador para Eventos de Feedback
 *
 * RESPONSABILIDADE:
 * - Capturar hook de feedback de cliente
 * - Extrair rating e dados do cliente
 * - Traduzir para Command interno
 * - Chamar Use Case apropriado
 *
 * PRINCÍPIOS:
 * - Adapter Pattern
 * - Zero regras de negócio
 * - Zero decisões financeiras
 * - Apenas tradução: evento externo → comando interno
 *
 * IMPORTANTE:
 * - NÃO decide se rating é suficiente (Policy decide)
 * - NÃO valida se pode autorizar (Use Case + Policy validam)
 * - NÃO acessa ledger diretamente
 * - Apenas: hook → dados → use case
 *
 * HOOK:
 * - limpvix_customer_feedback_submitted
 * - Disparado quando cliente submete avaliação
 *
 * PASSO 5.4 - Adaptadores de Eventos
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\ProcessFeedbackReceived;

defined('ABSPATH') || exit;

class FeedbackAdapter
{
    /**
     * Use Case
     *
     * @var ProcessFeedbackReceived
     */
    private $useCase;

    /**
     * Construtor
     *
     * @param ProcessFeedbackReceived $useCase
     */
    public function __construct(ProcessFeedbackReceived $useCase)
    {
        $this->useCase = $useCase;
    }

    /**
     * Registrar hooks
     *
     * @return void
     */
    public function register(): void
    {
        // Hook customizado para feedback
        add_action('limpvix_customer_feedback_submitted', [$this, 'handleFeedbackSubmitted'], 10, 3);
    }

    /**
     * Handler: limpvix_customer_feedback_submitted
     *
     * @param string $orderUuid UUID da order
     * @param int $rating Rating (1-5)
     * @param int|null $customerId ID do cliente
     * @return void
     */
    public function handleFeedbackSubmitted(string $orderUuid, int $rating, ?int $customerId = null): void
    {
        try {
            // Validação básica de rating (1-5)
            if ($rating < 1 || $rating > 5) {
                $this->logWarning("Rating inválido: {$rating} para order {$orderUuid}");
                return;
            }

            // Executar Use Case
            $result = $this->useCase->execute($orderUuid, $rating, $customerId);

            // Log do resultado
            if ($result->isSuccess()) {
                $this->logSuccess($orderUuid, $rating, $result);
            } else {
                $this->logRejection($orderUuid, $rating, $result);
            }

        } catch (\Exception $e) {
            $this->logError($orderUuid, $rating, $e);
        }
    }

    /**
     * Log de sucesso
     *
     * @param string $orderUuid
     * @param int $rating
     * @param \LimpVix\Application\Results\TransitionFinancialStatusResult $result
     * @return void
     */
    private function logSuccess(string $orderUuid, int $rating, $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_feedback_processed', [
            'order_uuid' => $orderUuid,
            'rating' => $rating,
            'from_status' => $result->getFromStatus()->getValue(),
            'to_status' => $result->getToStatus()->getValue(),
            'ledger_uuid' => $result->getLedgerUuid()
        ]);
    }

    /**
     * Log de rejeição
     *
     * @param string $orderUuid
     * @param int $rating
     * @param \LimpVix\Application\Results\TransitionFinancialStatusResult $result
     * @return void
     */
    private function logRejection(string $orderUuid, int $rating, $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_feedback_rejected', [
            'order_uuid' => $orderUuid,
            'rating' => $rating,
            'reason' => $result->getRejectReason()
        ]);
    }

    /**
     * Log de warning
     *
     * @param string $message
     * @return void
     */
    private function logWarning(string $message): void
    {
        if (function_exists('do_action')) {
            do_action('limpvix_adapter_warning', [
                'adapter' => 'FeedbackAdapter',
                'message' => $message
            ]);
        }
    }

    /**
     * Log de erro
     *
     * @param string $orderUuid
     * @param int $rating
     * @param \Exception $exception
     * @return void
     */
    private function logError(string $orderUuid, int $rating, \Exception $exception): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_adapter_error', [
            'adapter' => 'FeedbackAdapter',
            'order_uuid' => $orderUuid,
            'rating' => $rating,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
