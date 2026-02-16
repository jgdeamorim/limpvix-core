<?php
/**
 * CommunicationEventDispatcher - Infrastructure
 *
 * RESPONSABILIDADE:
 * - Despachar eventos de domínio de Communication
 * - Converter eventos de domínio em WordPress hooks
 * - Integrar com sistema de eventos do WordPress
 *
 * @package LimpVix\Infrastructure\Events
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Events;

use LimpVix\Domain\Communication\MessageSentEvent;
use LimpVix\Domain\Communication\MessageFailedEvent;

defined('ABSPATH') || exit;

class CommunicationEventDispatcher
{
    /**
     * Despachar evento de domínio
     *
     * @param object $event Evento de domínio
     * @return void
     */
    public function dispatch(object $event): void
    {
        // Determinar tipo do evento
        $eventType = $this->getEventType($event);

        if (!$eventType) {
            error_log('[LimpVix] Unknown event type: ' . get_class($event));
            return;
        }

        // Converter evento para array
        $eventData = $this->eventToArray($event);

        // Despachar como WordPress hook
        $hookName = 'limpvix_communication_' . $eventType;

        do_action($hookName, $eventData);

        // Log para debug
        error_log(sprintf(
            '[LimpVix] Event dispatched: %s | Data: %s',
            $hookName,
            json_encode($eventData, JSON_UNESCAPED_UNICODE)
        ));
    }

    /**
     * Obter tipo do evento
     *
     * @param object $event Evento
     * @return string|null
     */
    private function getEventType(object $event): ?string
    {
        $className = get_class($event);

        $typeMap = [
            MessageSentEvent::class => 'message_sent',
            MessageFailedEvent::class => 'message_failed',
        ];

        return $typeMap[$className] ?? null;
    }

    /**
     * Converter evento para array
     *
     * @param object $event Evento
     * @return array
     */
    private function eventToArray(object $event): array
    {
        if ($event instanceof MessageSentEvent) {
            return [
                'message_id' => $event->getMessageId(),
                'template_id' => $event->getTemplateId(),
                'template_version' => $event->getTemplateVersion(),
                'recipient' => $event->getRecipient(),
                'channel' => $event->getChannel(),
                'event_id' => $event->getEventId(),
                'event_type' => $event->getEventType(),
                'sent_at' => $event->getOccurredAt()->format('Y-m-d H:i:s'),
            ];
        }

        if ($event instanceof MessageFailedEvent) {
            return [
                'message_id' => $event->getMessageId(),
                'template_id' => $event->getTemplateId(),
                'recipient' => $event->getRecipient(),
                'channel' => $event->getChannel(),
                'failure_reason' => $event->getFailureReason(),
                'retry_count' => $event->getRetryCount(),
                'can_retry' => $event->canRetry(),
                'failed_at' => $event->getOccurredAt()->format('Y-m-d H:i:s'),
            ];
        }

        return [];
    }
}
