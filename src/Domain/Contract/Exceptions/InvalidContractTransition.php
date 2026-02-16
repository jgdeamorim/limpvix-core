<?php
/**
 * InvalidContractTransition - Exception para transições inválidas de status
 *
 * @package LimpVix\Domain\Contract\Exceptions
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract\Exceptions;

defined('ABSPATH') || exit;

class InvalidContractTransition extends \DomainException
{
    public function __construct(string $message = 'Invalid contract status transition')
    {
        parent::__construct($message);
    }
}
