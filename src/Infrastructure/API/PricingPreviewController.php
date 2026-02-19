<?php
/**
 * PricingPreviewController - REST API Controller
 *
 * RESPONSABILIDADE:
 * - Endpoint POST /briefing/price-preview
 * - Recalcular preço em tempo real com dados parciais do briefing
 * - Expor PricingEngine SSOT via REST API
 *
 * ENDPOINT:
 * POST /wp-json/limpvix/v1/briefing/price-preview
 *
 * @package LimpVix\Infrastructure\API
 * @since P1.3
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Domain\Pricing\PricingEngine;
use LimpVix\Domain\Briefing\PropertyStructure;

defined('ABSPATH') || exit;

class PricingPreviewController
{
    /**
     * Registrar rotas
     */
    public function register(): void
    {
        register_rest_route('limpvix/v1', '/briefing/price-preview', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'calculatePreview'],
            'permission_callback' => '__return_true',
            'args' => $this->getArgs()
        ]);
    }

    /**
     * POST /wp-json/limpvix/v1/briefing/price-preview
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function calculatePreview(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            $propertyType = $request->get_param('property_type') ?? 'residential';
            $estimatedM2 = $request->get_param('estimated_m2');
            $cleaningTypes = $request->get_param('cleaning_types') ?? ['standard'];
            $additionals = $request->get_param('additionals') ?? [];
            $packageType = $request->get_param('package_type') ?? 'basic';
            $zipCode = $request->get_param('zip_code');
            $frequency = $request->get_param('frequency') ?? 'once';
            $structure = $request->get_param('structure');

            // If estimated_m2 not provided but structure is, calculate it
            if ($estimatedM2 === null && $structure !== null) {
                $ps = PropertyStructure::fromArray($structure);
                $estimatedM2 = $ps->calculateEstimatedM2();
            }

            if ($estimatedM2 === null) {
                $estimatedM2 = 0;
            }

            // Resolve additionals prices from catalog if only IDs provided
            $resolvedAdditionals = $this->resolveAdditionals($additionals);

            // Geo lookup from cached transient if zip_code provided
            $geoIndex = null;
            $geoMultiplier = null;
            if ($zipCode !== null) {
                $geoData = $this->lookupGeoCache($zipCode);
                if ($geoData !== null) {
                    $geoIndex = $geoData['indice'];
                    $geoMultiplier = $geoData['multiplicador'];
                }
            }

            // Calculate via PricingEngine SSOT
            $result = PricingEngine::calculatePrice([
                'estimated_m2' => (float) $estimatedM2,
                'cleaning_types' => $cleaningTypes,
                'additionals' => $resolvedAdditionals,
                'package_type' => $packageType,
                'property_type' => $propertyType,
                'geo_index' => $geoIndex,
                'geo_multiplier' => $geoMultiplier,
                'frequency' => $frequency,
            ]);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $result
            ], 200);

        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao calcular preview: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve additionals: if only IDs, lookup prices from catalog
     *
     * @param array $additionals
     * @return array
     */
    private function resolveAdditionals(array $additionals): array
    {
        if (empty($additionals)) {
            return [];
        }

        $resolved = [];
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_service_additionals';

        foreach ($additionals as $add) {
            $price = $add['price'] ?? null;
            $quantity = (int) ($add['quantity'] ?? 1);
            $additionalId = $add['additional_id'] ?? null;

            // If price not provided, lookup from catalog
            if ($price === null && $additionalId !== null) {
                $price = $wpdb->get_var($wpdb->prepare(
                    "SELECT base_price FROM {$table} WHERE id = %d AND active = 1",
                    $additionalId
                ));
            }

            if ($price !== null) {
                $resolved[] = [
                    'additional_id' => $additionalId,
                    'price' => (float) $price,
                    'quantity' => $quantity,
                ];
            }
        }

        return $resolved;
    }

    /**
     * Lookup geo data from IBGE cache (transient)
     *
     * @param string $zipCode
     * @return array|null
     */
    private function lookupGeoCache(string $zipCode): ?array
    {
        $cleanCep = preg_replace('/\D/', '', $zipCode);
        $cached = get_transient("limpvix_ibge_cep_{$cleanCep}");

        if ($cached !== false) {
            return $cached;
        }

        // If not cached, try a live lookup (non-blocking for preview)
        if (class_exists('LimpVix\\Infrastructure\\Services\\IBGEAreaIndexService')) {
            $service = new \LimpVix\Infrastructure\Services\IBGEAreaIndexService();
            return $service->calculate($zipCode);
        }

        return null;
    }

    /**
     * Args schema
     *
     * @return array
     */
    private function getArgs(): array
    {
        return [
            'property_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['residential', 'commercial'],
                'description' => 'Tipo de propriedade'
            ],
            'estimated_m2' => [
                'required' => false,
                'type' => 'number',
                'description' => 'm² estimado (ou forneça structure)'
            ],
            'structure' => [
                'required' => false,
                'type' => 'object',
                'description' => 'Estrutura do imóvel (alternativa a estimated_m2)'
            ],
            'cleaning_types' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Tipos de limpeza'
            ],
            'additionals' => [
                'required' => false,
                'type' => 'array',
                'description' => 'Adicionais [{additional_id, price?, quantity}]'
            ],
            'package_type' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Pacote (basic, standard, premium)'
            ],
            'zip_code' => [
                'required' => false,
                'type' => 'string',
                'description' => 'CEP para geo pricing'
            ],
            'frequency' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Frequência (once, weekly, biweekly, monthly)'
            ]
        ];
    }
}
