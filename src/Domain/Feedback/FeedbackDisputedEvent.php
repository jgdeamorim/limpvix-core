<?php
/**
 * FeedbackDisputedEvent - Domain Event
 *
 * RESPONSABILIDADE:
 * - Notificar que feedback foi contestado por profissional
 * - Disparar arbitragem manual
 *
 * @package LimpVix\Domain\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Domain\Feedback;

defined('ABSPATH') || exit;

class FeedbackDisputedEvent
{
    private string $feedbackUuid;
    private string $orderUuid;
    private int $professionalId;
    private string $disputeReason;
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        string $feedbackUuid,
        string $orderUuid,
        int $professionalId,
        string $disputeReason
    ) {
        $this->feedbackUuid = $feedbackUuid;
        $this->orderUuid = $orderUuid;
        $this->professionalId = $professionalId;
        $this->disputeReason = $disputeReason;
        $this->occurredAt = new \DateTimeImmutable();
    }

    // Getters
    public function getFeedbackUuid(): string { return $this->feedbackUuid; }
    public function getOrderUuid(): string { return $this->orderUuid; }
    public function getProfessionalId(): int { return $this->professionalId; }
    public function getDisputeReason(): string { return $this->disputeReason; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function toArray(): array
    {
        return [
            'feedback_uuid' => $this->feedbackUuid,
            'order_uuid' => $this->orderUuid,
            'professional_id' => $this->professionalId,
            'dispute_reason' => $this->disputeReason,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
