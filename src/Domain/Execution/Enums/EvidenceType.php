<?php

declare(strict_types=1);

namespace LimpVix\Domain\Execution\Enums;

/**
 * EvidenceType Enum
 *
 * Categorias de evidências coletadas durante execução de serviço.
 *
 * CATEGORIAS:
 * - EPI_CHECKIN: Vídeo selfie mostrando EPI no momento do check-in (obrigatório)
 * - EPI_CHECKOUT: Fotos de EPI no check-out (confirmação)
 * - LOCATION: Fotos/vídeos do local (antes, durante e após serviço)
 * - ISSUE: Evidências fotográficas de problemas reportados
 *
 * GAP #2: Evidence Categorization System
 *
 * @package LimpVix\Domain\Execution\Enums
 * @since 1.0.0 (GAP #2 Implementation)
 */
enum EvidenceType: string
{
    case EPI_CHECKIN  = 'epi_checkin';
    case EPI_CHECKOUT = 'epi_checkout';
    case LOCATION     = 'location';
    case ISSUE        = 'issue';

    /**
     * Retorna label legível para exibição
     */
    public function label(): string
    {
        return match($this) {
            self::EPI_CHECKIN  => 'EPI - Check-in',
            self::EPI_CHECKOUT => 'EPI - Check-out',
            self::LOCATION     => 'Local de Serviço',
            self::ISSUE        => 'Evidência de Problema',
        };
    }

    /**
     * Indica se esta categoria é obrigatória para validar a execução
     */
    public function isRequired(): bool
    {
        return $this === self::EPI_CHECKIN;
    }

    /**
     * Retorna o estágio da execução em que este tipo é coletado
     */
    public function stage(): string
    {
        return match($this) {
            self::EPI_CHECKIN  => 'check_in',
            self::EPI_CHECKOUT => 'check_out',
            self::LOCATION     => 'execution',
            self::ISSUE        => 'execution',
        };
    }

    /**
     * Cria instância a partir de string (compatibilidade com Evidence VO)
     *
     * @throws \InvalidArgumentException se valor inválido
     */
    public static function fromString(string $value): self
    {
        return self::from($value);
    }

    /**
     * Lista todos os valores válidos
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
