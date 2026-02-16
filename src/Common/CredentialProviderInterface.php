<?php
declare(strict_types=1);

namespace LimpVix\Common;

/**
 * Credential Provider Interface
 *
 * Contrato para obtenção de credenciais de integrações externas.
 * Desacopla o Core da fonte real de credenciais (wp_options, constantes, env vars, etc).
 *
 * @package LimpVix\Common
 */
interface CredentialProviderInterface
{
    /**
     * Verifica se uma credencial existe
     *
     * @param string $key Chave da credencial (ex: 'twilio.account_sid')
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Obtém o valor de uma credencial
     *
     * @param string $key Chave da credencial
     * @return string
     * @throws \RuntimeException Se credencial não existir
     */
    public function get(string $key): string;

    /**
     * Obtém o valor de uma credencial ou valor padrão
     *
     * @param string $key Chave da credencial
     * @param string|null $default Valor padrão se não existir
     * @return string|null
     */
    public function getOrDefault(string $key, ?string $default = null): ?string;
}
