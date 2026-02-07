<?php
/**
 * BriefingEventDispatcher - Dispatcher de Eventos de Domínio
 *
 * RESPONSABILIDADE:
 * - Despachar Domain Events via WordPress hooks (do_action)
 * - Converter eventos de domínio em hooks WordPress
 * - Permitir que outros módulos escutem eventos do Briefing
 *
 * EVENTOS DISPARADOS:
 * - limpvix_briefing_created
 * - limpvix_briefing_step_completed
 * - limpvix_briefing_phone_verified
 * - limpvix_briefing_awaiting_payment
 * - limpvix_briefing_paid
 * - limpvix_briefing_locked
 *
 * @package LimpVix\Infrastructure\Events
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Events;

defined('ABSPATH') || exit;

class BriefingEventDispatcher
{
    /**
     * Disparar evento de domínio
     *
     * Converte Domain Event → WordPress hook.
     *
     * @param object $event Domain Event (BriefingCreatedEvent, etc)
     * @return void
     */
    public function dispatch($event): void
    {
        if (!is_object($event)) {
            return;
        }

        // Obter nome do hook baseado no event type
        $hookName = $this->getHookName($event);

        if (empty($hookName)) {
            return;
        }

        // Serializar evento para array
        $eventData = method_exists($event, 'toArray') ? $event->toArray() : (array) $event;

        // Disparar hook WordPress
        do_action($hookName, $eventData);

        // Log (debug)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix] Evento disparado: %s (UUID: %s)',
                $hookName,
                $eventData['briefing_uuid'] ?? 'unknown'
            ));
        }
    }

    /**
     * Obter nome do hook WordPress baseado no evento
     *
     * @param object $event
     * @return string Hook name (ex: limpvix_briefing_created)
     */
    private function getHookName($event): string
    {
        $eventClass = get_class($event);
        $className = substr(strrchr($eventClass, '\\'), 1);

        // Mapeamento de eventos → hooks
        $mapping = [
            'BriefingCreatedEvent' => 'limpvix_briefing_created',
            'BriefingStepCompletedEvent' => 'limpvix_briefing_step_completed',
            'BriefingPhoneVerifiedEvent' => 'limpvix_briefing_phone_verified',
            'BriefingAwaitingPaymentEvent' => 'limpvix_briefing_awaiting_payment',
            'BriefingPaidEvent' => 'limpvix_briefing_paid',
            'BriefingLockedEvent' => 'limpvix_briefing_locked'
        ];

        return $mapping[$className] ?? '';
    }

    /**
     * Registrar listener para evento
     *
     * Wrapper para add_action com namespace correto.
     *
     * @param string $eventType Tipo do evento (ex: 'locked')
     * @param callable $callback Callback a executar
     * @param int $priority Prioridade (padrão 10)
     * @return void
     */
    public static function listen(string $eventType, callable $callback, int $priority = 10): void
    {
        $hookName = 'limpvix_briefing_' . $eventType;
        add_action($hookName, $callback, $priority, 1);
    }
}
