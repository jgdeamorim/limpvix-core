<?php
declare(strict_types=1);

namespace LimpVix\Domain\Pricing;

defined('ABSPATH') || exit;

/**
 * GeoIndex - Value Object para índice geográfico socioeconômico (P0.4)
 *
 * Baseado em dados IBGE (PIB per capita, população, densidade).
 * Classifica regiões e determina multiplicadores de preço e taxas.
 *
 * Classificações:
 * - Vulnerável (<0.30): multiplicador 0.85, fee 15%
 * - Popular (0.30-0.50): multiplicador 0.95, fee 15%
 * - Médio (0.50-0.70): multiplicador 1.00, fee 18%
 * - Alto (0.70-0.85): multiplicador 1.15, fee 20%
 * - Premium (>=0.85): multiplicador 1.30, fee 25%
 *
 * @package LimpVix\Domain\Pricing
 * @since P0.4
 */
final readonly class GeoIndex
{
    public const CLASS_VULNERAVEL = 'vulneravel';
    public const CLASS_POPULAR = 'popular';
    public const CLASS_MEDIO = 'medio';
    public const CLASS_ALTO = 'alto';
    public const CLASS_PREMIUM = 'premium';

    private const THRESHOLDS = [
        self::CLASS_VULNERAVEL => ['max' => 0.30, 'multiplier' => 0.85, 'fee' => 15.0],
        self::CLASS_POPULAR    => ['max' => 0.50, 'multiplier' => 0.95, 'fee' => 15.0],
        self::CLASS_MEDIO      => ['max' => 0.70, 'multiplier' => 1.00, 'fee' => 18.0],
        self::CLASS_ALTO       => ['max' => 0.85, 'multiplier' => 1.15, 'fee' => 20.0],
        self::CLASS_PREMIUM    => ['max' => 1.00, 'multiplier' => 1.30, 'fee' => 25.0],
    ];

    public function __construct(
        public string $municipio,
        public float $indice,
        public string $classificacao,
        public float $multiplicador,
        public float $feePercentage,
        public ?float $pibPerCapita = null,
        public ?int $populacao = null,
        public ?float $densidade = null
    ) {}

    /**
     * Create GeoIndex from IBGE calculation result
     *
     * @param array $ibgeResult {municipio, indice, pib_per_capita, populacao, densidade}
     * @return self
     */
    public static function fromIBGEResult(array $ibgeResult): self
    {
        $indice = (float) ($ibgeResult['indice'] ?? 0);
        $classificacao = self::classify($indice);
        $thresholds = self::THRESHOLDS[$classificacao];

        return new self(
            municipio: $ibgeResult['municipio'] ?? 'Desconhecido',
            indice: $indice,
            classificacao: $classificacao,
            multiplicador: $thresholds['multiplier'],
            feePercentage: $thresholds['fee'],
            pibPerCapita: isset($ibgeResult['pib_per_capita']) ? (float) $ibgeResult['pib_per_capita'] : null,
            populacao: isset($ibgeResult['populacao']) ? (int) $ibgeResult['populacao'] : null,
            densidade: isset($ibgeResult['densidade']) ? (float) $ibgeResult['densidade'] : null
        );
    }

    /**
     * Create a neutral/default GeoIndex (when IBGE data unavailable)
     *
     * @return self
     */
    public static function neutral(): self
    {
        return new self(
            municipio: 'N/A',
            indice: 0.50,
            classificacao: self::CLASS_MEDIO,
            multiplicador: 1.0,
            feePercentage: 18.0
        );
    }

    /**
     * Classify index into tier
     *
     * @param float $indice (0-1)
     * @return string Classification constant
     */
    public static function classify(float $indice): string
    {
        if ($indice < 0.30) return self::CLASS_VULNERAVEL;
        if ($indice < 0.50) return self::CLASS_POPULAR;
        if ($indice < 0.70) return self::CLASS_MEDIO;
        if ($indice < 0.85) return self::CLASS_ALTO;
        return self::CLASS_PREMIUM;
    }

    /**
     * Get multiplier for a given index
     *
     * @param float $indice
     * @return float
     */
    public static function getMultiplierForIndex(float $indice): float
    {
        $class = self::classify($indice);
        return self::THRESHOLDS[$class]['multiplier'];
    }

    /**
     * Get fee percentage for a given index
     *
     * @param float $indice
     * @return float
     */
    public static function getFeeForIndex(float $indice): float
    {
        $class = self::classify($indice);
        return self::THRESHOLDS[$class]['fee'];
    }

    /**
     * Get all classifications with thresholds
     *
     * @return array
     */
    public static function getAllClassifications(): array
    {
        return self::THRESHOLDS;
    }

    public function toArray(): array
    {
        return [
            'municipio' => $this->municipio,
            'indice' => $this->indice,
            'classificacao' => $this->classificacao,
            'multiplicador' => $this->multiplicador,
            'fee_percentage' => $this->feePercentage,
            'pib_per_capita' => $this->pibPerCapita,
            'populacao' => $this->populacao,
            'densidade' => $this->densidade,
        ];
    }
}
