<?php
/**
 * ScheduleExecutionRequest - DTO for scheduling executions
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class ScheduleExecutionRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly int $execution_id,
        public readonly string $scheduled_date,
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

        $dateError = $this->validateDate($this->scheduled_date, 'scheduled_date');
        if ($dateError) {
            $errors[] = $dateError;
        }

        return $errors;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            execution_id: (int) ($data['execution_id'] ?? $data['id'] ?? 0),
            scheduled_date: (string) ($data['scheduled_date'] ?? ''),
        );
    }

    public function getScheduledDateImmutable(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->scheduled_date);
    }
}
