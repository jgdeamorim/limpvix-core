<?php
/**
 * WordPressEventDispatcher - Dispatcher de Domain Events via WordPress Hooks
 *
 * RESPONSABILIDADE:
 * - Despachar Domain Events via do_action() do WordPress
 * - Converter Domain Events em WordPress actions
 * - Permitir extensibilidade via add_action()
 *
 * ESTRATÉGIA:
 * - Domain Events são objetos PHP
 * - WordPress actions recebem array de dados
 * - Nomeação: limpvix_{aggregate}_{event_name}
 *
 * EXEMPLO:
 * Domain Event: ContractActivated
 * WordPress Action: limpvix_contract_activated
 *
 * @package LimpVix\Infrastructure\Events
 * @since 0.8.0
 */

namespace LimpVix\Infrastructure\Events;

defined('ABSPATH') || exit;

final class WordPressEventDispatcher
{
    /**
     * Despachar Domain Event via WordPress do_action()
     *
     * @param object $event Domain Event object
     * @return void
     */
    public function dispatch(object $event): void
    {
        $actionName = $this->getActionName($event);
        $eventData = $this->extractEventData($event);

        // Despachar via WordPress
        do_action($actionName, $eventData);

        // Log (apenas se WP_DEBUG)
        $this->logEvent($actionName, $eventData);
    }

    /**
     * Despachar múltiplos eventos
     *
     * @param array $events Array de Domain Events
     * @return void
     */
    public function dispatchAll(array $events): void
    {
        foreach ($events as $event) {
            $this->dispatch($event);
        }
    }

    /**
     * Converter nome da classe do evento em action name
     *
     * EXEMPLOS:
     * LimpVix\Domain\Contract\Events\ContractActivated
     *   → limpvix_contract_activated
     *
     * LimpVix\Domain\Contract\Events\ContractPaused
     *   → limpvix_contract_paused
     *
     * @param object $event
     * @return string
     */
    private function getActionName(object $event): string
    {
        $className = get_class($event);

        // Extrair nome curto da classe (última parte do namespace)
        $shortName = substr($className, strrpos($className, '\\') + 1);

        // Extrair aggregate name do namespace
        // LimpVix\Domain\Contract\Events\ContractActivated → contract
        $parts = explode('\\', $className);
        $aggregateName = strtolower($parts[2] ?? 'unknown');

        // Remover prefixo do aggregate do evento se presente
        // ContractActivated → Activated
        $aggregatePrefix = ucfirst($aggregateName);
        if (strpos($shortName, $aggregatePrefix) === 0) {
            $shortName = substr($shortName, strlen($aggregatePrefix));
        }

        // Converter CamelCase para snake_case
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $shortName));

        return "limpvix_{$aggregateName}_{$snakeCase}";
    }

    /**
     * Extrair dados do evento para passar ao hook
     *
     * ESTRATÉGIA:
     * - Tentar método toArray() primeiro
     * - Fallback: usar reflexão para pegar propriedades públicas
     * - Sempre incluir event_type e timestamp
     *
     * @param object $event
     * @return array
     */
    private function extractEventData(object $event): array
    {
        // Se evento tem método toArray(), usar
        if (method_exists($event, 'toArray')) {
            $data = $event->toArray();
        } else {
            // Fallback: reflexão
            $data = $this->extractViaReflection($event);
        }

        // Adicionar metadados
        $data['event_type'] = get_class($event);
        $data['dispatched_at'] = current_time('mysql');

        return $data;
    }

    /**
     * Extrair dados via reflexão (fallback)
     *
     * @param object $event
     * @return array
     */
    private function extractViaReflection(object $event): array
    {
        $data = [];
        $reflection = new \ReflectionObject($event);

        foreach ($reflection->getProperties() as $property) {
            // Tornar acessível se privada/protegida
            $property->setAccessible(true);

            $name = $property->getName();
            $value = $property->getValue($event);

            // Converter objetos complexos em formato serializável
            if (is_object($value)) {
                if (method_exists($value, 'toInt')) {
                    $data[$name] = $value->toInt();
                } elseif (method_exists($value, 'toString')) {
                    $data[$name] = $value->toString();
                } elseif (method_exists($value, '__toString')) {
                    $data[$name] = (string) $value;
                } elseif ($value instanceof \DateTimeInterface) {
                    $data[$name] = $value->format('Y-m-d H:i:s');
                } else {
                    $data[$name] = get_class($value);
                }
            } else {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * Log do evento despachado (apenas se WP_DEBUG)
     *
     * @param string $actionName
     * @param array $eventData
     * @return void
     */
    private function logEvent(string $actionName, array $eventData): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Event Dispatcher] Action: %s | Data: %s',
                $actionName,
                json_encode($eventData, JSON_UNESCAPED_UNICODE)
            ));
        }
    }
}
