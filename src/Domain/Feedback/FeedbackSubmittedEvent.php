<?php
/**
 * FeedbackSubmittedEvent - Domain Event
 *
 * RESPONSABILIDADE:
 * - Notificar que feedback estruturado foi submetido
 * - Disparar side effects:
 *   - Notificar profissional
 *   - Atualizar rating médio
 *   - Liberar payout (se positivo)
 *
 * @package LimpVix\Domain\Feedback
 * @since 0.3.0
 */

namespace LimpVix\Domain\Feedback;

defined('ABSPATH') || exit;

class FeedbackSubmittedEvent
{
    private string $feedbackUuid;
    private string $orderUuid;
    private int $customerId;
    private float $finalScore;
    private bool $isPositive;
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        string $feedbackUuid,
        string $orderUuid,
        int $customerId,
        float $finalScore,
        bool $isPositive
    ) {
        $this->feedbackUuid = $feedbackUuid;
        $this->orderUuid = $orderUuid;
        $this->customerId = $customerId;
        $this->finalScore = $finalScore;
        $this->isPositive = $isPositive;
        $this->occurredAt = new \DateTimeImmutable();
    }

    // Getters
    public function getFeedbackUuid(): string { return $this->feedbackUuid; }
    public function getOrderUuid(): string { return $this->orderUuid; }
    public function getCustomerId(): int { return $this->customerId; }
    public function getFinalScore(): float { return $this->finalScore; }
    public function isPositive(): bool { return $this->isPositive; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function toArray(): array
    {
        return [
            'feedback_uuid' => $this->feedbackUuid,
            'order_uuid' => $this->orderUuid,
            'customer_id' => $this->customerId,
            'final_score' => $this->finalScore,
            'is_positive' => $this->isPositive,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
