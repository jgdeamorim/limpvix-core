<?php
/**
 * FeedbackEventDispatcher - Infrastructure
 *
 * RESPONSABILIDADE:
 * - Despachar eventos de domínio de Feedback
 * - Converter eventos de domínio em WordPress hooks
 *
 * @package LimpVix\Infrastructure\Events
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Events;

use LimpVix\Domain\Feedback\FeedbackSubmittedEvent;
use LimpVix\Domain\Feedback\FeedbackDisputedEvent;

defined('ABSPATH') || exit;

class FeedbackEventDispatcher
{
    /**
     * Despachar evento de domínio
     *
     * @param object $event Evento de domínio
     * @return void
     */
    public function dispatch(object $event): void
    {
        $eventType = $this->getEventType($event);

        if (!$eventType) {
            error_log('[LimpVix] Unknown feedback event type: ' . get_class($event));
            return;
        }

        $eventData = $this->eventToArray($event);
        $hookName = 'limpvix_feedback_' . $eventType;

        do_action($hookName, $eventData);

        error_log(sprintf(
            '[LimpVix] Feedback event dispatched: %s | Data: %s',
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
            FeedbackSubmittedEvent::class => 'submitted',
            FeedbackDisputedEvent::class => 'disputed',
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
        if ($event instanceof FeedbackSubmittedEvent) {
            return [
                'feedback_uuid' => $event->getFeedbackUuid(),
                'order_uuid' => $event->getOrderUuid(),
                'customer_id' => $event->getCustomerId(),
                'final_score' => $event->getFinalScore(),
                'is_positive' => $event->isPositive(),
                'occurred_at' => $event->getOccurredAt()->format('Y-m-d H:i:s'),
            ];
        }

        if ($event instanceof FeedbackDisputedEvent) {
            return [
                'feedback_uuid' => $event->getFeedbackUuid(),
                'order_uuid' => $event->getOrderUuid(),
                'professional_id' => $event->getProfessionalId(),
                'dispute_reason' => $event->getDisputeReason(),
                'occurred_at' => $event->getOccurredAt()->format('Y-m-d H:i:s'),
            ];
        }

        return [];
    }
}
