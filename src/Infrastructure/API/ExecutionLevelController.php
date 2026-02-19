<?php
/**
 * ExecutionLevelController - REST API Controller para Niveis de Execucao
 *
 * RESPONSABILIDADE:
 * - Endpoint para listar execution levels ativos
 * - Endpoint para selecionar execution level em um briefing
 *
 * ROTAS:
 * - GET  /limpvix/v1/execution-levels (lista niveis ativos)
 * - POST /limpvix/v1/briefing/{uuid}/execution-level (seleciona nivel)
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.4.0 (Service Domain Refactor - FASE 6)
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\UseCases\Briefing\SelectPackage;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined('ABSPATH') || exit;

class ExecutionLevelController
{
    private const NAMESPACE = 'limpvix/v1';

    /**
     * @var SelectPackage Reuses SelectPackage use case (backward compat)
     */
    private $selectPackageUseCase;

    public function __construct(SelectPackage $selectPackageUseCase)
    {
        $this->selectPackageUseCase = $selectPackageUseCase;
    }

    public function register(): void
    {
        // GET /limpvix/v1/execution-levels
        register_rest_route(self::NAMESPACE, '/execution-levels', [
            'methods' => 'GET',
            'callback' => [$this, 'listExecutionLevels'],
            'permission_callback' => '__return_true',
        ]);

        // POST /limpvix/v1/briefing/{uuid}/execution-level
        register_rest_route(self::NAMESPACE, '/briefing/(?P<uuid>[a-f0-9\-]+)/execution-level', [
            'methods' => 'POST',
            'callback' => [$this, 'selectExecutionLevel'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'uuid' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'UUID do Briefing',
                    'validate_callback' => function ($param) {
                        return preg_match('/^[a-f0-9\-]{36}$/', $param);
                    }
                ],
                'execution_level' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Slug do nivel de execucao (basic_execution, standard_execution, premium_execution)',
                    'validate_callback' => function ($param) {
                        return !empty($param) && strlen($param) <= 50;
                    }
                ]
            ]
        ]);
    }

    /**
     * GET /limpvix/v1/execution-levels
     */
    public function listExecutionLevels(WP_REST_Request $request)
    {
        global $wpdb;

        try {
            $tableName = $wpdb->prefix . 'limpvix_execution_levels';

            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$tableName}'");
            if (!$tableExists) {
                // Fallback: return legacy package_configs formatted as execution levels
                return $this->listLegacyPackages();
            }

            $levels = $wpdb->get_results(
                "SELECT
                    slug,
                    display_name,
                    description,
                    price_multiplier,
                    team_min,
                    team_max,
                    checklist_level,
                    warranty_hours
                FROM {$tableName}
                WHERE is_active = 1
                ORDER BY price_multiplier ASC",
                ARRAY_A
            );

            $formatted = array_map(function ($level) {
                $multiplier = (float)$level['price_multiplier'];
                return [
                    'slug' => $level['slug'],
                    'name' => $level['display_name'],
                    'description' => $level['description'],
                    'price_multiplier' => $multiplier,
                    'percentage_display' => '+' . number_format(($multiplier - 1) * 100, 0) . '%',
                    'team_min' => (int)$level['team_min'],
                    'team_max' => (int)$level['team_max'],
                    'checklist_level' => $level['checklist_level'],
                    'warranty_hours' => (int)$level['warranty_hours'],
                    // Legacy compatibility fields
                    'type' => $level['slug'],
                    'percentage_increase' => $multiplier - 1,
                    'min_professionals' => (int)$level['team_min'],
                    'max_professionals' => (int)$level['team_max'],
                ];
            }, $levels);

            return new WP_REST_Response([
                'success' => true,
                'data' => $formatted,
                'count' => count($formatted),
                'source' => 'execution_levels',
            ], 200);

        } catch (\Exception $e) {
            error_log('[ExecutionLevelController] listExecutionLevels error: ' . $e->getMessage());
            return new WP_Error('limpvix_execution_levels_error', 'Erro ao listar niveis de execucao', ['status' => 500]);
        }
    }

    /**
     * POST /limpvix/v1/briefing/{uuid}/execution-level
     */
    public function selectExecutionLevel(WP_REST_Request $request)
    {
        $briefingUuid = $request->get_param('uuid');
        $executionLevel = sanitize_text_field($request->get_param('execution_level'));

        if (empty($executionLevel)) {
            return new WP_Error('missing_execution_level', 'Nivel de execucao nao fornecido', ['status' => 400]);
        }

        // Map execution_level slug to legacy package_type for SelectPackage use case
        $legacyMap = [
            'basic_execution' => 'basic',
            'standard_execution' => 'standard',
            'premium_execution' => 'premium',
        ];

        $packageType = $legacyMap[$executionLevel] ?? $executionLevel;

        // Delegate to SelectPackage use case (backward compat)
        $result = $this->selectPackageUseCase->execute($briefingUuid, $packageType);

        if (!$result['success']) {
            return new WP_Error('execution_level_selection_failed', $result['message'], ['status' => 400]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => $result['message'],
            'data' => array_merge($result['data'], [
                'execution_level' => $executionLevel,
            ]),
        ], 200);
    }

    public function checkPermission(WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_forbidden', 'Voce precisa estar logado', ['status' => 401]);
        }
        return true;
    }

    /**
     * Fallback: list legacy package_configs as execution levels
     */
    private function listLegacyPackages(): WP_REST_Response
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'limpvix_package_configs';

        $packages = $wpdb->get_results(
            "SELECT package_type, display_name, description, percentage_increase, min_professionals, max_professionals
             FROM {$tableName} WHERE is_active = 1 ORDER BY percentage_increase ASC",
            ARRAY_A
        );

        $formatted = array_map(function ($p) {
            $multiplier = 1 + (float)$p['percentage_increase'];
            return [
                'slug' => $p['package_type'],
                'name' => $p['display_name'],
                'description' => $p['description'],
                'price_multiplier' => $multiplier,
                'percentage_display' => '+' . number_format((float)$p['percentage_increase'] * 100, 0) . '%',
                'team_min' => (int)$p['min_professionals'],
                'team_max' => (int)$p['max_professionals'],
                'checklist_level' => 'basic',
                'warranty_hours' => 0,
                'type' => $p['package_type'],
                'percentage_increase' => (float)$p['percentage_increase'],
                'min_professionals' => (int)$p['min_professionals'],
                'max_professionals' => (int)$p['max_professionals'],
            ];
        }, $packages);

        return new WP_REST_Response([
            'success' => true,
            'data' => $formatted,
            'count' => count($formatted),
            'source' => 'package_configs_legacy',
        ], 200);
    }
}
