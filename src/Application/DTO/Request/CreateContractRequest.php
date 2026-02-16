<?php
/**
 * CreateContractRequest - DTO for creating new contracts
 *
 * RESPONSABILIDADE:
 * - Validar dados de entrada para criação de contrato
 * - Garantir tipos corretos e valores válidos
 * - Fornecer dados estruturados para CreateContract Use Case
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class CreateContractRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly int $client_user_id,
        public readonly string $contract_type,
        public readonly int $recurrence_day,
        public readonly string $service_code,
        public readonly string $property_type,
        public readonly float $monthly_value,
        public readonly string $start_date,
        public readonly ?string $end_date = null,
        public readonly bool $auto_renew = true,
        public readonly bool $auto_activate = false,
        public readonly ?int $professional_id = null,
    ) {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Validation failed: ' . implode(', ', $errors));
        }
    }

    public function validate(): array
    {
        $errors = [];

        // Validate client_user_id
        if ($this->client_user_id <= 0) {
            $errors[] = 'client_user_id: Must be positive';
        } elseif (!get_user_by('id', $this->client_user_id)) {
            $errors[] = 'client_user_id: User not found';
        }

        // Validate contract_type
        $typeError = $this->validateEnum(
            $this->contract_type,
            ['monthly', 'weekly', 'biweekly'],
            'contract_type'
        );
        if ($typeError) {
            $errors[] = $typeError;
        }

        // Validate recurrence_day
        if ($this->recurrence_day < 1 || $this->recurrence_day > 31) {
            $errors[] = 'recurrence_day: Must be between 1 and 31';
        }

        // Validate service_code
        if (empty($this->service_code)) {
            $errors[] = 'service_code: Cannot be empty';
        }

        // Validate property_type
        if (empty($this->property_type)) {
            $errors[] = 'property_type: Cannot be empty';
        }

        // Validate monthly_value
        $valueError = $this->validatePositive($this->monthly_value, 'monthly_value');
        if ($valueError) {
            $errors[] = $valueError;
        }

        // Validate start_date
        $dateError = $this->validateDate($this->start_date, 'start_date');
        if ($dateError) {
            $errors[] = $dateError;
        }

        // Validate end_date if provided
        if ($this->end_date !== null) {
            $endDateError = $this->validateDate($this->end_date, 'end_date');
            if ($endDateError) {
                $errors[] = $endDateError;
            }
        }

        // Validate auto_activate logic
        if ($this->auto_activate && !$this->professional_id) {
            $errors[] = 'professional_id: Required when auto_activate is true';
        }

        return $errors;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            client_user_id: (int) ($data['client_user_id'] ?? 0),
            contract_type: (string) ($data['contract_type'] ?? ''),
            recurrence_day: (int) ($data['recurrence_day'] ?? 0),
            service_code: (string) ($data['service_code'] ?? ''),
            property_type: (string) ($data['property_type'] ?? ''),
            monthly_value: (float) ($data['monthly_value'] ?? 0),
            start_date: (string) ($data['start_date'] ?? ''),
            end_date: $data['end_date'] ?? null,
            auto_renew: (bool) ($data['auto_renew'] ?? true),
            auto_activate: (bool) ($data['auto_activate'] ?? false),
            professional_id: isset($data['professional_id']) ? (int) $data['professional_id'] : null,
        );
    }

    /**
     * Convert DTO to array for Use Case execution
     *
     * @return array
     */
    public function toUseCaseParams(): array
    {
        return [
            'client_user_id' => $this->client_user_id,
            'contract_type' => $this->contract_type,
            'recurrence_day' => $this->recurrence_day,
            'service_code' => $this->service_code,
            'property_type' => $this->property_type,
            'monthly_value' => $this->monthly_value,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'auto_renew' => $this->auto_renew,
        ];
    }
}
