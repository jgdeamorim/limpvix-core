<?php

/**
 * NotifyClientOnCheckIn - Listener para notificar cliente no check-in do profissional
 *
 * Escuta o WordPress action `limpvix_execution_checked_in` e envia notificação
 * automática ao cliente informando que o profissional chegou ao local.
 *
 * Estratégia de fallback:
 * 1. WhatsApp (canal preferido)
 * 2. SMS (fallback)
 * 3. Email (fallback final)
 *
 * Delega a lógica de negócio ao ExecutionCheckedInListener existente em Integration,
 * servindo como ponto de entrada no namespace EventListeners para compatibilidade
 * com a verificação de dependências do sistema.
 *
 * GAP #3: Client Check-in Notification
 *
 * @package LimpVix\Infrastructure\EventListeners
 * @since 1.0.0 (GAP #3 Implementation)
 */

namespace LimpVix\Infrastructure\EventListeners;

use LimpVix\Infrastructure\Integration\ExecutionCheckedInListener;

defined('ABSPATH') || exit;

final class NotifyClientOnCheckIn
{
    /**
     * Registra o listener no hook do WordPress
     *
     * @return void
     */
    public static function register(): void
    {
        $instance = new self();
        add_action('limpvix_execution_checked_in', [$instance, 'handle'], 10, 3);
    }

    /**
     * Processa o evento de check-in e envia notificação ao cliente
     *
     * @param string $executionUuid UUID da execução
     * @param int    $orderId       ID do pedido
     * @param int    $professionalId ID do profissional
     * @return void
     */
    public function handle(string $executionUuid, int $orderId, int $professionalId): void
    {
        // Delega ao listener de integração que possui a lógica completa de notificação
        $delegate = new ExecutionCheckedInListener();
        $delegate->handleCheckedIn($executionUuid, $orderId, $professionalId);
    }
}
