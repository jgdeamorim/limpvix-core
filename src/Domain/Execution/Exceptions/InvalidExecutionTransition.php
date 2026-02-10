<?php
/**
 * InvalidExecutionTransition - Transição de status inválida
 *
 * Lançada quando tentativa de mudança de status viola regras de negócio
 *
 * @package LimpVix\Domain\Execution\Exceptions
 * @since 0.9.0
 */

namespace LimpVix\Domain\Execution\Exceptions;

defined('ABSPATH') || exit;

final class InvalidExecutionTransition extends \DomainException
{
    public static function fromTo(string $from, string $to): self
    {
        return new self("Invalid execution transition from '{$from}' to '{$to}'");
    }
}
