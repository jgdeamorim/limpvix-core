<?php
/**
 * BriefingCreatedEvent - Domain Event
 *
 * Disparado quando um novo Briefing é criado (estado: draft).
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingCreatedEvent
{
    /**
     * @var string UUID do Briefing
     */
    private $briefingUuid;

    /**
     * @var int User ID
     */
    private $userId;

    /**
     * @var string Tipo de propriedade
     */
    private $propertyType;

    /**
     * @var \DateTimeImmutable Data do evento
     */
    private $occurredAt;

    /**
     * Construtor
     *
     * @param string $briefingUuid
     * @param int $userId
     * @param string $propertyType
     * @param \DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        string $briefingUuid,
        int $userId,
        string $propertyType,
        ?\DateTimeImmutable $occurredAt = null
    ) {
        $this->briefingUuid = $briefingUuid;
        $this->userId = $userId;
        $this->propertyType = $propertyType;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getBriefingUuid(): string
    {
        return $this->briefingUuid;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getPropertyType(): string
    {
        return $this->propertyType;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getEventType(): string
    {
        return 'briefing.created';
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->getEventType(),
            'briefing_uuid' => $this->briefingUuid,
            'user_id' => $this->userId,
            'property_type' => $this->propertyType,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s')
        ];
    }
}
