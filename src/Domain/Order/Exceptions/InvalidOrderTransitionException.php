<?php
declare(strict_types=1);

namespace LimpVix\Domain\Order\Exceptions;

use LimpVix\Domain\Order\Enums\OrderStatusEnum;

/**
 * Invalid Order Transition Exception (Sprint 0)
 *
 * Lançada quando uma transição de estado inválida é tentada em Order.
 * Exception específica e rastreável - não usa Exception genérica.
 *
 * @package LimpVix\Domain\Order\Exceptions
 */
class InvalidOrderTransitionException extends \RuntimeException
{
    public function __construct(
        OrderStatusEnum $from,
        OrderStatusEnum $to,
        ?string $reason = null
    ) {
        $message = sprintf(
            'Invalid Order transition: %s → %s',
            $from->value,
            $to->value
        );

        if ($reason) {
            $message .= " (Reason: $reason)";
        }

        parent::__construct($message);
    }

    /**
     * Factory: Transição proibida (estado terminal)
     */
    public static function terminalState(OrderStatusEnum $from): self
    {
        return new self($from, $from, 'State is terminal, no transitions allowed');
    }

    /**
     * Factory: Transição proibida (regra de negócio)
     */
    public static function forbidden(OrderStatusEnum $from, OrderStatusEnum $to, string $reason): self
    {
        return new self($from, $to, $reason);
    }
}
