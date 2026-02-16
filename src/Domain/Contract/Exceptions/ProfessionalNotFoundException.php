<?php
/**
 * ProfessionalNotFoundException - Profissional não encontrado
 *
 * Lançada quando tentamos alocar um profissional que não existe
 *
 * @package LimpVix\Domain\Contract\Exceptions
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract\Exceptions;

defined('ABSPATH') || exit;

final class ProfessionalNotFoundException extends \DomainException
{
    public static function withId(int $professionalId): self
    {
        return new self("Professional not found with ID: {$professionalId}");
    }
}
