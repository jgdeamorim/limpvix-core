<?php
/**
 * ActivateContractRequest - DTO for activating contracts
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class ActivateContractRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly int $contract_id,
        public readonly int $professional_id,
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

        if ($this->professional_id <= 0) {
            $errors[] = 'professional_id: Must be positive';
        }

        return $errors;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            contract_id: (int) ($data['contract_id'] ?? $data['id'] ?? 0),
            professional_id: (int) ($data['professional_id'] ?? 0),
        );
    }
}
