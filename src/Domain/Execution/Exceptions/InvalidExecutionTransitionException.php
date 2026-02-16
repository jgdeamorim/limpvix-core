<?php
declare(strict_types=1);

namespace LimpVix\Domain\Execution\Exceptions;

use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;

/**
 * Invalid Execution Transition Exception (Sprint 1 - Dia 2)
 *
 * Lançada quando uma transição de estado inválida é tentada em Execution.
 * Exception específica e rastreável - não usa Exception genérica.
 *
 * @package LimpVix\Domain\Execution\Exceptions
 */
class InvalidExecutionTransitionException extends \RuntimeException
{
    public function __construct(
        ExecutionStatusEnum $from,
        ExecutionStatusEnum $to,
        ?string $reason = null
    ) {
        $message = sprintf(
            'Invalid Execution transition: %s → %s',
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
    public static function terminalState(ExecutionStatusEnum $from): self
    {
        return new self($from, $from, 'State is terminal, no transitions allowed');
    }

    /**
     * Factory: Transição proibida (regra de negócio)
     */
    public static function forbidden(
        ExecutionStatusEnum $from,
        ExecutionStatusEnum $to,
        string $reason
    ): self {
        return new self($from, $to, $reason);
    }

    /**
     * Factory: Check-in não realizado
     */
    public static function checkInRequired(ExecutionStatusEnum $from, ExecutionStatusEnum $to): self
    {
        return new self($from, $to, 'Check-in must be performed before this transition');
    }

    /**
     * Factory: Evidência ausente
     */
    public static function evidenceRequired(ExecutionStatusEnum $from, ExecutionStatusEnum $to): self
    {
        return new self($from, $to, 'Evidence is required for this transition');
    }
}
