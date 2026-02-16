<?php
/**
 * Environment - Gerenciador Centralizado de Variáveis de Ambiente
 *
 * RESPONSABILIDADES:
 * - Carregar variáveis do arquivo .env
 * - Fornecer acesso type-safe às configurações
 * - Fallback para constantes PHP e wp_options
 * - Validação de variáveis obrigatórias
 * - Cache de valores para performance
 *
 * HIERARQUIA DE BUSCA (Priority Order):
 * 1. .env file (highest priority)
 * 2. PHP Constants (LIMPVIX_*)
 * 3. wp_options (fallback - backward compatibility)
 * 4. Default values (if provided)
 *
 * MOTIVAÇÃO (SPRINT 7 - Item 1.10):
 * - Separar configuração de dev/staging/production
 * - Remover credenciais hardcoded do código
 * - Permitir deploy seguro sem expor secrets
 * - Facilitar configuração para novos ambientes
 *
 * USO:
 * ```php
 * // Obter valor (exception se não existir)
 * $apiKey = Environment::get('LIMPVIX_MP_ACCESS_TOKEN_PROD');
 *
 * // Obter com default
 * $debug = Environment::get('LIMPVIX_DEBUG', 'false');
 *
 * // Obter como bool
 * $isDebug = Environment::getBool('LIMPVIX_DEBUG');
 *
 * // Obter como int
 * $timeout = Environment::getInt('LIMPVIX_CRON_TIMEOUT', 300);
 *
 * // Verificar se existe
 * if (Environment::has('LIMPVIX_SLACK_WEBHOOK_URL')) {
 *     // ...
 * }
 *
 * // Obter ambiente atual
 * $env = Environment::getEnvironment(); // 'development', 'staging', 'production'
 * $isDev = Environment::isDevelopment();
 * $isProd = Environment::isProduction();
 * ```
 *
 * @package LimpVix\Core
 * @since 0.10.0 (SPRINT 7 - Item 1.10)
 * @author Claude Code
 */

namespace LimpVix\Core;

defined('ABSPATH') || exit;

final class Environment
{
    /**
     * Cache de variáveis carregadas
     *
     * @var array<string, string>
     */
    private static array $cache = [];

    /**
     * Flag indicando se .env foi carregado
     *
     * @var bool
     */
    private static bool $loaded = false;

    /**
     * Path do arquivo .env
     *
     * @var string|null
     */
    private static ?string $envPath = null;

    /**
     * Carregar arquivo .env se existir
     *
     * NOTA: Este método é chamado automaticamente no primeiro acesso
     * Pode ser chamado manualmente se precisar recarregar
     *
     * @param string|null $path Path customizado do .env (opcional)
     * @return void
     */
    public static function load(?string $path = null): void
    {
        if (self::$loaded && $path === null) {
            return; // Já carregado
        }

        // Determinar path do .env
        if ($path !== null) {
            self::$envPath = $path;
        } elseif (self::$envPath === null) {
            // Default: raiz do plugin
            self::$envPath = dirname(__DIR__, 2) . '/.env';
        }

        // Verificar se .env existe
        if (!file_exists(self::$envPath)) {
            self::logDebug('.env file not found: ' . self::$envPath);
            self::$loaded = true;
            return;
        }

        // Parse .env file
        $lines = file(self::$envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            self::logError('Failed to read .env file: ' . self::$envPath);
            self::$loaded = true;
            return;
        }

        $count = 0;
        foreach ($lines as $line) {
            // Skip comments and empty lines
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=VALUE
            if (!str_contains($line, '=')) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes se houver
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Salvar no cache
            if ($key !== '') {
                self::$cache[$key] = $value;
                $count++;
            }
        }

        self::$loaded = true;
        self::logDebug("Loaded {$count} variables from .env");
    }

