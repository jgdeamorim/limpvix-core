<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Events;

use LimpVix\Domain\Scheduling\Events\SchedulingEvent;

/**
 * Event Dispatcher: Scheduling
 *
 * Despacha eventos de scheduling para:
 * - WordPress hooks (do_action)
 * - Ledger (auditoria)
 * - Integrações com outros módulos
 */
final class SchedulingEventDispatcher
{
    private \wpdb $wpdb;
    private string $ledgerTable;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->ledgerTable = $wpdb->prefix . 'limpvix_scheduling_ledger';
    }

    /**
     * Despacha evento de scheduling
     *
     * @param SchedulingEvent $event
     */
    public function dispatch(SchedulingEvent $event): void
    {
        $eventType = $this->getEventType($event);
        $eventData = $event->toArray();

        // 1. Disparar WordPress hook
        $hookName = 'limpvix_scheduling_' . $eventType;
        do_action($hookName, $eventData);

        // 2. Registrar no ledger (append-only)
        $this->appendToLedger($event, $eventType, $eventData);

        // 3. Log debug (se WP_DEBUG ativo)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Scheduling] Event dispatched: %s (Schedule: %s)',
                $eventType,
                $eventData['schedule_uuid'] ?? 'N/A'
            ));
        }
    }

    /**
     * Despacha múltiplos eventos em batch
     *
     * @param array $events Array de SchedulingEvent
     */
    public function dispatchBatch(array $events): void
    {
        foreach ($events as $event) {
            if ($event instanceof SchedulingEvent) {
                $this->dispatch($event);
            }
        }
    }

    /**
     * Registra evento no ledger (auditoria)
     *
     * @param SchedulingEvent $event
     * @param string $eventType
     * @param array $eventData
     */
    private function appendToLedger(SchedulingEvent $event, string $eventType, array $eventData): void
    {
        $ledgerUuid = wp_generate_uuid4();
        $scheduleUuid = $eventData['schedule_uuid'] ?? null;

        if (!$scheduleUuid) {
            return; // Sem schedule UUID, não registrar
        }

        $data = [
            'ledger_uuid' => $ledgerUuid,
            'schedule_uuid' => $scheduleUuid,
            'event_type' => $eventType,
            'from_status' => $eventData['from_status'] ?? null,
            'to_status' => $eventData['to_status'] ?? null,
            'actor' => $this->getActor(),
            'actor_id' => $this->getActorId(),
            'event_data' => json_encode($eventData),
            'occurred_at' => current_time('mysql'),
        ];

        $this->wpdb->insert(
            $this->ledgerTable,
            $data,
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Extrai tipo do evento (snake_case)
     *
     * @param SchedulingEvent $event
     * @return string
     */
    private function getEventType(SchedulingEvent $event): string
    {
        $className = get_class($event);
        $shortName = substr($className, strrpos($className, '\\') + 1);

        // ScheduleCreated → schedule_created
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));

        return $snakeCase;
    }

    /**
     * Identifica ator que disparou evento
     *
     * @return string
     */
    private function getActor(): string
    {
        if (defined('DOING_CRON') && DOING_CRON) {
            return 'cron';
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return 'api';
        }

        if (is_user_logged_in()) {
            return 'admin';
        }

        return 'system';
    }

    /**
     * ID do ator (user ID se logado)
     *
     * @return int|null
     */
    private function getActorId(): ?int
    {
        if (is_user_logged_in()) {
            return get_current_user_id();
        }

        return null;
    }

    /**
     * Busca eventos de um schedule no ledger
     *
     * @param string $scheduleUuid
     * @return array
     */
    public function getEventsForSchedule(string $scheduleUuid): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->ledgerTable}
                WHERE schedule_uuid = %s
                ORDER BY occurred_at ASC",
                $scheduleUuid
            ),
            ARRAY_A
        );

        return array_map(function ($row) {
            $row['event_data'] = json_decode($row['event_data'], true);
            return $row;
        }, $results);
    }

    /**
     * Busca últimos eventos (para dashboard)
     *
     * @param int $limit
     * @return array
     */
    public function getRecentEvents(int $limit = 50): array
    {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->ledgerTable}
                ORDER BY occurred_at DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return array_map(function ($row) {
            $row['event_data'] = json_decode($row['event_data'], true);
            return $row;
        }, $results);
    }
}
