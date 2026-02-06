<?php
/**
 * BriefingStepCompletedEvent - Domain Event
 *
 * Disparado quando um step do Briefing é completado.
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingStepCompletedEvent
{
    /**
     * @var string UUID do Briefing
     */
    private $briefingUuid;

    /**
     * @var string Nome do step ('structure', 'frequency', etc)
     */
    private $stepName;

    /**
     * @var string Status atual
     */
    private $currentStatus;

    /**
     * @var \DateTimeImmutable Data do evento
     */
    private $occurredAt;

    /**
     * Construtor
     *
     * @param string $briefingUuid
     * @param string $stepName
     * @param string $currentStatus
     * @param \DateTimeImmutable|null $occurredAt
     */
    public function __construct(
        string $briefingUuid,
        string $stepName,
        string $currentStatus,
        ?\DateTimeImmutable $occurredAt = null
    ) {
        $this->briefingUuid = $briefingUuid;
        $this->stepName = $stepName;
        $this->currentStatus = $currentStatus;
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function getBriefingUuid(): string
    {
        return $this->briefingUuid;
    }

    public function getStepName(): string
    {
        return $this->stepName;
    }

    public function getCurrentStatus(): string
    {
        return $this->currentStatus;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getEventType(): string
    {
        return 'briefing.step_completed';
    }

    public function toArray(): array
    {
        return [
            'event_type' => $this->getEventType(),
            'briefing_uuid' => $this->briefingUuid,
            'step_name' => $this->stepName,
            'current_status' => $this->currentStatus,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s')
        ];
    }
}
