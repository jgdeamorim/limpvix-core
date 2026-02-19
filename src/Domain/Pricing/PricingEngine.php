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
     * Complexity multipliers (time/scope factor)
     * New naming from Service Domain Refactor (FASE 4A)
     * Includes legacy aliases for backward compatibility
     */
    private const COMPLEXITY_MULTIPLIERS = [
        // New slugs (from wp_limpvix_service_complexities)
        'standard' => 1.0,
        'detailed' => 1.3,
        'post_construction' => 1.8,
        // Legacy alias
        'pre_move' => 1.3,
    ];

    /**
     * Execution level multipliers (operational quality factor)
     * New naming from Service Domain Refactor (FASE 4A)
     * Includes legacy aliases for backward compatibility
     */
    private const EXECUTION_LEVEL_MULTIPLIERS = [
        // New slugs (from wp_limpvix_execution_levels)
        'basic_execution' => 1.0,
        'standard_execution' => 1.15,
        'premium_execution' => 1.30,
        // Legacy aliases (from old package_type)
        'basic' => 1.0,
        'standard' => 1.15,
        'premium' => 1.30,
    ];

    /** @deprecated Use COMPLEXITY_MULTIPLIERS */
    private const CLEANING_MULTIPLIERS = self::COMPLEXITY_MULTIPLIERS;

    /** @deprecated Use EXECUTION_LEVEL_MULTIPLIERS */
    private const PACKAGE_PERCENTAGES = [
        'basic' => 0.0,
        'standard' => 0.15,
        'premium' => 0.30,
    ];

    /**
     * Calculate price with full breakdown
     *
     * Accepts both new and legacy parameter names:
     * - complexity_slug OR cleaning_types (new preferred)
     * - execution_level OR package_type (new preferred)
     *
     * @param array $input {
     *   estimated_m2: float,
     *   complexity_slug: ?string (standard, detailed, post_construction) — NEW,
     *   cleaning_types: ?string[] (standard, pre_move, post_construction) — LEGACY,
     *   additionals: array[] ({additional_id, price, quantity}),
     *   execution_level: ?string (basic_execution, standard_execution, premium_execution) — NEW,
     *   package_type: ?string (basic, standard, premium) — LEGACY,
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
        $additionals = $input['additionals'] ?? [];
        $propertyType = $input['property_type'] ?? 'residential';
        $geoIndex = $input['geo_index'] ?? null;
        $geoMultiplier = $input['geo_multiplier'] ?? null;
        $frequency = $input['frequency'] ?? 'once';

        // Resolve complexity: new param or legacy
        $complexitySlug = $input['complexity_slug'] ?? null;
        $cleaningTypes = $input['cleaning_types'] ?? ['standard'];
        if ($complexitySlug) {
            $cleaningTypes = [$complexitySlug];
        }

        // Resolve execution level: new param or legacy
        $executionLevel = $input['execution_level'] ?? null;
        $packageType = $input['package_type'] ?? 'basic';
        if ($executionLevel) {
            $packageType = $executionLevel;
        }

        // 1. Price per m2 from WP options (SSOT)
        $pricePerM2 = self::getPricePerM2();

        // 2. Base price
        $basePrice = $estimatedM2 * $pricePerM2;

        // 3. Complexity adjustment (use highest multiplier if multiple)
        $cleaningMultiplier = 1.0;
        foreach ($cleaningTypes as $type) {
            $mult = self::COMPLEXITY_MULTIPLIERS[$type] ?? 1.0;
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

        // 5. Execution level / package increase
        $executionMultiplier = self::EXECUTION_LEVEL_MULTIPLIERS[$packageType] ?? 1.0;
        $packagePercentage = $executionMultiplier - 1.0; // 1.15 → 0.15
        $subtotalBeforePackage = $afterCleaning + $additionalsPrice;
        $packageIncrease = $subtotalBeforePackage * $packagePercentage;

        // 6. Commercial adjustment
        $commercialAdjustment = 0.0;
        $subtotalBeforeCommercial = $subtotalBeforePackage + $packageIncrease;
        if ($propertyType === 'commercial') {
            $commercialAdjustment = $subtotalBeforeCommercial * (self::COMMERCIAL_MULTIPLIER - 1.0);
        }

        // 7. Geographic adjustment (P0.4 - uses GeoIndex multiplier)
        $geoAdjustment = 0.0;
        $effectiveGeoMultiplier = 1.0;
        if ($geoMultiplier !== null) {
            $effectiveGeoMultiplier = (float) $geoMultiplier;
            $subtotalBeforeGeo = $subtotalBeforeCommercial + $commercialAdjustment;
            $geoAdjustment = $subtotalBeforeGeo * ($effectiveGeoMultiplier - 1.0);
        } elseif ($geoIndex !== null) {
            // If multiplier not provided but index is, derive from GeoIndex VO
            $effectiveGeoMultiplier = GeoIndex::getMultiplierForIndex((float) $geoIndex);
            $subtotalBeforeGeo = $subtotalBeforeCommercial + $commercialAdjustment;
            $geoAdjustment = $subtotalBeforeGeo * ($effectiveGeoMultiplier - 1.0);
        }

        // 8. Total with minimum floor
        $totalPrice = $subtotalBeforeCommercial + $commercialAdjustment + $geoAdjustment;
        $totalPrice = max($totalPrice, self::MINIMUM_PRICE);
        $totalPrice = round($totalPrice, 2);

        // 9. Platform fee - dynamic based on geo index (P0.4)
        $platformFeePct = PlatformFeeConfig::getFeeByGeoIndex($geoIndex !== null ? (float) $geoIndex : null);
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
            // Complexity (new) + legacy aliases
            'complexity_slug' => $complexitySlug ?? ($cleaningTypes[0] ?? 'standard'),
            'complexity_multiplier' => $cleaningMultiplier,
            'cleaning_types' => $cleaningTypes,
            'cleaning_multiplier' => $cleaningMultiplier,
            'cleaning_adjustment' => round($cleaningAdjustment, 2),
            'additionals_price' => round($additionalsPrice, 2),
            'additionals_count' => count($additionals),
            // Execution level (new) + legacy aliases
            'execution_level' => $executionLevel ?? $packageType,
            'execution_level_multiplier' => $executionMultiplier,
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
     * @param string $packageType Legacy param (basic/standard/premium)
     * @param string|null $executionLevel New param (basic_execution/standard_execution/premium_execution)
     * @param string|null $complexitySlug New param (standard/detailed/post_construction)
     * @return float
     */
    public static function quickEstimate(
        float $estimatedM2,
        string $propertyType = 'residential',
        string $packageType = 'basic',
        ?string $executionLevel = null,
        ?string $complexitySlug = null
    ): float {
        $input = [
            'estimated_m2' => $estimatedM2,
            'property_type' => $propertyType,
            'package_type' => $packageType,
        ];

        if ($executionLevel !== null) {
            $input['execution_level'] = $executionLevel;
        }
        if ($complexitySlug !== null) {
            $input['complexity_slug'] = $complexitySlug;
        }

        return self::calculatePrice($input)['total_price'];
    }
}
