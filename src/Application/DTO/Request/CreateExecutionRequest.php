<?php
/**
 * CreateExecutionRequest - DTO for creating executions
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class CreateExecutionRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly int $contract_id,
        public readonly int $professional_user_id,
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

        if ($this->contract_id <= 0) {
            $errors[] = 'contract_id: Must be positive';
        }

        if ($this->professional_user_id <= 0) {
            $errors[] = 'professional_user_id: Must be positive';
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
            contract_id: (int) ($data['contract_id'] ?? 0),
            professional_user_id: (int) ($data['professional_user_id'] ?? 0),
            scheduled_date: (string) ($data['scheduled_date'] ?? ''),
        );
    }

    public function getScheduledDateImmutable(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->scheduled_date);
    }
}
