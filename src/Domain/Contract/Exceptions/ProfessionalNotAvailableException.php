<?php
/**
 * ProfessionalNotAvailableException - Profissional não disponível
 *
 * Lançada quando profissional existe mas não está disponível para alocação
 * Motivos: inativo, suspenso, banido
 *
 * @package LimpVix\Domain\Contract\Exceptions
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract\Exceptions;

defined('ABSPATH') || exit;

final class ProfessionalNotAvailableException extends \DomainException
{
    public static function inactive(int $professionalId): self
    {
        return new self("Professional {$professionalId} is inactive");
    }

    public static function suspended(int $professionalId, string $until): self
    {
        return new self("Professional {$professionalId} is suspended until {$until}");
    }

    public static function banned(int $professionalId): self
    {
        return new self("Professional {$professionalId} is permanently banned");
    }
}
