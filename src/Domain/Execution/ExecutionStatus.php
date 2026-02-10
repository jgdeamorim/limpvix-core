<?php
/**
 * ExecutionStatus - Value Object para status de execução
 *
 * RESPONSABILIDADE:
 * - Representar estados possíveis de uma execução
 * - Validar transições de estado
 * - Encapsular regras de negócio de mudança de status
 *
 * ESTADOS:
 * - draft: Execução criada mas não agendada
 * - scheduled: Execução agendada para data específica
 * - in_progress: Profissional iniciou o serviço
 * - completed: Serviço finalizado com sucesso
 * - cancelled: Execução cancelada
 * - no_show: Profissional não compareceu
 *
 * TRANSIÇÕES VÁLIDAS:
 * - draft → scheduled
 * - scheduled → in_progress
 * - scheduled → no_show
 * - scheduled → cancelled
 * - in_progress → completed
 * - in_progress → cancelled
 * - * → cancelled (qualquer estado pode ser cancelado)
 *
 * @package LimpVix\Domain\Execution
 * @since 0.9.0
 */

namespace LimpVix\Domain\Execution;

use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransition;

defined('ABSPATH') || exit;

final class ExecutionStatus
{
    private const DRAFT = 'draft';
    private const SCHEDULED = 'scheduled';
    private const IN_PROGRESS = 'in_progress';
    private const COMPLETED = 'completed';
    private const CANCELLED = 'cancelled';
    private const NO_SHOW = 'no_show';

    private string $value;

    private function __construct(string $value)
    {
        $this->ensureValidStatus($value);
        $this->value = $value;
    }

    // ============================================
    // Factory Methods
    // ============================================

    public static function draft(): self
    {
        return new self(self::DRAFT);
    }

    public static function scheduled(): self
    {
        return new self(self::SCHEDULED);
    }

    public static function inProgress(): self
    {
        return new self(self::IN_PROGRESS);
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    public static function cancelled(): self
    {
        return new self(self::CANCELLED);
    }

    public static function noShow(): self
    {
        return new self(self::NO_SHOW);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    // ============================================
    // State Check Methods
    // ============================================

    public function isDraft(): bool
    {
        return $this->value === self::DRAFT;
    }

    public function isScheduled(): bool
    {
        return $this->value === self::SCHEDULED;
    }

    public function isInProgress(): bool
    {
        return $this->value === self::IN_PROGRESS;
    }

    public function isCompleted(): bool
    {
        return $this->value === self::COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->value === self::CANCELLED;
    }

    public function isNoShow(): bool
    {
        return $this->value === self::NO_SHOW;
    }

    /**
     * Verificar se execução está em estado final (não pode mais mudar)
     *
     * @return bool
     */
    public function isFinalState(): bool
    {
        return in_array($this->value, [
            self::COMPLETED,
            self::CANCELLED,
            self::NO_SHOW,
        ], true);
    }

    // ============================================
    // Transition Validation
    // ============================================

    /**
     * Validar que transição para novo status é permitida
     *
     * TRANSIÇÕES VÁLIDAS:
     * - draft → scheduled
     * - scheduled → in_progress | no_show | cancelled
     * - in_progress → completed | cancelled
     * - * → cancelled (sempre permitido)
     *
     * @param ExecutionStatus $newStatus
     * @return void
     * @throws InvalidExecutionTransition
     */
    public function ensureCanTransitionTo(ExecutionStatus $newStatus): void
    {
        // Transição para o mesmo estado (noop)
        if ($this->equals($newStatus)) {
            return;
        }

        // Estados finais não podem transicionar
        if ($this->isFinalState()) {
            throw InvalidExecutionTransition::fromTo($this->value, $newStatus->value);
        }

        // Cancelamento sempre permitido (de qualquer estado não-final)
        if ($newStatus->isCancelled()) {
            return;
        }

        // Validar transições específicas
        $validTransitions = $this->getValidTransitions();

        if (!in_array($newStatus->value, $validTransitions, true)) {
            throw InvalidExecutionTransition::fromTo($this->value, $newStatus->value);
        }
    }

    /**
     * Obter transições válidas para o estado atual
     *
     * @return array
     */
    private function getValidTransitions(): array
    {
        return match ($this->value) {
            self::DRAFT => [self::SCHEDULED, self::CANCELLED],
            self::SCHEDULED => [self::IN_PROGRESS, self::NO_SHOW, self::CANCELLED],
            self::IN_PROGRESS => [self::COMPLETED, self::CANCELLED],
            default => [], // Estados finais não têm transições
        };
    }

    // ============================================
    // Value Comparison
    // ============================================

    public function equals(ExecutionStatus $other): bool
    {
        return $this->value === $other->value;
    }

    // ============================================
    // Conversion
    // ============================================

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    // ============================================
    // Validation
    // ============================================

    /**
     * Validar que status é válido
     *
     * @param string $value
     * @return void
     * @throws \InvalidArgumentException
     */
    private function ensureValidStatus(string $value): void
    {
        $validStatuses = [
            self::DRAFT,
            self::SCHEDULED,
            self::IN_PROGRESS,
            self::COMPLETED,
            self::CANCELLED,
            self::NO_SHOW,
        ];

        if (!in_array($value, $validStatuses, true)) {
            throw new \InvalidArgumentException(
                "Invalid execution status: {$value}. Valid statuses: " . implode(', ', $validStatuses)
            );
        }
    }
}
