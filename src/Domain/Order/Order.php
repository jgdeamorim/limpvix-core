<?php
declare(strict_types=1);

/**
 * Order - Aggregate Root com State Machine (Sprint 0 - Dia 2)
 *
 * RESPONSABILIDADE:
 * - Representar ciclo de vida completo de uma Order
 * - Garantir transições válidas via State Machine
 * - Aggregation Root com invariantes protegidas
 *
 * PRINCÍPIOS:
 * - Entity (tem identidade - UUID)
 * - State Machine formal (OrderStatusEnum)
 * - Transições explícitas e validadas
 * - Estados terminais imutáveis
 *
 * BREAKING CHANGE (Sprint 0):
 * - setStatus() REMOVIDO
 * - Status agora é OrderStatusEnum (não string)
 * - Transições via métodos explícitos apenas
 *
 * @package LimpVix\Domain\Order
 */

namespace LimpVix\Domain\Order;

use LimpVix\Domain\Order\Enums\OrderStatusEnum;
use LimpVix\Domain\Order\Exceptions\InvalidOrderTransitionException;
use LimpVix\Domain\Finance\FinancialStatus;

defined('ABSPATH') || exit;

class Order
{
    private string $uuid;
    private int $id;
    private OrderStatusEnum $status;
    private FinancialStatus $financialStatus;
    private float $totalAmount;
    private float $platformFeePercentage;
    private float $platformFeeAmount;
    private float $professionalNetAmount;

    public function __construct(
        string $uuid,
        int $id,
        OrderStatusEnum $status,
        FinancialStatus $financialStatus,
        float $totalAmount = 0.0,
        float $platformFeePercentage = 0.0,
        float $platformFeeAmount = 0.0,
        float $professionalNetAmount = 0.0
    ) {
        $this->uuid = $uuid;
        $this->id = $id;
        $this->status = $status;
        $this->financialStatus = $financialStatus;
        $this->totalAmount = $totalAmount;
        $this->platformFeePercentage = $platformFeePercentage;
        $this->platformFeeAmount = $platformFeeAmount;
        $this->professionalNetAmount = $professionalNetAmount;
    }

    /**
     * Factory: Criar Order em estado inicial
     */
    public static function create(
        string $uuid,
        int $id,
        FinancialStatus $financialStatus,
        float $totalAmount = 0.0,
        float $platformFeePercentage = 0.0,
        float $platformFeeAmount = 0.0,
        float $professionalNetAmount = 0.0
    ): self {
        return new self(
            $uuid,
            $id,
            OrderStatusEnum::CREATED,
            $financialStatus,
            $totalAmount,
            $platformFeePercentage,
            $platformFeeAmount,
            $professionalNetAmount
        );
    }

    // ========================================
    // STATE MACHINE - MÉTODOS DE TRANSIÇÃO
    // ========================================

    /**
     * Confirmar Order (payment captured)
     */
    public function confirm(): void
    {
        $this->guardTransition(OrderStatusEnum::CONFIRMED);
        $this->status = OrderStatusEnum::CONFIRMED;
    }

    /**
     * Agendar Order (briefing locked)
     */
    public function schedule(): void
    {
        $this->guardTransition(OrderStatusEnum::SCHEDULED);
        $this->status = OrderStatusEnum::SCHEDULED;
    }

    /**
     * Iniciar execução (check-in performed)
     */
    public function startExecution(): void
    {
        $this->guardTransition(OrderStatusEnum::IN_EXECUTION);
        $this->status = OrderStatusEnum::IN_EXECUTION;
    }

    /**
     * Completar Order (check-out performed)
     */
    public function complete(): void
    {
        $this->guardTransition(OrderStatusEnum::COMPLETED);
        $this->status = OrderStatusEnum::COMPLETED;
    }

    /**
     * Fechar Order (feedback submitted)
     */
    public function close(): void
    {
        $this->guardTransition(OrderStatusEnum::CLOSED);
        $this->status = OrderStatusEnum::CLOSED;
    }

    /**
     * Cancelar Order (before execution)
     */
    public function cancel(): void
    {
        $this->guardTransition(OrderStatusEnum::CANCELLED);
        $this->status = OrderStatusEnum::CANCELLED;
    }

    /**
     * Disputar Order (customer initiated)
     */
    public function dispute(): void
    {
        $this->guardTransition(OrderStatusEnum::DISPUTED);
        $this->status = OrderStatusEnum::DISPUTED;
    }

    /**
     * Resolver disputa (admin resolved)
     */
    public function resolveDispute(): void
    {
        if ($this->status !== OrderStatusEnum::DISPUTED) {
            throw InvalidOrderTransitionException::forbidden(
                $this->status,
                OrderStatusEnum::COMPLETED,
                'Can only resolve from DISPUTED status'
            );
        }
        $this->status = OrderStatusEnum::COMPLETED;
    }

    // ========================================
    // GUARD TRANSITION (STATE MACHINE CORE)
    // ========================================

    /**
     * Valida se transição é permitida
     */
    private function guardTransition(OrderStatusEnum $to): void
    {
        $from = $this->status;

        // Estados terminais não permitem transições
        if ($from->isTerminal()) {
            throw InvalidOrderTransitionException::terminalState($from);
        }

        // Validar transições permitidas
        $allowed = $this->getAllowedTransitions();

        if (!in_array($to, $allowed, true)) {
            throw InvalidOrderTransitionException::forbidden(
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
            OrderStatusEnum::CREATED => [
                OrderStatusEnum::CONFIRMED,
                OrderStatusEnum::CANCELLED,
            ],
            OrderStatusEnum::CONFIRMED => [
                OrderStatusEnum::SCHEDULED,
                OrderStatusEnum::CANCELLED,
            ],
            OrderStatusEnum::SCHEDULED => [
                OrderStatusEnum::IN_EXECUTION,
                OrderStatusEnum::CANCELLED,
            ],
            OrderStatusEnum::IN_EXECUTION => [
                OrderStatusEnum::COMPLETED,
                OrderStatusEnum::DISPUTED,
            ],
            OrderStatusEnum::COMPLETED => [
                OrderStatusEnum::CLOSED,
            ],
            OrderStatusEnum::DISPUTED => [
                OrderStatusEnum::COMPLETED,
            ],
            OrderStatusEnum::CLOSED => [],
            OrderStatusEnum::CANCELLED => [],
        };
    }

    // ========================================
    // GETTERS
    // ========================================

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getStatus(): OrderStatusEnum
    {
        return $this->status;
    }

    public function getFinancialStatus(): FinancialStatus
    {
        return $this->financialStatus;
    }

    public function getTotalAmount(): float
    {
        return $this->totalAmount;
    }

    public function getPlatformFeePercentage(): float
    {
        return $this->platformFeePercentage;
    }

    public function getPlatformFeeAmount(): float
    {
        return $this->platformFeeAmount;
    }

    public function getProfessionalNetAmount(): float
    {
        return $this->professionalNetAmount;
    }

    public function equals(Order $other): bool
    {
        return $this->uuid === $other->uuid;
    }
}
