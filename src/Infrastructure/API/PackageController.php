<?php
/**
 * PackageController - REST API Controller para Pacotes
 *
 * RESPONSABILIDADE:
 * - Endpoint para listar pacotes ativos
 * - Endpoint para selecionar pacote em um briefing
 * - Validação de permissões e dados
 *
 * ROTAS:
 * - GET  /limpvix/v1/packages (lista pacotes ativos)
 * - POST /limpvix/v1/briefing/{uuid}/package (seleciona pacote)
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.1.10
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\UseCases\Briefing\SelectPackage;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined('ABSPATH') || exit;

class PackageController
{
    private const NAMESPACE = 'limpvix/v1';

    /**
     * @var SelectPackage
     */
    private $selectPackageUseCase;

    /**
     * Construtor
     *
     * @param SelectPackage $selectPackageUseCase
     */
    public function __construct(SelectPackage $selectPackageUseCase)
    {
        $this->selectPackageUseCase = $selectPackageUseCase;
    }

    /**
     * Registrar rotas no WordPress REST API
     *
     * @return void
     */
    public function register(): void
    {
        // GET /limpvix/v1/packages - Listar pacotes ativos
        register_rest_route(self::NAMESPACE, '/packages', [
            'methods' => 'GET',
            'callback' => [$this, 'listPackages'],
            'permission_callback' => '__return_true', // Público
        ]);

        // POST /limpvix/v1/briefing/(?P<uuid>[a-f0-9\-]+)/package - Selecionar pacote
        register_rest_route(self::NAMESPACE, '/briefing/(?P<uuid>[a-f0-9\-]+)/package', [
            'methods' => 'POST',
            'callback' => [$this, 'selectPackage'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'uuid' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'UUID do Briefing',
                    'validate_callback' => function($param) {
                        return preg_match('/^[a-f0-9\-]{36}$/', $param);
                    }
                ],
                'package_type' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Tipo de pacote (basic, standard, premium, etc)',
                    'validate_callback' => function($param) {
                        return !empty($param) && strlen($param) <= 50;
                    }
                ]
            ]
        ]);
    }

    /**
     * GET /limpvix/v1/packages
     *
     * Lista todos os pacotes ativos ordenados por percentual
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function listPackages(WP_REST_Request $request)
    {
        global $wpdb;

        try {
            $tableName = $wpdb->prefix . 'limpvix_package_configs';

            // Buscar pacotes ativos
            $packages = $wpdb->get_results(
                "SELECT
                    package_type,
                    display_name,
                    description,
                    percentage_increase,
                    min_professionals,
                    max_professionals,
                    required_skills
                FROM {$tableName}
                WHERE is_active = 1
                ORDER BY percentage_increase ASC",
                ARRAY_A
            );

            // Formatar resposta
            $formatted = array_map(function($package) {
                return [
                    'type' => $package['package_type'],
                    'name' => $package['display_name'],
                    'description' => $package['description'],
                    'percentage_increase' => (float)$package['percentage_increase'],
                    'percentage_display' => number_format((float)$package['percentage_increase'] * 100, 1) . '%',
                    'min_professionals' => (int)$package['min_professionals'],
                    'max_professionals' => (int)$package['max_professionals'],
                    'required_skills' => json_decode($package['required_skills'], true) ?: []
                ];
            }, $packages);

            return new WP_REST_Response([
                'success' => true,
                'data' => $formatted,
                'count' => count($formatted)
            ], 200);

        } catch (\Exception $e) {
            error_log('[PackageController] listPackages error: ' . $e->getMessage());

            return new WP_Error(
                'limpvix_packages_list_error',
                'Erro ao listar pacotes',
                ['status' => 500]
            );
        }
    }

    /**
     * POST /limpvix/v1/briefing/{uuid}/package
     *
     * Seleciona um pacote para o briefing
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function selectPackage(WP_REST_Request $request)
    {
        $briefingUuid = $request->get_param('uuid');
        $packageType = sanitize_text_field($request->get_param('package_type'));

        if (empty($packageType)) {
            return new WP_Error(
                'missing_package_type',
                'Tipo de pacote não fornecido',
                ['status' => 400]
            );
        }

        // Executar Use Case
        $result = $this->selectPackageUseCase->execute($briefingUuid, $packageType);

        if (!$result['success']) {
            return new WP_Error(
                'package_selection_failed',
                $result['message'],
                ['status' => 400]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => $result['message'],
            'data' => $result['data']
        ], 200);
    }

    /**
     * Verificar permissão para selecionar pacote
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function checkPermission(WP_REST_Request $request)
    {
        // Usuário deve estar logado
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_forbidden',
                'Você precisa estar logado para selecionar um pacote',
                ['status' => 401]
            );
        }

        // TODO: Verificar se usuário é owner do briefing ou admin
        // Por enquanto, permitir qualquer usuário logado

        return true;
    }
}
