<?php
declare(strict_types=1);

namespace LimpVix\Domain\Pricing;

use LimpVix\Infrastructure\Configuration\PlatformFeeConfig;

defined('ABSPATH') || exit;

/**
 * PricingEngine - Single Source of Truth para cálculo de preços (P0.3)
 *
 * RESPONSABILIDADE:
 * - Centralizar TODOS os cálculos de preço do sistema
 * - Eliminar os 3 caminhos inconsistentes:
 *   1. WooCommerceBriefingAdapter (hardcoded R$15/m2)
 *   2. BriefingSnapshot (WP option limpvix_briefing_price_per_m2)
 *   3. CreateContractFromBriefing (hardcoded R$15/m2)
 *
 * FÓRMULA:
 * base_price = estimated_m2 × price_per_m2
 * cleaning_adj = base_price × cleaning_type_multiplier
 * additionals_price = SUM(additional_prices)
 * package_increase = (base + cleaning + additionals) × package_percentage
 * geo_adjustment = subtotal × geo_multiplier (P0.4)
 * commercial_adj = subtotal × 1.20 (if commercial)
 * total = MAX(subtotal, MINIMUM_PRICE)
 * platform_fee = total × fee_percentage
 * professional_net = total - platform_fee
 *
 * @package LimpVix\Domain\Pricing
 * @since P0.3
 */
final class PricingEngine
{
    public const OPTION_KEY_PRICE_PER_M2 = 'limpvix_briefing_price_per_m2';
    public const DEFAULT_PRICE_PER_M2 = 15.0;
    public const MINIMUM_PRICE = 150.0;
    public const COMMERCIAL_MULTIPLIER = 1.20;

    /**
     * Cleaning type multipliers (time/complexity factor)
     */
    private const CLEANING_MULTIPLIERS = [
        'standard' => 1.0,
        'pre_move' => 1.3,
        'post_construction' => 1.8,
    ];

    /**
     * Package increase percentages
     */
    private const PACKAGE_PERCENTAGES = [
        'basic' => 0.0,
        'standard' => 0.15,
        'premium' => 0.30,
    ];

