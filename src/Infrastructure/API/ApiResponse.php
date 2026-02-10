<?php
/**
 * ApiResponse - Static helper for standardized API responses
 *
 * RESPONSABILIDADE:
 * - Padronizar formato de respostas REST API
 * - Facilitar criação de responses (success/error)
 * - Garantir consistência em todos endpoints
 *
 * FORMATO PADRÃO:
 * {
 *   "success": true|false,
 *   "data": {...},      // opcional, apenas em success
 *   "message": "...",   // opcional
 *   "error": "...",     // opcional, apenas em error
 *   "code": "...",      // opcional, erro code
 *   "errors": [...]     // opcional, validation errors
 * }
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.10.0
 */

namespace LimpVix\Infrastructure\API;

use WP_REST_Response;

defined('ABSPATH') || exit;

final class ApiResponse
{
    /**
     * Create success response
     *
     * @param mixed $data Response data (optional)
     * @param string|null $message Success message (optional)
     * @param int $status HTTP status code (default: 200)
     * @return WP_REST_Response
     */
    public static function success(mixed $data = null, string $message = null, int $status = 200): WP_REST_Response
    {
        $response = ['success' => true];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($message !== null) {
            $response['message'] = $message;
        }

        return new WP_REST_Response($response, $status);
    }

    /**
     * Create error response
     *
     * @param string $message Error message
     * @param string|null $code Error code (optional)
     * @param int $status HTTP status code (default: 500)
     * @return WP_REST_Response
     */
    public static function error(string $message, string $code = null, int $status = 500): WP_REST_Response
    {
        $response = [
            'success' => false,
            'error' => $message
        ];

        if ($code !== null) {
            $response['code'] = $code;
        }

        return new WP_REST_Response($response, $status);
    }

    /**
     * Create validation error response
     *
     * @param array $errors Array of validation error messages
     * @param int $status HTTP status code (default: 400)
     * @return WP_REST_Response
     */
    public static function validationError(array $errors, int $status = 400): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Validation failed',
            'errors' => $errors
        ], $status);
    }

    /**
     * Create not found response
     *
     * @param string $message Not found message (default: 'Resource not found')
     * @return WP_REST_Response
     */
    public static function notFound(string $message = 'Resource not found'): WP_REST_Response
    {
        return self::error($message, 'not_found', 404);
    }

    /**
     * Create unauthorized response
     *
     * @param string $message Unauthorized message (default: 'Unauthorized')
     * @return WP_REST_Response
     */
    public static function unauthorized(string $message = 'Unauthorized'): WP_REST_Response
    {
        return self::error($message, 'unauthorized', 403);
    }

    /**
     * Create forbidden response
     *
     * @param string $message Forbidden message (default: 'Forbidden')
     * @return WP_REST_Response
     */
    public static function forbidden(string $message = 'Forbidden'): WP_REST_Response
    {
        return self::error($message, 'forbidden', 403);
    }

    /**
     * Create bad request response
     *
     * @param string $message Bad request message
     * @return WP_REST_Response
     */
    public static function badRequest(string $message): WP_REST_Response
    {
        return self::error($message, 'bad_request', 400);
    }

    /**
     * Create deprecated endpoint response
     *
     * @param string $newEndpoint New endpoint URL
     * @return WP_REST_Response
     */
    public static function deprecated(string $newEndpoint): WP_REST_Response
    {
        return self::error(
            "This endpoint is deprecated. Use {$newEndpoint} instead",
            'deprecated',
            410
        );
    }
}
