<?php
/**
 * BriefingSchemaController - REST API Controller
 *
 * RESPONSABILIDADE:
 * - Endpoint GET /wp-json/limpvix/v1/briefing/schema
 * - Retornar schema dinâmico dos steps do Briefing
 * - Aplicar regras condicionais (residential vs commercial)
 * - Usado pelo frontend para construir stepper
 *
 * ENDPOINT:
 * GET /wp-json/limpvix/v1/briefing/schema?property_type=residential
 *
 * RESPONSE:
 * {
 *   "property_type": "residential",
 *   "version": "1.0",
 *   "steps": [...]
 * }
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\UseCases\Briefing\GetBriefingSchema;

defined('ABSPATH') || exit;

class BriefingSchemaController
{
    /**
     * @var GetBriefingSchema
     */
    private $getBriefingSchemaUseCase;

    /**
     * Construtor
     *
     * @param GetBriefingSchema $getBriefingSchemaUseCase
     */
    public function __construct(GetBriefingSchema $getBriefingSchemaUseCase)
    {
        $this->getBriefingSchemaUseCase = $getBriefingSchemaUseCase;
    }

    /**
     * Registrar rotas
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route('limpvix/v1', '/briefing/schema', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getSchema'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getSchemaArgs()
        ]);
    }

    /**
     * GET /wp-json/limpvix/v1/briefing/schema
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function getSchema(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            // 1. Validar property_type
            $propertyType = $request->get_param('property_type');

            if (!in_array($propertyType, ['residential', 'commercial'], true)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'property_type deve ser "residential" ou "commercial"'
                ], 400);
            }

            // 2. Obter current_step (opcional)
            $currentStep = $request->get_param('current_step');

            // 3. Executar use case
            $schema = $this->getBriefingSchemaUseCase->execute($propertyType, $currentStep);

            // 4. Retornar schema
            return new \WP_REST_Response([
                'success' => true,
                'data' => $schema
            ], 200);

        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao obter schema: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar permissão
     *
     * Schema é público (não requer autenticação), mas pode ser limitado via CORS.
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function checkPermission(\WP_REST_Request $request): bool
    {
        // Schema é público
        return true;
    }

    /**
     * Schema dos argumentos
     *
     * @return array
     */
    private function getSchemaArgs(): array
    {
        return [
            'property_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['residential', 'commercial'],
                'description' => 'Tipo de propriedade: residential ou commercial',
                'validate_callback' => function ($param) {
                    return in_array($param, ['residential', 'commercial'], true);
                }
            ],
            'current_step' => [
                'required' => false,
                'type' => 'string',
                'description' => 'Step atual (opcional, para validações contextuais)',
                'validate_callback' => function ($param) {
                    return is_string($param) && strlen($param) > 0;
                }
            ]
        ];
    }
}
