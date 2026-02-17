<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\Contracts;

use LimpVix\Domain\Verification\ValueObjects\BackgroundResult;

/**
 * BackgroundProviderInterface — Abstração para qualquer provedor de background check
 *
 * O core nunca conhece Exato, Serasa ou qualquer outro provedor.
 * Regra: o provedor NUNCA retorna dados brutos ao domínio.
 * Apenas o BackgroundResult normalizado é exposto.
 */
interface BackgroundProviderInterface
{
    /**
     * Executa background check completo
     *
     * @param string $cpf       CPF do profissional
     * @param string $fullName  Nome completo
     * @param string $birthDate Data de nascimento (Y-m-d)
     * @return BackgroundResult Resultado normalizado (NUNCA dados brutos)
     */
    public function check(
        string $cpf,
        string $fullName,
        string $birthDate,
    ): BackgroundResult;

    /**
     * Nome do provedor para auditoria
     */
    public function providerName(): string;

    /**
     * Indica se o provedor está configurado com credenciais reais
     */
    public function isConnected(): bool;
}
