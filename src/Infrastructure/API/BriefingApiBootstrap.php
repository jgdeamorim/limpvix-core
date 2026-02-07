<?php
/**
 * BriefingApiBootstrap - Bootstrap REST API
 *
 * RESPONSABILIDADE:
 * - Registrar todas as rotas REST API do módulo Briefing
 * - Inicializar controllers com dependências
 * - Hook: rest_api_init
 * - Namespace: limpvix/v1
 *
 * ROTAS REGISTRADAS:
 * - GET    /limpvix/v1/briefing/schema
 * - POST   /limpvix/v1/briefing
 * - GET    /limpvix/v1/briefing/{uuid}
 * - POST   /limpvix/v1/briefing/{uuid}/step
 * - POST   /limpvix/v1/briefing/{uuid}/verify-phone
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\UseCases\Briefing\CreateBriefing;
use LimpVix\Application\UseCases\Briefing\UpdateBriefingStep;
use LimpVix\Application\UseCases\Briefing\VerifyBriefingPhone;
use LimpVix\Application\UseCases\Briefing\GetBriefingSchema;
use LimpVix\Application\Services\BriefingMetricsCalculator;
use LimpVix\Infrastructure\Persistence\WpBriefingRepository;
use LimpVix\Infrastructure\Adapters\FirebaseAuthAdapter;
use LimpVix\Domain\Briefing\BriefingPolicy;

defined('ABSPATH') || exit;

class BriefingApiBootstrap
{
    /**
     * @var bool Flag para evitar registro duplo
     */
    private static $registered = false;

    /**
     * Inicializar Bootstrap
     *
     * Registra hook rest_api_init para registrar rotas.
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$registered) {
            return;
        }

        add_action('rest_api_init', [__CLASS__, 'registerRoutes']);
        self::$registered = true;
    }

    /**
     * Registrar todas as rotas REST API
     *
     * Hook: rest_api_init
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        // 1. Inicializar dependências compartilhadas
        $repository = new WpBriefingRepository();
        $policy = new BriefingPolicy();
        $calculator = new BriefingMetricsCalculator();
        $firebaseAdapter = new FirebaseAuthAdapter();

        // 2. Inicializar Use Cases
        $createBriefingUseCase = new CreateBriefing($repository, $calculator);
        $updateBriefingStepUseCase = new UpdateBriefingStep($repository, $policy, $calculator);
        $verifyBriefingPhoneUseCase = new VerifyBriefingPhone($repository, $policy, $firebaseAdapter);
        $getBriefingSchemaUseCase = new GetBriefingSchema();

        // 3. Inicializar Controllers
        $schemaController = new BriefingSchemaController($getBriefingSchemaUseCase);
        $briefingController = new BriefingController($createBriefingUseCase, $repository);
        $stepController = new BriefingStepController($updateBriefingStepUseCase, $repository);
        $phoneController = new BriefingPhoneController($verifyBriefingPhoneUseCase, $repository);

        // 4. Registrar rotas dos controllers
        $schemaController->register();
        $briefingController->register();
        $stepController->register();
        $phoneController->register();

        // 5. Log (debug)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix] REST API Briefing registrada: /limpvix/v1/briefing/*');
        }
    }

    /**
     * Obter lista de rotas registradas (para documentação)
     *
     * @return array
     */
    public static function getRoutes(): array
    {
        return [
            'schema' => [
                'method' => 'GET',
                'path' => '/wp-json/limpvix/v1/briefing/schema',
                'description' => 'Obter schema dinâmico dos steps',
                'params' => ['property_type' => 'residential|commercial'],
                'auth' => 'public'
            ],
            'create' => [
                'method' => 'POST',
                'path' => '/wp-json/limpvix/v1/briefing',
                'description' => 'Criar novo Briefing',
                'params' => ['property_type' => 'residential|commercial', 'user_id' => 'int (opcional)'],
                'auth' => 'required'
            ],
            'get' => [
                'method' => 'GET',
                'path' => '/wp-json/limpvix/v1/briefing/{uuid}',
                'description' => 'Buscar Briefing por UUID',
                'params' => ['uuid' => 'string (UUID)'],
                'auth' => 'required (owner ou admin)'
            ],
            'update_step' => [
                'method' => 'POST',
                'path' => '/wp-json/limpvix/v1/briefing/{uuid}/step',
                'description' => 'Atualizar step individual',
                'params' => ['uuid' => 'string', 'step_name' => 'string', 'step_data' => 'object'],
                'auth' => 'required (owner ou admin)'
            ],
            'verify_phone' => [
                'method' => 'POST',
                'path' => '/wp-json/limpvix/v1/briefing/{uuid}/verify-phone',
                'description' => 'Verificar telefone via Firebase OTP',
                'params' => ['uuid' => 'string', 'id_token' => 'string (Firebase JWT)'],
                'auth' => 'required (owner ou admin)'
            ]
        ];
    }

    /**
     * Gerar documentação OpenAPI/Swagger (opcional)
     *
     * Retorna schema OpenAPI 3.0 das rotas Briefing.
     *
     * @return array
     */
    public static function getOpenApiSchema(): array
    {
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'LimpVix Briefing API',
                'version' => '0.2.0',
                'description' => 'REST API para módulo Briefing do LimpVix Core'
            ],
            'servers' => [
                ['url' => home_url('/wp-json')]
            ],
            'paths' => [
                '/limpvix/v1/briefing/schema' => [
                    'get' => [
                        'summary' => 'Obter schema dinâmico dos steps',
                        'parameters' => [
                            [
                                'name' => 'property_type',
                                'in' => 'query',
                                'required' => true,
                                'schema' => ['type' => 'string', 'enum' => ['residential', 'commercial']]
                            ]
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Schema retornado com sucesso',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'success' => ['type' => 'boolean'],
                                                'data' => ['type' => 'object']
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                '/limpvix/v1/briefing' => [
                    'post' => [
                        'summary' => 'Criar novo Briefing',
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['property_type'],
                                        'properties' => [
                                            'property_type' => ['type' => 'string', 'enum' => ['residential', 'commercial']],
                                            'user_id' => ['type' => 'integer']
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'responses' => [
                            '201' => ['description' => 'Briefing criado'],
                            '400' => ['description' => 'Dados inválidos'],
                            '401' => ['description' => 'Não autenticado']
                        ]
                    ]
                ],
                '/limpvix/v1/briefing/{uuid}' => [
                    'get' => [
                        'summary' => 'Buscar Briefing por UUID',
                        'parameters' => [
                            [
                                'name' => 'uuid',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string', 'format' => 'uuid']
                            ]
                        ],
                        'responses' => [
                            '200' => ['description' => 'Briefing encontrado'],
                            '404' => ['description' => 'Não encontrado'],
                            '403' => ['description' => 'Acesso negado']
                        ]
                    ]
                ]
                // ... outros endpoints
            ]
        ];
    }

    /**
     * Desregistrar rotas (útil para testes)
     *
     * @return void
     */
    public static function unregister(): void
    {
        remove_action('rest_api_init', [__CLASS__, 'registerRoutes']);
        self::$registered = false;
    }
}
