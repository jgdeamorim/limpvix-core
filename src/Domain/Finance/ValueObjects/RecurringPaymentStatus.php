<?php
/**
 * RecurringPaymentStatus - Value Object para Status de Pagamento Recorrente
 *
 * RESPONSABILIDADE:
 * - Representar estados válidos de um pagamento recorrente
 * - Garantir type safety com enum
 * - Facilitar validações de transição de estado
 *
 * ESTADOS:
 * - pending: Pagamento criado, aguardando processamento
 * - processing: Enviado ao MercadoPago, aguardando confirmação
 * - completed: Pagamento aprovado e confirmado
 * - failed: Pagamento rejeitado ou falhou
 * - cancelled: Pagamento cancelado manualmente
 *
 * STATE MACHINE:
 * pending → processing → completed
 *         ↘         ↘ failed
 * Any state → cancelled (manual intervention)
 *
 * @package LimpVix\Domain\Finance\ValueObjects
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Domain\Finance\ValueObjects;

defined('ABSPATH') || exit;

final class RecurringPaymentStatus
{
    private const PENDING = 'pending';
    private const PROCESSING = 'processing';
    private const COMPLETED = 'completed';
    private const FAILED = 'failed';
    private const CANCELLED = 'cancelled';

    private string $value;

    /**
     * Private constructor - use static factory methods
     */
    private function __construct(string $value)
    {
        $this->ensureIsValid($value);
        $this->value = $value;
    }

    /**
     * Static factory: Create pending status
     */
    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    /**
     * Static factory: Create processing status
     */
    public static function processing(): self
    {
        return new self(self::PROCESSING);
    }

    /**
     * Static factory: Create completed status
     */
    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    /**
     * Static factory: Create failed status
     */
    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    /**
     * Static factory: Create cancelled status
     */
    public static function cancelled(): self
    {
        return new self(self::CANCELLED);
    }

    /**
     * Static factory: Create from string
     *
     * @throws \InvalidArgumentException if invalid status
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Get string value
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Check if status is pending
     */
    public function isPending(): bool
    {
        return $this->value === self::PENDING;
    }

    /**
     * Check if status is processing
     */
    public function isProcessing(): bool
    {
        return $this->value === self::PROCESSING;
    }

    /**
     * Check if status is completed
     */
    public function isCompleted(): bool
    {
        return $this->value === self::COMPLETED;
    }

    /**
     * Check if status is failed
     */
    public function isFailed(): bool
    {
        return $this->value === self::FAILED;
    }

    /**
     * Check if status is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->value === self::CANCELLED;
    }

    /**
     * Check if status allows retry
     * Only failed payments can be retried
     */
    public function canRetry(): bool
    {
        return $this->isFailed();
    }

    /**
     * Check if status is terminal (completed or cancelled)
     */
    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isCancelled();
    }

    /**
     * Validate if transition to new status is allowed
     *
     * @param RecurringPaymentStatus $newStatus
     * @return bool
     */
    public function canTransitionTo(RecurringPaymentStatus $newStatus): bool
    {
        // Completed and cancelled are terminal states
        if ($this->isTerminal()) {
            return false;
        }

        // Pending can go to processing or cancelled
        if ($this->isPending()) {
            return $newStatus->isProcessing() || $newStatus->isCancelled();
        }

        // Processing can go to completed, failed, or cancelled
        if ($this->isProcessing()) {
            return $newStatus->isCompleted()
                || $newStatus->isFailed()
                || $newStatus->isCancelled();
        }

        // Failed can go to processing (retry) or cancelled
        if ($this->isFailed()) {
            return $newStatus->isProcessing() || $newStatus->isCancelled();
        }

        return false;
    }

    /**
     * Ensure value is valid status
     *
     * @throws \InvalidArgumentException
     */
    private function ensureIsValid(string $value): void
    {
        $validStatuses = [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];

        if (!in_array($value, $validStatuses, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid RecurringPaymentStatus "%s". Valid values: %s',
                    $value,
                    implode(', ', $validStatuses)
                )
            );
        }
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Equality check
     */
    public function equals(RecurringPaymentStatus $other): bool
    {
        return $this->value === $other->value;
    }
}
