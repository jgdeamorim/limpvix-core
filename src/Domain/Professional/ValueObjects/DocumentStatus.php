<?php

declare(strict_types=1);

namespace LimpVix\Domain\Professional\ValueObjects;

/**
 * Document Status Value Object
 *
 * Representa os estados possíveis de um documento durante o processo de revisão
 */
final class DocumentStatus
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';

    private const VALID_STATUSES = [
        self::PENDING,
        self::APPROVED,
        self::REJECTED,
        self::EXPIRED,
    ];

    private const STATUS_LABELS = [
        self::PENDING => 'Aguardando Revisão',
        self::APPROVED => 'Aprovado',
        self::REJECTED => 'Rejeitado',
        self::EXPIRED => 'Expirado',
    ];

    private const STATUS_COLORS = [
        self::PENDING => 'warning',
        self::APPROVED => 'success',
        self::REJECTED => 'danger',
        self::EXPIRED => 'secondary',
    ];

    private string $value;

    private function __construct(string $value)
    {
        if (!in_array($value, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid document status: {$value}. Valid statuses: " . implode(', ', self::VALID_STATUSES)
            );
        }

        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function approved(): self
    {
        return new self(self::APPROVED);
    }

    public static function rejected(): self
    {
        return new self(self::REJECTED);
    }

    public static function expired(): self
    {
        return new self(self::EXPIRED);
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return self::STATUS_LABELS[$this->value] ?? $this->value;
    }

    public function getColor(): string
    {
        return self::STATUS_COLORS[$this->value] ?? 'secondary';
    }

    public function isPending(): bool
    {
        return $this->value === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->value === self::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->value === self::REJECTED;
    }

    public function isExpired(): bool
    {
        return $this->value === self::EXPIRED;
    }

    public function canTransitionTo(DocumentStatus $newStatus): bool
    {
        // Pending pode ir para qualquer estado
        if ($this->isPending()) {
            return true;
        }

        // Rejected pode voltar para pending (re-upload)
        if ($this->isRejected() && $newStatus->isPending()) {
            return true;
        }

        // Approved pode expirar
        if ($this->isApproved() && $newStatus->isExpired()) {
            return true;
        }

        // Expired pode voltar para pending (renovação)
        if ($this->isExpired() && $newStatus->isPending()) {
            return true;
        }

        return false;
    }

    public function equals(DocumentStatus $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function all(): array
    {
        return self::VALID_STATUSES;
    }

    public static function allWithLabels(): array
    {
        return self::STATUS_LABELS;
    }
}
