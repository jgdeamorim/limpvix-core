<?php
/**
 * MessageSentEvent - Domain Event
 *
 * Disparado quando mensagem é enviada com sucesso
 *
 * LISTENERS ESPERADOS:
 * - MessageLogRecorder (registra no log)
 * - MetricsCollector (métricas de comunicação)
 * - AuditLogger (auditoria)
 *
 * @package LimpVix\Domain\Communication
 * @since 0.3.0
 */

namespace LimpVix\Domain\Communication;

defined('ABSPATH') || exit;

class MessageSentEvent
{
    private $messageId;
    private $templateId;
    private $templateVersion;
    private $recipient;
    private $channel;
    private $eventId;
    private $eventType;
    private $occurredAt;

    public function __construct(
        string $messageId,
        string $templateId,
        string $templateVersion,
        string $recipient,
        string $channel,
        ?string $eventId = null,
        ?string $eventType = null
    ) {
        $this->messageId = $messageId;
        $this->templateId = $templateId;
        $this->templateVersion = $templateVersion;
        $this->recipient = $recipient;
        $this->channel = $channel;
        $this->eventId = $eventId;
        $this->eventType = $eventType;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getMessageId(): string { return $this->messageId; }
    public function getTemplateId(): string { return $this->templateId; }
    public function getTemplateVersion(): string { return $this->templateVersion; }
    public function getRecipient(): string { return $this->recipient; }
    public function getChannel(): string { return $this->channel; }
    public function getEventId(): ?string { return $this->eventId; }
    public function getEventType(): ?string { return $this->eventType; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    public function toArray(): array
    {
        return [
            'event_name' => 'message_sent',
            'message_id' => $this->messageId,
            'template_id' => $this->templateId,
            'template_version' => $this->templateVersion,
            'recipient' => $this->recipient,
            'channel' => $this->channel,
            'source_event_id' => $this->eventId,
            'source_event_type' => $this->eventType,
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:sP'),
        ];
    }
}
