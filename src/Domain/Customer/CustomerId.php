<?php

declare(strict_types=1);

namespace LimpVix\Domain\Customer;

defined("ABSPATH") || exit;

/**
 * Customer ID Value Object
 * 
 * Representa o identificador único de um cliente.
 * Baseado no user_id do WordPress (wp_users.ID)
 */
final class CustomerId
{
    private int $id;

    private function __construct(int $id)
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException("Customer ID must be positive integer");
        }

        $this->id = $id;
    }

    public static function fromInt(int $id): self
    {
        return new self($id);
    }

    public function toInt(): int
    {
        return $this->id;
    }

    public function equals(CustomerId $other): bool
    {
        return $this->id === $other->id;
    }

    public function __toString(): string
    {
        return (string) $this->id;
    }
}
