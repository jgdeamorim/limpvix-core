<?php
/**
 * ReallocationReason - Value Object
 *
 * Defines valid reasons for professional reallocation with typed constants.
 *
 * Ensures consistency in reallocation reason tracking across the system.
 *
 * VALID REASONS:
 * - low_score: Professional score fell below threshold
 * - client_request: Client requested different professional
 * - professional_request: Professional requested to be deallocated
 * - unavailable: Professional became unavailable
 * - performance_issue: Performance issues reported
 * - other: Other reason (requires notes)
 *
 * @package LimpVix\Domain\Contract\ValueObjects
 * @since 0.9.0
 */

namespace LimpVix\Domain\Contract\ValueObjects;

defined('ABSPATH') || exit;

final class ReallocationReason
{
    private const VALID_REASONS = [
        'low_score' => 'Professional score fell below threshold',
        'client_request' => 'Client requested different professional',
        'professional_request' => 'Professional requested to be deallocated',
        'unavailable' => 'Professional became unavailable',
        'performance_issue' => 'Performance issues reported',
        'no_show' => 'Professional had no-show incident',
        'other' => 'Other reason (see notes)',
    ];

    private function __construct(
        private readonly string $value,
        private readonly ?string $notes = null
    ) {
        if (!array_key_exists($value, self::VALID_REASONS)) {
            throw new \InvalidArgumentException("Invalid reallocation reason: {$value}");
        }

        if ($value === 'other' && empty($notes)) {
            throw new \InvalidArgumentException(
                "Notes are required when reason is 'other'"
            );
        }
    }

    // ==================== FACTORY METHODS ====================

    public static function lowScore(?string $notes = null): self
    {
        return new self('low_score', $notes);
    }

    public static function clientRequest(?string $notes = null): self
    {
        return new self('client_request', $notes);
    }

    public static function professionalRequest(?string $notes = null): self
    {
        return new self('professional_request', $notes);
    }

    public static function unavailable(?string $notes = null): self
    {
        return new self('unavailable', $notes);
    }

    public static function performanceIssue(?string $notes = null): self
    {
        return new self('performance_issue', $notes);
    }

    public static function noShow(?string $notes = null): self
    {
        return new self('no_show', $notes);
    }

    public static function other(string $notes): self
    {
        return new self('other', $notes);
    }

    /**
     * Create from string (e.g., from API or database)
     *
     * @param string $value Reason code
     * @param string|null $notes Optional notes
     * @return self
     */
    public static function fromString(string $value, ?string $notes = null): self
    {
        return new self($value, $notes);
    }

    // ==================== GETTERS ====================

    public function getValue(): string
    {
        return $this->value;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getDescription(): string
    {
        return self::VALID_REASONS[$this->value];
    }

    /**
     * Get full description including notes
     *
     * @return string
     */
    public function getFullDescription(): string
    {
        $base = $this->getDescription();
        return $this->notes ? "{$base}: {$this->notes}" : $base;
    }

    /**
     * Convert to string representation
     *
     * Format: "reason_code: Full description with notes"
     *
     * @return string
     */
    public function toString(): string
    {
        return "{$this->value}: {$this->getFullDescription()}";
    }

    /**
     * Check if reason is specific type
     *
     * @param string $type Reason type to check
     * @return bool
     */
    public function is(string $type): bool
    {
        return $this->value === $type;
    }

    /**
     * Check if reason is client-initiated
     *
     * @return bool
     */
    public function isClientInitiated(): bool
    {
        return $this->value === 'client_request';
    }

    /**
     * Check if reason is professional-initiated
     *
     * @return bool
     */
    public function isProfessionalInitiated(): bool
    {
        return $this->value === 'professional_request';
    }

    /**
     * Check if reason is system-initiated
     *
     * @return bool
     */
    public function isSystemInitiated(): bool
    {
        return in_array($this->value, ['low_score', 'unavailable', 'no_show'], true);
    }

    // ==================== EQUALITY ====================

    public function equals(self $other): bool
    {
        return $this->value === $other->value
            && $this->notes === $other->notes;
    }

    // ==================== SERIALIZATION ====================

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'description' => $this->getDescription(),
            'notes' => $this->notes,
            'full_description' => $this->getFullDescription(),
        ];
    }
}
