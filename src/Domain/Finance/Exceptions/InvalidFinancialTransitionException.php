<?php
declare(strict_types=1);

namespace LimpVix\Domain\Finance\Exceptions;

use LimpVix\Domain\Finance\Enums\FinancialStatusEnum;

/**
 * Invalid Financial Transition Exception (Sprint 0)
 *
 * Lançada quando uma transição de estado financeiro inválida é tentada.
 * Exception específica e rastreável - não usa Exception genérica.
 *
 * @package LimpVix\Domain\Finance\Exceptions
 */
class InvalidFinancialTransitionException extends \RuntimeException
{
    public function __construct(
        FinancialStatusEnum $from,
        FinancialStatusEnum $to,
        ?string $reason = null
    ) {
        $message = sprintf(
            'Invalid Financial transition: %s → %s',
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
    public static function terminalState(FinancialStatusEnum $from): self
    {
        return new self($from, $from, 'State is terminal, no transitions allowed');
    }

    /**
     * Factory: Transição proibida (regra de negócio)
     */
    public static function forbidden(
        FinancialStatusEnum $from,
        FinancialStatusEnum $to,
        string $reason
    ): self {
        return new self($from, $to, $reason);
    }
}
