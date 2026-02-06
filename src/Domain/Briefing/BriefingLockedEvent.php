<?php
/**
 * BriefingLockedEvent - Domain Event
 *
 * Disparado quando o Briefing transiciona para o estado final 'locked'.
 * Indica que o Briefing foi pago, confirmado e está pronto para execução.
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingLockedEvent
{
    /**
     * @var string UUID do Briefing
     */
    private $briefingUuid;

    /**
     * @var int Order ID vinculada (WooCommerce)
     */
    private $orderId;

    /**
     * @var int User ID
     */
    private $userId;

    /**
     * @var bool Requer contrato?
     */
    private $requiresContract;

    /**
     * @var \DateTimeImmutable Data do evento
     */
    private $occurredAt;

    /**
     * Construtor
     *
     * @param string $briefingUuid
     * @param int $orderId
     * @param int $userId
     * @param bool $requiresContract
     * @param \DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        string $briefingUuid,
        int $orderId,
        int $userId,
        bool $requiresContract,
        ?\DateTimeImmutable $occurredAt = null
    ) {
        $this->briefingUuid = $briefingUuid;
        $this->orderId = $orderId;
        $this->userId = $userId;
        $this->requiresContract = $requiresContract;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getBriefingUuid(): string
    {
        return $this->briefingUuid;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function requiresContract(): bool
    {
        return $this->requiresContract;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getEventType(): string
    {
        return 'briefing.locked';
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->getEventType(),
            'briefing_uuid' => $this->briefingUuid,
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
            'requires_contract' => $this->requiresContract,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s')
        ];
    }
}
