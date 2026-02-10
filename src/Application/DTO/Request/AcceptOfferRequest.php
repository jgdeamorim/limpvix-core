<?php
/**
 * AcceptOfferRequest - DTO for accepting offers
 *
 * @package LimpVix\Application\DTO\Request
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Request;

defined('ABSPATH') || exit;

final class AcceptOfferRequest extends BaseRequestDTO
{
    public function __construct(
        public readonly int $professional_id,
        public readonly int $offer_id,
    ) {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException('Validation failed: ' . implode(', ', $errors));
        }
    }

    public function validate(): array
    {
        $errors = [];

        if ($this->professional_id <= 0) {
            $errors[] = 'professional_id: Must be positive';
        }

        if ($this->offer_id <= 0) {
            $errors[] = 'offer_id: Must be positive';
        }

        return $errors;
    }

    public static function fromArray(array $data): static
    {
        return new self(
            professional_id: (int) ($data['professional_id'] ?? $data['id'] ?? 0),
            offer_id: (int) ($data['offer_id'] ?? 0),
        );
    }
}
