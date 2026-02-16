<?php
/**
 * ContractStatus - Value Object para status de contrato
 *
 * RESPONSABILIDADE:
 * - Garantir status válidos
 * - Garantir transições válidas
 * - Prevenir estados inválidos
 *
 * INVARIANTES:
 * - Status só pode ser um dos valores definidos
 * - Transições seguem fluxo específico
 * - Cancelled é terminal (não volta)
 *
 * @package LimpVix\Domain\Contract\ValueObjects
 * @since 0.7.0
 */

namespace LimpVix\Domain\Contract\ValueObjects;

defined('ABSPATH') || exit;

class ContractStatus
{
    // Estados possíveis
    public const DRAFT = 'draft';
    public const PENDING_ALLOCATION = 'pending_allocation';
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    /**
     * Transições permitidas
     *
     * Regras:
     * - draft → pending_allocation, cancelled
     * - pending_allocation → active, cancelled
     * - active → paused, completed, cancelled
     * - paused → active, cancelled
     * - completed → [terminal]
     * - cancelled → [terminal]
     */
    private const ALLOWED_TRANSITIONS = [
        self::DRAFT => [self::PENDING_ALLOCATION, self::CANCELLED],
        self::PENDING_ALLOCATION => [self::ACTIVE, self::CANCELLED],
        self::ACTIVE => [self::PAUSED, self::COMPLETED, self::CANCELLED],
        self::PAUSED => [self::ACTIVE, self::CANCELLED],
        self::COMPLETED => [], // Terminal
        self::CANCELLED => [], // Terminal
    ];

    private string $value;

    private function __construct(string $value)
    {
        $this->ensureValid($value);
        $this->value = $value;
    }

    public static function draft(): self
    {
        return new self(self::DRAFT);
    }

    public static function pendingAllocation(): self
    {
        return new self(self::PENDING_ALLOCATION);
    }

    public static function active(): self
    {
        return new self(self::ACTIVE);
    }

    public static function paused(): self
    {
        return new self(self::PAUSED);
    }

    public static function completed(): self
    {
        return new self(self::COMPLETED);
    }

    public static function cancelled(): self
    {
        return new self(self::CANCELLED);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    private function ensureValid(string $value): void
    {
        $validStatuses = [
            self::DRAFT,
            self::PENDING_ALLOCATION,
            self::ACTIVE,
            self::PAUSED,
            self::COMPLETED,
            self::CANCELLED,
        ];

        if (!in_array($value, $validStatuses, true)) {
            throw new \InvalidArgumentException(
                "Invalid contract status: {$value}. Must be one of: " . implode(', ', $validStatuses)
            );
        }
    }

    /**
     * Verifica se pode transicionar para outro status
     *
     * @param ContractStatus $newStatus
     * @return bool
     */
    public function canTransitionTo(ContractStatus $newStatus): bool
    {
        $allowedTargets = self::ALLOWED_TRANSITIONS[$this->value] ?? [];
        return in_array($newStatus->value, $allowedTargets, true);
    }

    /**
     * Valida transição (throw exception se inválida)
     *
     * @param ContractStatus $newStatus
     * @throws \DomainException
     * @return void
     */
    public function ensureCanTransitionTo(ContractStatus $newStatus): void
    {
        if (!$this->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Invalid contract status transition: {$this->value} → {$newStatus->value}"
            );
        }
    }

    public function isDraft(): bool
    {
        return $this->value === self::DRAFT;
    }

    public function isPendingAllocation(): bool
    {
        return $this->value === self::PENDING_ALLOCATION;
    }

    public function isActive(): bool
    {
        return $this->value === self::ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->value === self::PAUSED;
    }

    public function isCompleted(): bool
    {
        return $this->value === self::COMPLETED;
    }

    public function isCancelled(): bool
    {
        return $this->value === self::CANCELLED;
    }

    public function isTerminal(): bool
    {
        return $this->isCompleted() || $this->isCancelled();
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(ContractStatus $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