    /**
     * Obter valor de variável de ambiente
     *
     * @param string $key Nome da variável
     * @param string|null $default Valor padrão se não existir
     * @return string|null Valor ou null se não existe e sem default
     * @throws \RuntimeException Se variável não existe e sem default
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        // Garantir que .env foi carregado
        if (!self::$loaded) {
            self::load();
        }

        // 1. Buscar no cache (.env file)
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        // 2. Buscar em PHP constants
        if (defined($key)) {
            $value = constant($key);
            return is_string($value) ? $value : (string) $value;
        }

        // 3. Buscar em wp_options (backward compatibility)
        // Converter LIMPVIX_MP_ACCESS_TOKEN -> limpvix_mp_access_token
        $optionKey = strtolower($key);
        $optionValue = get_option($optionKey);
        if ($optionValue !== false && $optionValue !== '') {
            return (string) $optionValue;
        }

        // 4. Usar default
        if ($default !== null) {
            return $default;
        }

        // 5. Não encontrado e sem default
        throw new \RuntimeException("Environment variable not found: {$key}");
    }

    /**
     * Verificar se variável existe
     *
     * @param string $key Nome da variável
     * @return bool True se existe
     */
    public static function has(string $key): bool
    {
        try {
            self::get($key);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Obter variável como boolean
     *
     * Converte strings 'true', '1', 'yes', 'on' para true
     * Converte strings 'false', '0', 'no', 'off', '' para false
     *
     * @param string $key Nome da variável
     * @param bool $default Valor padrão
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        try {
            $value = strtolower(self::get($key, $default ? 'true' : 'false'));
        } catch (\RuntimeException $e) {
            return $default;
        }

        return in_array($value, ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Obter variável como integer
     *
     * @param string $key Nome da variável
     * @param int $default Valor padrão
     * @return int
     */
    public static function getInt(string $key, int $default = 0): int
    {
        try {
            $value = self::get($key, (string) $default);
            return (int) $value;
        } catch (\RuntimeException $e) {
            return $default;
        }
    }

    /**
     * Obter variável como float
     *
     * @param string $key Nome da variável
     * @param float $default Valor padrão
     * @return float
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        try {
            $value = self::get($key, (string) $default);
            return (float) $value;
        } catch (\RuntimeException $e) {
            return $default;
        }
    }

    /**
     * Obter variável como array (comma-separated)
     *
     * Exemplo: "value1,value2,value3" -> ['value1', 'value2', 'value3']
     *
     * @param string $key Nome da variável
     * @param array $default Valor padrão
     * @return array
     */
    public static function getArray(string $key, array $default = []): array
    {
        try {
            $value = self::get($key);
            if ($value === null || $value === '') {
                return $default;
            }

            $parts = explode(',', $value);
            return array_map('trim', $parts);
        } catch (\RuntimeException $e) {
            return $default;
        }
    }

    /**
     * Obter ambiente atual
     *
     * @return string 'development', 'staging', ou 'production'
     */
    public static function getEnvironment(): string
    {
        $env = self::get('LIMPVIX_ENV', 'development');
        $env = strtolower(trim($env));

        // Validar valor
        if (!in_array($env, ['development', 'staging', 'production'], true)) {
            self::logError("Invalid LIMPVIX_ENV value: {$env}, falling back to 'development'");
            return 'development';
        }

        return $env;
    }

    /**
     * Verificar se está em desenvolvimento
     *
     * @return bool
     */
    public static function isDevelopment(): bool
    {
        return self::getEnvironment() === 'development';
    }

    /**
     * Verificar se está em staging
     *
     * @return bool
     */
    public static function isStaging(): bool
    {
        return self::getEnvironment() === 'staging';
    }

    /**
     * Verificar se está em produção
     *
     * @return bool
     */
    public static function isProduction(): bool
    {
        return self::getEnvironment() === 'production';
    }

    /**
     * Verificar se debug está ativo
     *
     * @return bool
     */
    public static function isDebug(): bool
    {
        // Se WP_DEBUG está definido, usar ele
        if (defined('WP_DEBUG')) {
            return WP_DEBUG;
        }

        // Senão, usar LIMPVIX_DEBUG
        return self::getBool('LIMPVIX_DEBUG', false);
    }

    /**
     * Obter nível de log
     *
     * @return string 'error', 'warning', 'info', 'debug'
     */
    public static function getLogLevel(): string
    {
        $level = self::get('LIMPVIX_LOG_LEVEL', 'info');
        $level = strtolower(trim($level));

        if (!in_array($level, ['error', 'warning', 'info', 'debug'], true)) {
            return 'info';
        }

        return $level;
    }

    /**
     * Verificar se modo de teste está ativo
     *
     * ATENÇÃO: NUNCA habilitar em produção!
     *
     * @return bool
     */
    public static function isTestMode(): bool
    {
        return self::getBool('LIMPVIX_TEST_MODE', false);
    }

    /**
     * Validar variáveis obrigatórias
     *
     * Lança exception se alguma variável obrigatória não estiver definida
     *
     * @param array $required Lista de variáveis obrigatórias
     * @return void
     * @throws \RuntimeException Se alguma variável obrigatória não existe
     */
    public static function validate(array $required): void
    {
        $missing = [];

        foreach ($required as $key) {
            if (!self::has($key)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Obter todas as variáveis carregadas (debug)
     *
     * ATENÇÃO: Não usar em produção, pode expor secrets!
     *
     * @return array
     */
    public static function all(): array
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::$cache;
    }

    /**
     * Limpar cache de variáveis
     *
     * Útil para testes
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        self::$loaded = false;
    }

    /**
     * Definir variável manualmente (apenas para testes)
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    public static function set(string $key, string $value): void
    {
        self::$cache[$key] = $value;
    }

    /**
     * Log de debug (apenas se WP_DEBUG ativo)
     *
     * @param string $message
     * @return void
     */
    private static function logDebug(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix Environment] ' . $message);
        }
    }

    /**
     * Log de erro
     *
     * @param string $message
     * @return void
     */
    private static function logError(string $message): void
    {
        error_log('[LimpVix Environment] ERROR: ' . $message);
    }
}
