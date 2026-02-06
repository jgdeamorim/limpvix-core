<?php
/**
 * BriefingPhoneVerifiedEvent - Domain Event
 *
 * Disparado quando o telefone do cliente é verificado via Firebase OTP.
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingPhoneVerifiedEvent
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
     * @var string|null Firebase UID (opcional)
     */
    private $firebaseUid;

    /**
     * @var \DateTimeImmutable Data do evento
     */
    private $occurredAt;

    /**
     * Construtor
     *
     * @param string $briefingUuid
     * @param int $userId
     * @param string|null $firebaseUid
     * @param \DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        string $briefingUuid,
        int $userId,
        ?string $firebaseUid = null,
        ?\DateTimeImmutable $occurredAt = null
    ) {
        $this->briefingUuid = $briefingUuid;
        $this->userId = $userId;
        $this->firebaseUid = $firebaseUid;
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

    public function getFirebaseUid(): ?string
    {
        return $this->firebaseUid;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getEventType(): string
    {
        return 'briefing.phone_verified';
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->getEventType(),
            'briefing_uuid' => $this->briefingUuid,
            'user_id' => $this->userId,
            'firebase_uid' => $this->firebaseUid,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s')
        ];
    }
}
