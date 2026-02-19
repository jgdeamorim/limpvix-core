<?php
/**
 * FrontendGuards - Guardas de Segurança no Frontend
 *
 * RESPONSABILIDADE:
 * - Prevenir execução indevida no frontend
 * - Validar requisições suspeitas
 * - Rate limiting
 * - Honeypot para bots
 *
 * PRINCÍPIOS:
 * - Segurança em profundidade
 * - Falhar de forma segura (deny by default)
 * - Não vazar informações
 * - Log de tentativas suspeitas
 *
 * USO:
 * - Chamado ANTES de processar booking
 * - Protege contra spam/bots
 * - Limita taxa de requests
 *
 * @package LimpVix\Frontend
 */

namespace LimpVix\Frontend;

defined('ABSPATH') || exit;

class FrontendGuards
{
    /**
     * Limite de requests por IP (por hora)
     *
     * @var int
     */
    private const RATE_LIMIT_PER_HOUR = 10;

    /**
     * Verifica todas as guardas antes de permitir booking
     *
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    public static function checkBeforeBooking(): array
    {
        // 1. Verificar rate limit
        if (!self::checkRateLimit()) {
            return [
                'allowed' => false,
                'reason' => 'Muitas tentativas. Aguarde alguns minutos.'
            ];
        }

        // 2. Verificar honeypot (campo oculto para pegar bots)
        if (!self::checkHoneypot()) {
            self::logSuspicious('HONEYPOT_TRIGGERED');
            return [
                'allowed' => false,
                'reason' => 'Requisição inválida.'
            ];
        }

        // 3. Verificar nonce (CSRF protection)
        if (!self::checkNonce()) {
            return [
                'allowed' => false,
                'reason' => 'Token de segurança inválido.'
            ];
        }

        // Tudo OK
        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Verifica rate limit por IP
     *
     * @return bool
     */
    private static function checkRateLimit(): bool
    {
        $ip = self::getClientIp();
        $transientKey = 'limpvix_rate_limit_' . md5($ip);

        $attempts = get_transient($transientKey);

        if ($attempts === false) {
            // Primeira tentativa
            set_transient($transientKey, 1, HOUR_IN_SECONDS);
            return true;
        }

        if ($attempts >= self::RATE_LIMIT_PER_HOUR) {
            self::logSuspicious('RATE_LIMIT_EXCEEDED', ['ip' => $ip]);
            return false;
        }

        // Incrementar contador
        set_transient($transientKey, $attempts + 1, HOUR_IN_SECONDS);

        return true;
    }

    /**
     * Verifica honeypot field
     *
     * Campo oculto via CSS que humanos não preenchem
     * mas bots sim
     *
     * @return bool
     */
    private static function checkHoneypot(): bool
    {
        // Hidden field "website" — humans leave it empty, bots fill it in
        // Only check if the field is present in the POST (form submitted)
        if (isset($_POST['website']) && !empty($_POST['website'])) {
            return false; // Bot detected
        }

        // Hidden field "limpvix_hp" with CSS display:none
        if (isset($_POST['limpvix_hp']) && !empty($_POST['limpvix_hp'])) {
            return false; // Bot detected
        }

        return true;
    }

    /**
     * Verifica WordPress nonce
     *
     * @return bool
     */
    private static function checkNonce(): bool
    {
        if (!isset($_POST['limpvix_nonce'])) {
            return false;
        }

        return wp_verify_nonce($_POST['limpvix_nonce'], 'limpvix_booking_action');
    }

    /**
     * Obtém IP real do cliente (considerando proxies)
     *
     * @return string
     */
    private static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Proxy padrão
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];

                // Se for lista de IPs (X-Forwarded-For), pegar primeiro
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }

                // Validar IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Registra atividade suspeita
     *
     * @param string $type Tipo de suspeita
     * @param array $context Contexto adicional
     * @return void
     */
    private static function logSuspicious(string $type, array $context = []): void
    {
        $data = array_merge([
            'type' => $type,
            'ip' => self::getClientIp(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => current_time('mysql')
        ], $context);

        error_log('[LimpVix Security] ' . json_encode($data, JSON_UNESCAPED_UNICODE));

        // Persist to audit table
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_security_audit';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $wpdb->insert($table, [
                'event_type' => $type,
                'ip_address' => self::getClientIp(),
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500),
                'context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                'created_at' => current_time('mysql'),
            ], ['%s', '%s', '%s', '%s', '%s']);
        }
    }
}
