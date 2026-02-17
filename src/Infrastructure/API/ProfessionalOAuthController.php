<?php
namespace LimpVix\Infrastructure\API;

defined('ABSPATH') || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Professional OAuth Controller
 *
 * REST API endpoints para profissionais conectarem suas contas MercadoPago via OAuth
 *
 * @package LimpVix\Infrastructure\API
 */
final class ProfessionalOAuthController extends WP_REST_Controller
{
    /**
     * Namespace da API
     */
    private const NAMESPACE = 'limpvix/v1';

    /**
     * Register routes
     */
    public function register_routes(): void
    {
        // GET /limpvix/v1/professionals/{id}/mercadopago/connect
        // Retorna authorization URL para profissional conectar MercadoPago
        register_rest_route(self::NAMESPACE, '/professionals/(?P<id>\d+)/mercadopago/connect', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getMercadoPagoConnectUrl'],
                'permission_callback' => [$this, 'canManageProfessional'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        }
                    ],
                ],
            ],
        ]);

        // GET /limpvix/v1/oauth/mercadopago/callback
        // Recebe callback OAuth do MercadoPago
        register_rest_route(self::NAMESPACE, '/oauth/mercadopago/callback', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'handleMercadoPagoCallback'],
                'permission_callback' => '__return_true', // Public endpoint
                'args' => [
                    'code' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'state' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                ],
            ],
        ]);

        // POST /limpvix/v1/professionals/{id}/mercadopago/disconnect
        // Desconecta MercadoPago OAuth do profissional
        register_rest_route(self::NAMESPACE, '/professionals/(?P<id>\d+)/mercadopago/disconnect', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'disconnectMercadoPago'],
                'permission_callback' => [$this, 'canManageProfessional'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        // GET /limpvix/v1/professionals/{id}/payout-method
        // Retorna método de payout atual do profissional
        register_rest_route(self::NAMESPACE, '/professionals/(?P<id>\d+)/payout-method', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getPayoutMethod'],
                'permission_callback' => [$this, 'canManageProfessional'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        // PUT /limpvix/v1/professionals/{id}/payout-method
        // Altera método de payout do profissional
        register_rest_route(self::NAMESPACE, '/professionals/(?P<id>\d+)/payout-method', [
            [
                'methods' => 'PUT',
                'callback' => [$this, 'updatePayoutMethod'],
                'permission_callback' => [$this, 'canManageProfessional'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                    'payout_method' => [
                        'required' => true,
                        'type' => 'string',
                        'enum' => ['mp_oauth', 'pix_manual'],
                    ],
                    'pix_key' => [
                        'required' => false,
                        'type' => 'string',
                    ],
                    'pix_key_type' => [
                        'required' => false,
                        'type' => 'string',
                        'enum' => ['cpf', 'cnpj', 'email', 'phone', 'random'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Get MercadoPago authorization URL
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function getMercadoPagoConnectUrl(WP_REST_Request $request)
    {
        $professionalId = (int) $request['id'];

        // Verificar se credenciais OAuth estão configuradas
        $clientId = get_option('limpvix_mercadopago_client_id', '');
        $clientSecret = get_option('limpvix_mercadopago_client_secret', '');

        if (empty($clientId) || empty($clientSecret)) {
            return new WP_Error(
                'oauth_not_configured',
                'MercadoPago OAuth não está configurado. Configure Client ID e Client Secret em Configurações > Conexões.',
                ['status' => 400]
            );
        }

        // Gerar state para CSRF protection
        $state = $this->generateState($professionalId);

        // Salvar state temporariamente (15min expiry)
        set_transient("limpvix_mp_oauth_state_{$professionalId}", $state, 900);

        // Gerar authorization URL
        $redirectUri = rest_url(self::NAMESPACE . '/oauth/mercadopago/callback');

        $authUrl = 'https://auth.mercadopago.com.br/authorization?' . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'platform_id' => 'mp',
            'state' => $state,
            'redirect_uri' => $redirectUri,
        ]);

        return new WP_REST_Response([
            'authorization_url' => $authUrl,
            'state' => $state,
        ], 200);
    }

    /**
     * Handle MercadoPago OAuth callback
     *
     * @param WP_REST_Request $request
     * @return void (redirects)
     */
    public function handleMercadoPagoCallback(WP_REST_Request $request)
    {
        $code = $request['code'];
        $state = $request['state'];

        try {
            // Extrair professional_id do state
            $professionalId = $this->extractProfessionalIdFromState($state);

            if (!$professionalId) {
                throw new \Exception('Invalid state parameter');
            }

            // Verificar state (CSRF protection)
            $savedState = get_transient("limpvix_mp_oauth_state_{$professionalId}");

            if ($savedState !== $state) {
                throw new \Exception('Invalid state - possible CSRF attack');
            }

            // Limpar state usado
            delete_transient("limpvix_mp_oauth_state_{$professionalId}");

            // Trocar code por token
            $tokenData = $this->exchangeCodeForToken($code);

            // Salvar token no profissional
            $this->saveProfessionalOAuthToken($professionalId, $tokenData);

            // Redirecionar para sucesso
            $successUrl = home_url('/professional/mercadopago/connected?success=true');
            wp_redirect($successUrl);
            exit;

        } catch (\Exception $e) {
            // Redirecionar para erro
            $errorUrl = home_url('/professional/mercadopago/connected?success=false&error=' . urlencode($e->getMessage()));
            wp_redirect($errorUrl);
            exit;
        }
    }

    /**
     * Disconnect MercadoPago OAuth
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function disconnectMercadoPago(WP_REST_Request $request)
    {
        global $wpdb;

        $professionalId = (int) $request['id'];
        $table = $wpdb->prefix . 'limpvix_professionals';

        // Limpar dados OAuth
        $updated = $wpdb->update(
            $table,
            [
                'mp_access_token' => null,
                'mp_refresh_token' => null,
                'mp_user_id' => null,
                'mp_public_key' => null,
                'mp_oauth_connected_at' => null,
                'mp_oauth_expires_at' => null,
                'mp_oauth_status' => 'not_connected',
                // Se estava usando MP OAuth, voltar para PIX Manual
                'preferred_payout_method' => 'pix_manual',
            ],
            ['id' => $professionalId],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error(
                'disconnect_failed',
                'Falha ao desconectar MercadoPago',
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'MercadoPago desconectado com sucesso',
        ], 200);
    }

    /**
     * Get current payout method
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function getPayoutMethod(WP_REST_Request $request)
    {
        global $wpdb;

        $professionalId = (int) $request['id'];
        $table = $wpdb->prefix . 'limpvix_professionals';

        $professional = $wpdb->get_row($wpdb->prepare(
            "SELECT preferred_payout_method, mp_oauth_status, pix_key, pix_key_type
             FROM {$table}
             WHERE id = %d",
            $professionalId
        ));

        if (!$professional) {
            return new WP_Error(
                'professional_not_found',
                'Profissional não encontrado',
                ['status' => 404]
            );
        }

        return new WP_REST_Response([
            'payout_method' => $professional->preferred_payout_method ?? 'pix_manual',
            'mp_oauth_status' => $professional->mp_oauth_status ?? 'not_connected',
            'pix_key' => $professional->pix_key,
            'pix_key_type' => $professional->pix_key_type,
            'is_connected' => $professional->mp_oauth_status === 'connected',
        ], 200);
    }

    /**
     * Update payout method
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function updatePayoutMethod(WP_REST_Request $request)
    {
        global $wpdb;

        $professionalId = (int) $request['id'];
        $payoutMethod = $request['payout_method'];
        $table = $wpdb->prefix . 'limpvix_professionals';

        $updateData = [
            'preferred_payout_method' => $payoutMethod,
        ];

        // Se for PIX Manual, salvar chave PIX
        if ($payoutMethod === 'pix_manual') {
            $pixKey = $request->get_param('pix_key');
            $pixKeyType = $request->get_param('pix_key_type');

            if (empty($pixKey) || empty($pixKeyType)) {
                return new WP_Error(
                    'pix_key_required',
                    'Chave PIX e tipo são obrigatórios para PIX Manual',
                    ['status' => 400]
                );
            }

            $updateData['pix_key'] = $pixKey;
            $updateData['pix_key_type'] = $pixKeyType;
        }

        // Se for MP OAuth, verificar se está conectado
        if ($payoutMethod === 'mp_oauth') {
            $mpStatus = $wpdb->get_var($wpdb->prepare(
                "SELECT mp_oauth_status FROM {$table} WHERE id = %d",
                $professionalId
            ));

            if ($mpStatus !== 'connected') {
                return new WP_Error(
                    'mp_not_connected',
                    'MercadoPago não está conectado. Conecte primeiro.',
                    ['status' => 400]
                );
            }

            // Verificar se precisa aprovação admin (mudança PIX→MP)
            $requiresApproval = get_option('limpvix_payout_pix_to_mp_requires_approval', true);

            if ($requiresApproval) {
                $updateData['payout_method_change_requested_at'] = current_time('mysql');
                // Admin precisa aprovar a mudança
            }
        }

        $updated = $wpdb->update(
            $table,
            $updateData,
            ['id' => $professionalId],
            array_fill(0, count($updateData), '%s'),
            ['%d']
        );

        if ($updated === false) {
            return new WP_Error(
                'update_failed',
                'Falha ao atualizar método de payout',
                ['status' => 500]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Método de payout atualizado com sucesso',
            'payout_method' => $payoutMethod,
        ], 200);
    }

    /**
     * Check if current user can manage professional
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function canManageProfessional(WP_REST_Request $request): bool
    {
        // Admin pode gerenciar qualquer profissional
        if (current_user_can('manage_options')) {
            return true;
        }

        // Profissional pode gerenciar apenas a própria conta
        $professionalId = (int) $request['id'];
        $currentUserId = get_current_user_id();

        // TODO: Implementar lógica para verificar se user_id corresponde ao profissional
        // Por enquanto, apenas admin
        return current_user_can('manage_options');
    }

    /**
     * Generate secure state for CSRF protection
     */
    private function generateState(int $professionalId): string
    {
        // Encode professional ID no state para recuperar depois
        return base64_encode(json_encode([
            'professional_id' => $professionalId,
            'timestamp' => time(),
            'nonce' => wp_generate_password(32, true, true),
        ]));
    }

    /**
     * Extract professional ID from state
     */
    private function extractProfessionalIdFromState(string $state): ?int
    {
        $decoded = json_decode(base64_decode($state), true);

        if (!$decoded || !isset($decoded['professional_id'])) {
            return null;
        }

        return (int) $decoded['professional_id'];
    }

    /**
     * Exchange authorization code for access token
     */
    private function exchangeCodeForToken(string $code): array
    {
        $clientId = get_option('limpvix_mercadopago_client_id');
        $clientSecret = get_option('limpvix_mercadopago_client_secret');
        $redirectUri = rest_url(self::NAMESPACE . '/oauth/mercadopago/callback');

        $response = wp_remote_post('https://api.mercadopago.com/oauth/token', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('Failed to exchange code for token: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            throw new \Exception('MercadoPago token exchange error: ' . ($body['message'] ?? 'Unknown error'));
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'],
            'expires_in' => $body['expires_in'] ?? 15552000, // 180 dias
            'user_id' => $body['user_id'],
            'public_key' => $body['public_key'] ?? null,
        ];
    }

    /**
     * Save OAuth token to professional
     */
    private function saveProfessionalOAuthToken(int $professionalId, array $tokenData): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professionals';

        // Calcular data de expiração
        $expiresAt = date('Y-m-d H:i:s', time() + $tokenData['expires_in']);

        $wpdb->update(
            $table,
            [
                'mp_access_token' => $tokenData['access_token'], // TODO: Criptografar
                'mp_refresh_token' => $tokenData['refresh_token'], // TODO: Criptografar
                'mp_user_id' => $tokenData['user_id'],
                'mp_public_key' => $tokenData['public_key'],
                'mp_oauth_connected_at' => current_time('mysql'),
                'mp_oauth_expires_at' => $expiresAt,
                'mp_oauth_status' => 'connected',
            ],
            ['id' => $professionalId],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }
}
