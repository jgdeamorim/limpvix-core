<?php
/**
 * ContractNotFoundException - Exception quando contrato não é encontrado
 *
 * @package LimpVix\Domain\Contract\Exceptions
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract\Exceptions;

defined('ABSPATH') || exit;

class ContractNotFoundException extends \RuntimeException
{
    public function __construct(string $identifier)
    {
        parent::__construct("Contract not found: {$identifier}");
    }
}
