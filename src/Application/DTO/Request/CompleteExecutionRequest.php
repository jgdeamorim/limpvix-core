<?php
/**
 * CompleteExecutionRequest - DTO for completing executions
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class CompleteExecutionRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly int $execution_id,
        public readonly ?string $notes = null,
        public readonly array $photos = [],
    ) {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Validation failed: ' . implode(', ', $errors));
        }
    }

    public function validate(): array
    {
        $errors = [];

        if ($this->execution_id <= 0) {
            $errors[] = 'execution_id: Must be positive';
        }

        if (!is_array($this->photos)) {
            $errors[] = 'photos: Must be an array';
        }

        return $errors;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            execution_id: (int) ($data['execution_id'] ?? $data['id'] ?? 0),
            notes: $data['notes'] ?? null,
            photos: (array) ($data['photos'] ?? []),
        );
    }
}
