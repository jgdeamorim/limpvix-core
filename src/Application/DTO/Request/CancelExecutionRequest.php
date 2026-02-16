<?php
/**
 * CancelExecutionRequest - DTO for cancelling executions
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class CancelExecutionRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly int $execution_id,
        public readonly ?string $reason = null,
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

        return $errors;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            execution_id: (int) ($data['execution_id'] ?? $data['id'] ?? 0),
            reason: $data['reason'] ?? null,
        );
    }
}
