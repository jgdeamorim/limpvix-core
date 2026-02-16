<?php
/**
 * MessageFailedEvent - Domain Event
 *
 * Disparado quando mensagem falha no envio
 *
 * LISTENERS ESPERADOS:
 * - RetryScheduler (agenda retry se canRetry = true)
 * - AlertDispatcher (alerta admin se crítico)
 * - MessageLogRecorder (registra falha)
 *
 * @package LimpVix\Domain\Communication
 * @since 0.3.0
 */

namespace LimpVix\Domain\Communication;

defined('ABSPATH') || exit;

class MessageFailedEvent
{
    private $messageId;
    private $templateId;
    private $recipient;
    private $channel;
    private $failureReason;
    private $retryCount;
    private $canRetry;
    private $occurredAt;

    public function __construct(
        string $messageId,
        string $templateId,
        string $recipient,
        string $channel,
        string $failureReason,
        int $retryCount,
        bool $canRetry
    ) {
        $this->messageId = $messageId;
        $this->templateId = $templateId;
        $this->recipient = $recipient;
        $this->channel = $channel;
        $this->failureReason = $failureReason;
        $this->retryCount = $retryCount;
        $this->canRetry = $canRetry;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getMessageId(): string { return $this->messageId; }
    public function getTemplateId(): string { return $this->templateId; }
    public function getRecipient(): string { return $this->recipient; }
    public function getChannel(): string { return $this->channel; }
    public function getFailureReason(): string { return $this->failureReason; }
    public function getRetryCount(): int { return $this->retryCount; }
    public function canRetry(): bool { return $this->canRetry; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function toArray(): array
    {
        return [
            'event_name' => 'message_failed',
            'message_id' => $this->messageId,
            'template_id' => $this->templateId,
            'recipient' => $this->recipient,
            'channel' => $this->channel,
            'failure_reason' => $this->failureReason,
            'retry_count' => $this->retryCount,
            'can_retry' => $this->canRetry,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:sP'),
        ];
    }
}
