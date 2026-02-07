<?php
/**
 * RetryPolicy - Policy
 *
 * RESPONSABILIDADE:
 * - Definir estratégia de retry para mensagens falhadas
 * - Calcular delay exponencial (exponential backoff)
 * - Determinar se deve fazer retry baseado em tipo de erro
 *
 * ESTRATÉGIA:
 * - Retry 1: 1 minuto
 * - Retry 2: 5 minutos (exponential)
 * - Retry 3: 15 minutos (exponential)
 *
 * ERROS RETRYABLE:
 * - Timeout, network error, rate limit
 *
 * ERROS NÃO RETRYABLE:
 * - Invalid phone, permission denied, template not found
 *
 * @package LimpVix\Domain\Communication
 * @since 0.3.0
 */

namespace LimpVix\Domain\Communication;

defined('ABSPATH') || exit;

class RetryPolicy
{
    /**
     * Erros que permitem retry
     */
    const RETRYABLE_ERRORS = [
        'timeout',
        'network_error',
        'rate_limit',
        'service_unavailable',
        'connection_refused',
    ];

    /**
     * Erros que NÃO permitem retry (permanentes)
     */
    const NON_RETRYABLE_ERRORS = [
        'invalid_phone',
        'permission_denied',
        'template_not_found',
        'insufficient_balance',
        'blocked_recipient',
    ];

    /**
     * Verificar se erro permite retry
     *
     * @param string $errorType Tipo do erro
     * @return bool
     */
    public static function isRetryable(string $errorType): bool
    {
        // Normalizar erro (lowercase, replace spaces)
        $normalizedError = strtolower(str_replace(' ', '_', $errorType));

        // Verificar se é explicitamente não retryable
        if (in_array($normalizedError, self::NON_RETRYABLE_ERRORS, true)) {
            return false;
        }

        // Verificar se é explicitamente retryable
        if (in_array($normalizedError, self::RETRYABLE_ERRORS, true)) {
            return true;
        }

        // Default: não retry se não reconhecido (fail-safe)
        return false;
    }

    /**
     * Calcular delay para próximo retry (exponential backoff)
     *
     * @param int $retryCount Número de tentativas já feitas
     * @return int Delay em segundos
     */
    public static function calculateDelay(int $retryCount): int
    {
        // Exponential backoff: 60s, 300s (5min), 900s (15min)
        $delays = [
            0 => 60,     // 1 minuto (primeira retry)
            1 => 300,    // 5 minutos (segunda retry)
            2 => 900,    // 15 minutos (terceira retry)
        ];

        return $delays[$retryCount] ?? 900; // Default: 15min
    }

    /**
     * Obter timestamp para próxima tentativa
     *
     * @param int $retryCount Número de tentativas já feitas
     * @return \DateTimeImmutable Timestamp do próximo retry
     */
    public static function getNextRetryAt(int $retryCount): \DateTimeImmutable
    {
        $delay = self::calculateDelay($retryCount);
        return (new \DateTimeImmutable())->modify("+{$delay} seconds");
    }

    /**
     * Verificar se deve fazer retry baseado em delivery
     *
     * @param MessageDelivery $delivery Delivery atual
     * @param string $errorType Tipo do erro
     * @return bool
     */
    public static function shouldRetry(MessageDelivery $delivery, string $errorType): bool
    {
        // Não retry se erro não é retryable
        if (!self::isRetryable($errorType)) {
            return false;
        }

        // Não retry se excedeu max retries
        if (!$delivery->canRetry()) {
            return false;
        }

        return true;
    }

    /**
     * Obter descrição da estratégia de retry
     *
     * @return array
     */
    public static function getRetryStrategy(): array
    {
        return [
            'max_retries' => 3,
            'delays' => [
                'retry_1' => '1 minuto',
                'retry_2' => '5 minutos',
                'retry_3' => '15 minutos',
            ],
            'strategy' => 'exponential_backoff',
            'retryable_errors' => self::RETRYABLE_ERRORS,
            'non_retryable_errors' => self::NON_RETRYABLE_ERRORS,
        ];
    }
}
