<?php
/**
 * GeoTierConfig — SSOT para multiplicadores e taxas por geo-tier IBGE
 *
 * Armazena configurações admin-definidas para cada tier socioeconômico:
 * - multiplier: ajuste de preço do serviço (lado cliente)
 * - fee: comissão da plataforma no payout (lado profissional)
 *
 * CHAVE CANÔNICA: limpvix_geo_tier_config
 *
 * USOS:
 * - GeoIndex::getMultiplierForIndex() (pricing cliente)
 * - GeoIndex::getFeeForIndex() (fee plataforma)
 * - ProfissionaisTab (admin UI de calibração)
 *
 * @package LimpVix\Infrastructure\Configuration
 * @since 0.9.0
 */

namespace LimpVix\Infrastructure\Configuration;

defined('ABSPATH') || exit;

final class GeoTierConfig
{
    public const OPTION_KEY = 'limpvix_geo_tier_config';

    public const VALID_TIERS = ['vulneravel', 'popular', 'medio', 'alto', 'premium'];

    /**
     * Defaults idênticos aos THRESHOLDS originais do GeoIndex.
     * Usados quando nenhuma configuração admin foi salva.
     */
    private const DEFAULTS = [
        'vulneravel' => ['multiplier' => 0.85, 'fee' => 15.0],
        'popular'    => ['multiplier' => 0.95, 'fee' => 15.0],
        'medio'      => ['multiplier' => 1.00, 'fee' => 18.0],
        'alto'       => ['multiplier' => 1.15, 'fee' => 20.0],
        'premium'    => ['multiplier' => 1.30, 'fee' => 25.0],
    ];

    /**
     * Retorna multiplicador configurado para um tier.
     *
     * @param string $class Tier classification (vulneravel, popular, medio, alto, premium)
     * @return float Multiplier (0.50 - 2.00)
     */
    public static function getMultiplier(string $class): float
    {
        $config = self::getAll();
        return (float) ($config[$class]['multiplier'] ?? self::DEFAULTS['medio']['multiplier']);
    }

    /**
     * Retorna taxa (fee) configurada para um tier.
     *
     * @param string $class Tier classification
     * @return float Fee percentage (5.0 - 50.0)
     */
    public static function getFee(string $class): float
    {
        $config = self::getAll();
        return (float) ($config[$class]['fee'] ?? self::DEFAULTS['medio']['fee']);
    }

    /**
     * Retorna configuração completa de todos os tiers (merged com defaults).
     *
     * @return array<string, array{multiplier: float, fee: float}>
     */
    public static function getAll(): array
    {
        if (!function_exists('get_option')) {
            return self::DEFAULTS;
        }

        $saved = get_option(self::OPTION_KEY, []);

        if (!is_array($saved) || empty($saved)) {
            return self::DEFAULTS;
        }

        // Merge: saved values override defaults, per-tier
        $result = [];
        foreach (self::VALID_TIERS as $tier) {
            $result[$tier] = [
                'multiplier' => (float) ($saved[$tier]['multiplier'] ?? self::DEFAULTS[$tier]['multiplier']),
                'fee'        => (float) ($saved[$tier]['fee'] ?? self::DEFAULTS[$tier]['fee']),
            ];
        }

        return $result;
    }

    /**
     * Retorna os defaults hardcoded (para botão "Restaurar Padrão").
     *
     * @return array<string, array{multiplier: float, fee: float}>
     */
    public static function getDefaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * Persiste configuração de tiers no wp_options.
     *
     * @param array<string, array{multiplier: float, fee: float}> $tiers
     */
    public static function save(array $tiers): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $sanitized = [];
        foreach (self::VALID_TIERS as $tier) {
            if (!isset($tiers[$tier])) {
                // Manter default se tier não fornecido
                $sanitized[$tier] = self::DEFAULTS[$tier];
                continue;
            }

            $sanitized[$tier] = [
                'multiplier' => (float) max(0.50, min(2.00, $tiers[$tier]['multiplier'] ?? self::DEFAULTS[$tier]['multiplier'])),
                'fee'        => (float) max(5.0, min(50.0, $tiers[$tier]['fee'] ?? self::DEFAULTS[$tier]['fee'])),
            ];
        }

        update_option(self::OPTION_KEY, $sanitized);
    }

    /**
     * Inicializa opção com defaults se não existir.
     * Chamar no activation hook. Idempotente.
     */
    public static function initializeDefault(): void
    {
        if (!function_exists('add_option')) {
            return;
        }

        add_option(self::OPTION_KEY, self::DEFAULTS);
    }
}
