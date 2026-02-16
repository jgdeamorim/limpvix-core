<?php
/**
 * CEP Controller - REST API
 *
 * Endpoint para consulta de CEP via ViaCEP
 * 
 * Endpoints:
 * - GET /limpvix/v1/cep/{cep} - Consulta CEP (8 dígitos sem formatação)
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.10.0
 */

namespace LimpVix\Infrastructure\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

class CepController extends WP_REST_Controller
{
    private const VIACEP_URL = 'https://viacep.com.br/ws/{cep}/json/';
    private const CACHE_EXPIRY = DAY_IN_SECONDS; // 24 horas

    public function __construct()
    {
        $this->namespace = 'limpvix/v1';
        $this->rest_base = 'cep';
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<cep>[0-9]{8})', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_cep'],
                'permission_callback' => '__return_true',
                'args' => [
                    'cep' => [
                        'required' => true,
                        'validate_callback' => [$this, 'validate_cep'],
                        'sanitize_callback' => [$this, 'sanitize_cep'],
                    ],
                ],
            ],
        ]);
    }

    public function validate_cep($param): bool
    {
        return preg_match('/^[0-9]{8}$/', $param);
    }

    public function sanitize_cep($param): string
    {
        return preg_replace('/[^0-9]/', '', $param);
    }

    public function get_cep(WP_REST_Request $request): WP_REST_Response
    {
        $cep = $request['cep'];

        // Check cache
        $cache_key = 'limpvix_cep_' . $cep;
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return new WP_REST_Response([
                'success' => true,
                'data' => $cached,
                'cached' => true,
            ], 200);
        }

        // Query ViaCEP
        $url = str_replace('{cep}', $cep, self::VIACEP_URL);
        $response = wp_remote_get($url, [
            'timeout' => 5,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao consultar CEP: ' . $response->get_error_message(),
            ], 500);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['erro']) && $data['erro'] === true) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'CEP não encontrado',
            ], 404);
        }

        // Normalize data
        $normalized = [
            'cep' => $data['cep'] ?? '',
            'logradouro' => $data['logradouro'] ?? '',
            'bairro' => $data['bairro'] ?? '',
            'localidade' => $data['localidade'] ?? '',
            'uf' => $data['uf'] ?? '',
        ];

        // Cache for 24 hours
        set_transient($cache_key, $normalized, self::CACHE_EXPIRY);

        return new WP_REST_Response([
            'success' => true,
            'data' => $normalized,
            'cached' => false,
        ], 200);
    }
}
