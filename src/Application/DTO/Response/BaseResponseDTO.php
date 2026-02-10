<?php
/**
 * BaseResponseDTO - Abstract base class for Response DTOs
 *
 * RESPONSABILIDADE:
 * - Definir interface comum para todos Response DTOs
 * - Padronizar serialização de aggregates para API
 * - Garantir formato consistente de resposta
 *
 * PRINCÍPIOS:
 * - Response DTOs convertem Domain Objects para arrays
 * - Não expõem detalhes internos do domínio
 * - Formato otimizado para consumo via REST API
 *
 * @package LimpVix\Application\DTO\Response
 * @since 0.10.0
 */

namespace LimpVix\Application\DTO\Response;

defined('ABSPATH') || exit;

abstract class BaseResponseDTO
{
    /**
     * Convert DTO to array for API response
     *
     * @return array
     */
    abstract public function toArray(): array;

    /**
     * Format date for API response
     *
     * @param \DateTimeImmutable|null $date Date to format
     * @param string $format Date format (default: Y-m-d H:i:s)
     * @return string|null Formatted date or null
     */
    protected function formatDate(?\DateTimeImmutable $date, string $format = 'Y-m-d H:i:s'): ?string
    {
        return $date?->format($format);
    }

    /**
     * Format money value
     *
     * @param float $value Money value
     * @param string $currency Currency symbol (default: R$)
     * @return string Formatted money string
     */
    protected function formatMoney(float $value, string $currency = 'R$'): string
    {
        return sprintf('%s %s', $currency, number_format($value, 2, ',', '.'));
    }

    /**
     * Map enum value to human-readable label
     *
     * @param string $value Enum value
     * @param array $labels Map of value => label
     * @return string Label or original value if not found
     */
    protected function mapLabel(string $value, array $labels): string
    {
        return $labels[$value] ?? $value;
    }
}
