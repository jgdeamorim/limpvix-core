<?php
/**
 * ServiceCatalogController - REST API para Catálogo de Serviços
 *
 * RESPONSABILIDADE:
 * - Endpoint para listar serviços ativos
 * - Endpoint para listar adicionais ativos
 * - Endpoint para adicionar extras a um briefing
 * - Validação de permissões e dados
 *
 * ROTAS:
 * - GET  /limpvix/v1/services (lista serviços)
 * - GET  /limpvix/v1/additionals (lista adicionais)
 * - POST /limpvix/v1/briefing/{uuid}/additionals (adiciona extras)
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.1.11
 */

namespace LimpVix\Infrastructure\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined('ABSPATH') || exit;

class ServiceCatalogController
{
    private const NAMESPACE = 'limpvix/v1';

    private $wpdb;
    private $tableServices;
    private $tableAdditionals;
    private $tableBriefingAdditionals;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableServices = $wpdb->prefix . 'limpvix_service_catalog';
        $this->tableAdditionals = $wpdb->prefix . 'limpvix_service_additionals';
        $this->tableBriefingAdditionals = $wpdb->prefix . 'limpvix_briefing_additionals';
    }

    /**
     * Registrar rotas no WordPress REST API
     *
     * @return void
     */
    public function register(): void
    {
        // GET /limpvix/v1/services - Listar serviços
        register_rest_route(self::NAMESPACE, '/services', [
            'methods' => 'GET',
            'callback' => [$this, 'listServices'],
            'permission_callback' => '__return_true', // Público
            'args' => [
                'category' => [
                    'required' => false,
                    'type' => 'string',
                    'enum' => ['commercial', 'residential'],
                    'description' => 'Filtrar por categoria'
                ]
            ]
        ]);

        // GET /limpvix/v1/additionals - Listar adicionais
        register_rest_route(self::NAMESPACE, '/additionals', [
            'methods' => 'GET',
            'callback' => [$this, 'listAdditionals'],
            'permission_callback' => '__return_true', // Público
            'args' => [
                'category' => [
                    'required' => false,
                    'type' => 'string',
                    'enum' => ['commercial', 'residential'],
                    'description' => 'Filtrar por categoria compatível'
                ]
            ]
        ]);

        // POST /limpvix/v1/briefing/{uuid}/additionals - Adicionar extras
        register_rest_route(self::NAMESPACE, '/briefing/(?P<uuid>[a-f0-9\-]+)/additionals', [
            'methods' => 'POST',
            'callback' => [$this, 'addAdditionalsToBriefing'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'uuid' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'UUID do Briefing'
                ],
                'additionals' => [
                    'required' => true,
                    'type' => 'array',
                    'description' => 'Array de adicionais: [{"id": 1, "quantity": 2, "notes": "..."}]'
                ]
            ]
        ]);
    }

    /**
     * GET /limpvix/v1/services
     *
     * Lista serviços ativos, opcionalmente filtrados por categoria
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function listServices(WP_REST_Request $request)
    {
        try {
            $category = $request->get_param('category');

            $sql = "SELECT
                        id,
                        service_code,
                        category,
                        service_type,
                        display_name,
                        description,
                        base_price,
                        time_multiplier,
                        requires_multiple_professionals
                    FROM {$this->tableServices}
                    WHERE is_active = 1";

            if ($category) {
                $sql .= $this->wpdb->prepare(" AND category = %s", $category);
            }

            $sql .= " ORDER BY category, service_type";

            $services = $this->wpdb->get_results($sql, ARRAY_A);

            // Formatar resposta
            $formatted = array_map(function($service) {
                return [
                    'id' => (int)$service['id'],
                    'code' => $service['service_code'],
                    'category' => $service['category'],
                    'type' => $service['service_type'],
                    'name' => $service['display_name'],
                    'description' => $service['description'],
                    'base_price' => (float)$service['base_price'],
                    'time_multiplier' => (float)$service['time_multiplier'],
                    'requires_multiple_professionals' => (bool)$service['requires_multiple_professionals']
                ];
            }, $services);

            return new WP_REST_Response([
                'success' => true,
                'data' => $formatted,
                'count' => count($formatted)
            ], 200);

        } catch (\Exception $e) {
            error_log('[ServiceCatalogController] listServices error: ' . $e->getMessage());
            return new WP_Error('limpvix_services_error', 'Erro ao listar serviços', ['status' => 500]);
        }
    }

    /**
     * GET /limpvix/v1/additionals
     *
     * Lista adicionais ativos, opcionalmente filtrados por categoria
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function listAdditionals(WP_REST_Request $request)
    {
        try {
            $category = $request->get_param('category');

            $sql = "SELECT
                        id,
                        additional_code,
                        display_name,
                        description,
                        unit_type,
                        base_price,
                        estimated_duration_minutes,
                        compatible_with_categories
                    FROM {$this->tableAdditionals}
                    WHERE is_active = 1";

            // Filtrar por categoria se especificado
            if ($category) {
                $sql .= $this->wpdb->prepare(" AND JSON_CONTAINS(compatible_with_categories, %s, '$')", '"' . $category . '"');
            }

            $sql .= " ORDER BY display_name";

            $additionals = $this->wpdb->get_results($sql, ARRAY_A);

            // Formatar resposta
            $formatted = array_map(function($additional) {
                return [
                    'id' => (int)$additional['id'],
                    'code' => $additional['additional_code'],
                    'name' => $additional['display_name'],
                    'description' => $additional['description'],
                    'unit_type' => $additional['unit_type'],
                    'unit_label' => $this->getUnitLabel($additional['unit_type']),
                    'base_price' => (float)$additional['base_price'],
                    'duration_minutes' => (int)$additional['estimated_duration_minutes'],
                    'compatible_categories' => json_decode($additional['compatible_with_categories'], true) ?: []
                ];
            }, $additionals);

            return new WP_REST_Response([
                'success' => true,
                'data' => $formatted,
                'count' => count($formatted)
            ], 200);

        } catch (\Exception $e) {
            error_log('[ServiceCatalogController] listAdditionals error: ' . $e->getMessage());
            return new WP_Error('limpvix_additionals_error', 'Erro ao listar adicionais', ['status' => 500]);
        }
    }

    /**
     * POST /limpvix/v1/briefing/{uuid}/additionals
     *
     * Adiciona adicionais/extras a um briefing
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function addAdditionalsToBriefing(WP_REST_Request $request)
    {
        $briefingUuid = $request->get_param('uuid');
        $additionals = $request->get_param('additionals');

        if (empty($additionals) || !is_array($additionals)) {
            return new WP_Error('missing_additionals', 'Lista de adicionais não fornecida', ['status' => 400]);
        }

        try {
            // Verificar se briefing existe
            $briefingExists = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->wpdb->prefix}limpvix_briefings WHERE uuid = %s",
                $briefingUuid
            ));

            if (!$briefingExists) {
                return new WP_Error('briefing_not_found', 'Briefing não encontrado', ['status' => 404]);
            }

            // Limpar adicionais antigos (se houver)
            $this->wpdb->delete($this->tableBriefingAdditionals, ['briefing_uuid' => $briefingUuid]);

            // Inserir novos adicionais
            $inserted = [];
            foreach ($additionals as $additional) {
                $additionalId = (int)($additional['id'] ?? 0);
                $quantity = (float)($additional['quantity'] ?? 1);
                $notes = sanitize_textarea_field($additional['notes'] ?? '');

                if ($additionalId <= 0) {
                    continue;
                }

                // Buscar preço do adicional
                $additionalData = $this->wpdb->get_row($this->wpdb->prepare(
                    "SELECT base_price, display_name FROM {$this->tableAdditionals} WHERE id = %d AND is_active = 1",
                    $additionalId
                ), ARRAY_A);

                if (!$additionalData) {
                    continue;
                }

                $unitPrice = (float)$additionalData['base_price'];
                $totalPrice = $quantity * $unitPrice;

                // Inserir
                $result = $this->wpdb->insert(
                    $this->tableBriefingAdditionals,
                    [
                        'briefing_uuid' => $briefingUuid,
                        'additional_id' => $additionalId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'notes' => $notes,
                        'created_at' => current_time('mysql')
                    ],
                    ['%s', '%d', '%f', '%f', '%f', '%s', '%s']
                );

                if ($result) {
                    $inserted[] = [
                        'id' => $this->wpdb->insert_id,
                        'additional_id' => $additionalId,
                        'name' => $additionalData['display_name'],
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice
                    ];
                }
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => count($inserted) . ' adicionais adicionados com sucesso',
                'data' => [
                    'briefing_uuid' => $briefingUuid,
                    'additionals' => $inserted,
                    'total_additionals_price' => array_sum(array_column($inserted, 'total_price'))
                ]
            ], 200);

        } catch (\Exception $e) {
            error_log('[ServiceCatalogController] addAdditionalsToBriefing error: ' . $e->getMessage());
            return new WP_Error('additionals_save_error', 'Erro ao adicionar extras: ' . $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * Verificar permissão
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function checkPermission(WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', 'Você precisa estar logado', ['status' => 401]);
        }
        return true;
    }

    /**
     * Obter label da unidade
     *
     * @param string $unitType
     * @return string
     */
    private function getUnitLabel(string $unitType): string
    {
        $labels = [
            'fixed' => 'Preço fixo',
            'per_m2' => 'Por m²',
            'per_unit' => 'Por unidade'
        ];
        return $labels[$unitType] ?? $unitType;
    }
}
