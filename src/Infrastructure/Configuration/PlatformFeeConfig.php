<?php
/**
 * PlatformFeeConfig — Single Source of Truth para taxa da plataforma LimpVix
 *
 * RESPONSABILIDADE:
 * - Centralizar leitura e escrita da taxa em uma única chave de opção WordPress
 * - Eliminar duplicidade entre limpvix_platform_fee_percentage e
 *   limpvix_prof_platform_fee_percentage
 * - Fornecer inicialização segura de default (chamada no activation hook)
 *
 * CHAVE CANÔNICA: limpvix_platform_fee_percentage
 * DEFAULT: 15.0%
 *
 * USOS:
 * - PlatformFeeCalculator::loadConfiguredFee()
 * - CreateManualPayout::calculatePlatformFee()
 * - AdminBootstrap::renderProfissionaisTab() (leitura e save)
 * - limpvix-core.php activation hook (inicialização)
 *
 * @package LimpVix\Infrastructure\Configuration
 * @since 0.8.1
 */

namespace LimpVix\Infrastructure\Configuration;

defined('ABSPATH') || exit;

final class PlatformFeeConfig
{
    /**
     * Chave canônica única no wp_options.
     * Nenhuma outra parte do código deve usar chave diferente.
     */
    public const OPTION_KEY = 'limpvix_platform_fee_percentage';

    /**
     * Chave legada — mantida apenas para migração silenciosa de dados antigos.
     * @internal
     */
    private const LEGACY_KEY = 'limpvix_prof_platform_fee_percentage';

    /**
     * Taxa padrão aplicada quando nenhuma opção foi salva ainda.
     * Deve ser igual ao padrão exibido no formulário de Settings.
     */
    public const DEFAULT_FEE_PERCENTAGE = 15.0;

    /**
     * Retorna a taxa configurada.
     *
     * Ordem de resolução:
     * 1. Chave canônica (limpvix_platform_fee_percentage)
     * 2. Chave legada (limpvix_prof_platform_fee_percentage) — migração silenciosa
     * 3. DEFAULT_FEE_PERCENTAGE (15%)
     *
     * @return float Taxa entre 0 e 100
     */
    public static function getFeePercentage(): float
    {
        if (!function_exists('get_option')) {
            return self::DEFAULT_FEE_PERCENTAGE;
        }

        // 1. Chave canônica
        $value = get_option(self::OPTION_KEY, null);

        // 2. Migração silenciosa da chave legada
        if ($value === null || !is_numeric($value)) {
            $legacy = get_option(self::LEGACY_KEY, null);
            if ($legacy !== null && is_numeric($legacy)) {
                // Migrar: salvar na chave canônica e apagar a legada
                self::setFeePercentage((float) $legacy);
                delete_option(self::LEGACY_KEY);
                return (float) max(0, min(100, $legacy));
            }
            return self::DEFAULT_FEE_PERCENTAGE;
        }

        return (float) max(0, min(100, $value));
    }

    /**
     * Persiste a taxa na chave canônica.
     * Remove automaticamente a chave legada se ainda existir.
     *
     * @param float $percentage Taxa entre 0 e 100
     */
    public static function setFeePercentage(float $percentage): void
    {
        if (!function_exists('update_option')) {
            return;
        }

        $sanitized = (float) max(0, min(100, $percentage));
        update_option(self::OPTION_KEY, $sanitized);

        // Remover chave legada se existir (idempotente)
        if (get_option(self::LEGACY_KEY, null) !== null) {
            delete_option(self::LEGACY_KEY);
        }
    }

    /**
     * Inicializa a opção com o valor padrão se ainda não existir.
     * Deve ser chamado no activation hook do plugin.
     *
     * Idempotente: não sobrescreve valor já configurado pelo admin.
     */
    public static function initializeDefault(): void
    {
        if (!function_exists('add_option')) {
            return;
        }

        // add_option() não sobrescreve se já existir — idempotente
        add_option(self::OPTION_KEY, self::DEFAULT_FEE_PERCENTAGE);

        // Migração silenciosa: se existia apenas a chave legada, mover agora
        if (get_option(self::OPTION_KEY) === null) {
            $legacy = get_option(self::LEGACY_KEY, null);
            if ($legacy !== null && is_numeric($legacy)) {
                update_option(self::OPTION_KEY, (float) max(0, min(100, $legacy)));
            }
        }
    }
}
