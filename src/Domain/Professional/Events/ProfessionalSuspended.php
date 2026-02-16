<?php
/**
 * ProfessionalSuspended - Domain Event
 *
 * Fired when a professional is suspended (temporarily or permanently).
 *
 * TRIGGERS:
 * - Admin manually suspends professional
 * - System auto-suspends due to critical issues
 *
 * LISTENERS:
 * - OnProfessionalSuspended: Reallocate all active contracts
 * - NotificationService: Notify professional and clients
 * - AuditLogger: Log suspension for compliance
 *
 * SUSPENSION TYPES:
 * - Temporary: suspendedUntil has date (can return after)
 * - Permanent: suspendedUntil is null (banned)
 *
 * @package LimpVix\Domain\Professional\Events
 * @since 0.9.0
 */

namespace LimpVix\Domain\Professional\Events;

defined('ABSPATH') || exit;

final class ProfessionalSuspended
{
    public function __construct(
        public readonly int $professionalId,
        public readonly string $reason,
        public readonly ?\DateTimeImmutable $suspendedUntil,
        public readonly ?int $suspendedBy,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable()
    ) {}

    /**
     * Get event name for WordPress action
     *
     * @return string
     */
    public function getEventName(): string
    {
        return 'professional.suspended';
    }

    /**
     * Get event payload for WordPress action
     *
     * @return array
     */
    public function getPayload(): array
    {
        return [
            'professional_id' => $this->professionalId,
            'reason' => $this->reason,
            'suspended_until' => $this->suspendedUntil?->format('Y-m-d H:i:s'),
            'suspended_by' => $this->suspendedBy,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'is_permanent' => $this->suspendedUntil === null,
        ];
    }

    /**
     * Check if suspension is permanent
     *
     * @return bool
     */
    public function isPermanent(): bool
    {
        return $this->suspendedUntil === null;
    }

    /**
     * Check if suspension is temporary
     *
     * @return bool
     */
    public function isTemporary(): bool
    {
        return $this->suspendedUntil !== null;
    }

    /**
     * String representation
     *
     * @return string
     */
    public function __toString(): string
    {
        if ($this->isPermanent()) {
            return sprintf(
                'Professional #%d permanently suspended: %s',
                $this->professionalId,
                $this->reason
            );
        }

        return sprintf(
            'Professional #%d suspended until %s: %s',
            $this->professionalId,
            $this->suspendedUntil->format('Y-m-d H:i'),
            $this->reason
        );
    }
}
