<?php
/**
 * RescheduleExecutionRequest - DTO for rescheduling executions
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class RescheduleExecutionRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly int $execution_id,
        public readonly string $new_scheduled_date,
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

        $dateError = $this->validateDate($this->new_scheduled_date, 'new_scheduled_date');
        if ($dateError) {
            $errors[] = $dateError;
        }

        return $errors;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            execution_id: (int) ($data['execution_id'] ?? $data['id'] ?? 0),
            new_scheduled_date: (string) ($data['new_scheduled_date'] ?? $data['scheduled_date'] ?? ''),
        );
    }

    public function getNewScheduledDateImmutable(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->new_scheduled_date);
    }
}
