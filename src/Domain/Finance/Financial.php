<?php
declare(strict_types=1);

/**
 * Financial - Aggregate Root com State Machine (Sprint 0 - Dia 3)
 *
 * RESPONSABILIDADE:
 * - Representar ciclo de vida financeiro de uma Order
 * - Garantir transições válidas via State Machine
 * - Aggregation Root com invariantes protegidas
 *
 * PRINCÍPIOS:
 * - Entity (tem identidade - UUID da Order)
 * - State Machine formal (FinancialStatusEnum)
 * - Transições explícitas e validadas
 * - Estados terminais imutáveis
 *
 * BREAKING CHANGE (Sprint 0):
 * - setStatus() REMOVIDO
 * - Status agora é FinancialStatusEnum (não string)
 * - Transições via métodos explícitos apenas
 *
 * REGRA CRÍTICA (DIA 3):
 * ❌ captured → payout_authorized BLOQUEADO sem Order::COMPLETED
 *
 * @package LimpVix\Domain\Finance
 */

namespace LimpVix\Domain\Finance;

use LimpVix\Domain\Finance\Enums\FinancialStatusEnum;
use LimpVix\Domain\Finance\Exceptions\InvalidFinancialTransitionException;
use LimpVix\Domain\Order\Enums\OrderStatusEnum;

defined('ABSPATH') || exit;

class Financial
{
    private string $orderUuid;
    private FinancialStatusEnum $status;
    private float $amount;
    private float $platformFee;
    private float $professionalPayout;
    private ?OrderStatusEnum $orderStatus;

    public function __construct(
        string $orderUuid,
        FinancialStatusEnum $status,
        float $amount = 0.0,
        float $platformFee = 0.0,
        float $professionalPayout = 0.0,
        ?OrderStatusEnum $orderStatus = null
    ) {
        $this->orderUuid = $orderUuid;
        $this->status = $status;
        $this->amount = $amount;
        $this->platformFee = $platformFee;
        $this->professionalPayout = $professionalPayout;
        $this->orderStatus = $orderStatus;
    }

    /**
     * Factory: Criar Financial em estado inicial
     */
    public static function create(
        string $orderUuid,
        float $amount,
        float $platformFee,
        float $professionalPayout
    ): self {
        return new self(
            $orderUuid,
            FinancialStatusEnum::PENDING,
            $amount,
            $platformFee,
            $professionalPayout
        );
    }

    // ========================================
    // STATE MACHINE - MÉTODOS DE TRANSIÇÃO
    // ========================================

    /**
     * Autorizar pagamento (payment gateway approved)
     */
    public function authorize(): void
    {
        $this->guardTransition(FinancialStatusEnum::AUTHORIZED);
        $this->status = FinancialStatusEnum::AUTHORIZED;
    }

    /**
     * Capturar pagamento (funds captured from customer)
     */
    public function capture(): void
    {
        $this->guardTransition(FinancialStatusEnum::CAPTURED);
        $this->status = FinancialStatusEnum::CAPTURED;
    }

    /**
     * Segurar pagamento (hold until check-in)
     */
    public function hold(): void
    {
        $this->guardTransition(FinancialStatusEnum::HELD);
        $this->status = FinancialStatusEnum::HELD;
    }

    /**
     * Autorizar payout (service completed, ready to pay professional)
     *
     * REGRA CRÍTICA: Só permite se Order::COMPLETED
     */
    public function authorizePayout(): void
    {
        // Validação crítica: Order DEVE estar COMPLETED
        if ($this->orderStatus !== OrderStatusEnum::COMPLETED) {
            throw InvalidFinancialTransitionException::forbidden(
                $this->status,
                FinancialStatusEnum::PAYOUT_AUTHORIZED,
                'Cannot authorize payout: Order must be COMPLETED'
            );
        }

        $this->guardTransition(FinancialStatusEnum::PAYOUT_AUTHORIZED);
        $this->status = FinancialStatusEnum::PAYOUT_AUTHORIZED;
    }

    /**
     * Completar payout (funds transferred to professional)
     */
    public function completePayout(): void
    {
        $this->guardTransition(FinancialStatusEnum::PAYOUT_COMPLETED);
        $this->status = FinancialStatusEnum::PAYOUT_COMPLETED;
    }

    /**
     * Reembolsar pagamento (refund to customer)
     */
    public function refund(): void
    {
        $this->guardTransition(FinancialStatusEnum::REFUNDED);
        $this->status = FinancialStatusEnum::REFUNDED;
    }

    /**
     * Marcar como falho (payment failed)
     */
    public function markAsFailed(): void
    {
        $this->guardTransition(FinancialStatusEnum::FAILED);
        $this->status = FinancialStatusEnum::FAILED;
    }

    /**
     * Atualizar status da Order (para validações de payout)
     */
    public function updateOrderStatus(OrderStatusEnum $orderStatus): void
    {
        $this->orderStatus = $orderStatus;
    }

    // ========================================
    // GUARD TRANSITION (STATE MACHINE CORE)
    // ========================================

    /**
     * Valida se transição é permitida
     */
    private function guardTransition(FinancialStatusEnum $to): void
    {
        $from = $this->status;

        // Estados terminais não permitem transições
        if ($from->isTerminal()) {
            throw InvalidFinancialTransitionException::terminalState($from);
        }

        // Validar transições permitidas
        $allowed = $this->getAllowedTransitions();

        if (!in_array($to, $allowed, true)) {
            throw InvalidFinancialTransitionException::forbidden(
                $from,
                $to,
                'Transition not allowed by State Machine rules'
            );
        }
    }

    /**
     * Retorna transições permitidas a partir do estado atual
     */
    private function getAllowedTransitions(): array
    {
        return match ($this->status) {
            FinancialStatusEnum::PENDING => [
                FinancialStatusEnum::AUTHORIZED,
                FinancialStatusEnum::FAILED,
            ],
            FinancialStatusEnum::AUTHORIZED => [
                FinancialStatusEnum::CAPTURED,
                FinancialStatusEnum::REFUNDED,
                FinancialStatusEnum::FAILED,
            ],
            FinancialStatusEnum::CAPTURED => [
                FinancialStatusEnum::HELD,
                FinancialStatusEnum::REFUNDED,
            ],
            FinancialStatusEnum::HELD => [
                FinancialStatusEnum::PAYOUT_AUTHORIZED,
                FinancialStatusEnum::REFUNDED,
            ],
            FinancialStatusEnum::PAYOUT_AUTHORIZED => [
                FinancialStatusEnum::PAYOUT_COMPLETED,
            ],
            FinancialStatusEnum::RELEASED => [
                FinancialStatusEnum::PAYOUT_AUTHORIZED,
            ],
            FinancialStatusEnum::PAYOUT_COMPLETED => [],
            FinancialStatusEnum::REFUNDED => [],
            FinancialStatusEnum::FAILED => [],
        };
    }

    // ========================================
    // GETTERS
    // ========================================

    public function getOrderUuid(): string
    {
        return $this->orderUuid;
    }

    public function getStatus(): FinancialStatusEnum
    {
        return $this->status;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getPlatformFee(): float
    {
        return $this->platformFee;
    }

    public function getProfessionalPayout(): float
    {
        return $this->professionalPayout;
    }

    public function getOrderStatus(): ?OrderStatusEnum
    {
        return $this->orderStatus;
    }

    public function equals(Financial $other): bool
    {
        return $this->orderUuid === $other->orderUuid;
    }
}