    /**
     * Calculate price with full breakdown
     *
     * @param array $input {
     *   estimated_m2: float,
     *   cleaning_types: string[] (standard, pre_move, post_construction),
     *   additionals: array[] ({additional_id, price, quantity}),
     *   package_type: string (basic, standard, premium),
     *   property_type: string (residential, commercial),
     *   geo_index: ?float (0-1, from IBGE - P0.4),
     *   geo_multiplier: ?float (0.85-1.30, from GeoIndex - P0.4),
     *   frequency: ?string (once, weekly, biweekly, monthly),
     * }
     * @return array PricingResult
     */
    public static function calculatePrice(array $input): array
    {
        $estimatedM2 = (float) ($input['estimated_m2'] ?? 0);
        $cleaningTypes = $input['cleaning_types'] ?? ['standard'];
        $additionals = $input['additionals'] ?? [];
        $packageType = $input['package_type'] ?? 'basic';
        $propertyType = $input['property_type'] ?? 'residential';
        $geoIndex = $input['geo_index'] ?? null;
        $geoMultiplier = $input['geo_multiplier'] ?? null;
        $frequency = $input['frequency'] ?? 'once';

        // 1. Price per m2 from WP options (SSOT)
        $pricePerM2 = self::getPricePerM2();

        // 2. Base price
        $basePrice = $estimatedM2 * $pricePerM2;

        // 3. Cleaning type adjustment (use highest multiplier if multiple)
        $cleaningMultiplier = 1.0;
        foreach ($cleaningTypes as $type) {
            $mult = self::CLEANING_MULTIPLIERS[$type] ?? 1.0;
            $cleaningMultiplier = max($cleaningMultiplier, $mult);
        }
        $cleaningAdjustment = $basePrice * ($cleaningMultiplier - 1.0);
        $afterCleaning = $basePrice + $cleaningAdjustment;

        // 4. Additionals price
        $additionalsPrice = 0.0;
        foreach ($additionals as $add) {
            $price = (float) ($add['price'] ?? 0);
            $quantity = (int) ($add['quantity'] ?? 1);
            $additionalsPrice += $price * $quantity;
        }

        // 5. Package increase
        $packagePercentage = self::PACKAGE_PERCENTAGES[$packageType] ?? 0.0;
        $subtotalBeforePackage = $afterCleaning + $additionalsPrice;
        $packageIncrease = $subtotalBeforePackage * $packagePercentage;

        // 6. Commercial adjustment
        $commercialAdjustment = 0.0;
        $subtotalBeforeCommercial = $subtotalBeforePackage + $packageIncrease;
        if ($propertyType === 'commercial') {
            $commercialAdjustment = $subtotalBeforeCommercial * (self::COMMERCIAL_MULTIPLIER - 1.0);
        }

        // 7. Geographic adjustment (P0.4 - nullable)
        $geoAdjustment = 0.0;
        $effectiveGeoMultiplier = 1.0;
        if ($geoMultiplier !== null) {
            $effectiveGeoMultiplier = (float) $geoMultiplier;
            $subtotalBeforeGeo = $subtotalBeforeCommercial + $commercialAdjustment;
            $geoAdjustment = $subtotalBeforeGeo * ($effectiveGeoMultiplier - 1.0);
        }

        // 8. Total with minimum floor
        $totalPrice = $subtotalBeforeCommercial + $commercialAdjustment + $geoAdjustment;
        $totalPrice = max($totalPrice, self::MINIMUM_PRICE);
        $totalPrice = round($totalPrice, 2);

        // 9. Platform fee (static for now, dynamic with geo in P0.4)
        $platformFeePct = PlatformFeeConfig::getFeePercentage();
        $platformFee = round($totalPrice * ($platformFeePct / 100), 2);
        $professionalNet = round($totalPrice - $platformFee, 2);

        // 10. Per-execution value (for recurring)
        $perExecution = $totalPrice;
        $executionsPerMonth = 1;
        if ($frequency === 'weekly') {
            $executionsPerMonth = 4;
            $perExecution = round($totalPrice / 4.33, 2);
        } elseif ($frequency === 'biweekly') {
            $executionsPerMonth = 2;
            $perExecution = round($totalPrice / 2.16, 2);
        } elseif ($frequency === 'monthly') {
            $executionsPerMonth = 1;
            $perExecution = $totalPrice;
        }

        return [
            'price_per_m2' => $pricePerM2,
            'estimated_m2' => $estimatedM2,
            'base_price' => round($basePrice, 2),
            'cleaning_types' => $cleaningTypes,
            'cleaning_multiplier' => $cleaningMultiplier,
            'cleaning_adjustment' => round($cleaningAdjustment, 2),
            'additionals_price' => round($additionalsPrice, 2),
            'additionals_count' => count($additionals),
            'package_type' => $packageType,
            'package_percentage' => $packagePercentage,
            'package_increase' => round($packageIncrease, 2),
            'property_type' => $propertyType,
            'commercial_adjustment' => round($commercialAdjustment, 2),
            'geo_index' => $geoIndex,
            'geo_multiplier' => $effectiveGeoMultiplier,
            'geo_adjustment' => round($geoAdjustment, 2),
            'total_price' => $totalPrice,
            'minimum_applied' => ($totalPrice <= self::MINIMUM_PRICE),
            'platform_fee_pct' => $platformFeePct,
            'platform_fee' => $platformFee,
            'professional_net' => $professionalNet,
            'frequency' => $frequency,
            'executions_per_month' => $executionsPerMonth,
            'per_execution' => $perExecution,
            'per_execution_professional_net' => round($perExecution - ($perExecution * $platformFeePct / 100), 2),
        ];
    }

    /**
     * Get price per m2 from WP options (SSOT)
     *
     * @return float
     */
    public static function getPricePerM2(): float
    {
        if (!function_exists('get_option')) {
            return self::DEFAULT_PRICE_PER_M2;
        }

        return (float) get_option(self::OPTION_KEY_PRICE_PER_M2, self::DEFAULT_PRICE_PER_M2);
    }

    /**
     * Quick price estimate (simplified version for previews)
     *
     * @param float $estimatedM2
     * @param string $propertyType
     * @param string $packageType
     * @return float
     */
    public static function quickEstimate(
        float $estimatedM2,
        string $propertyType = 'residential',
        string $packageType = 'basic'
    ): float {
        $result = self::calculatePrice([
            'estimated_m2' => $estimatedM2,
            'property_type' => $propertyType,
            'package_type' => $packageType,
        ]);

        return $result['total_price'];
    }
}
