<?php

declare(strict_types=1);

namespace LimpVix\Domain\Verification\Contracts;

use LimpVix\Domain\Verification\ValueObjects\KycResult;

/**
 * KycProviderInterface — Abstração para qualquer provedor KYC
 *
 * O core nunca conhece PPID, Serpro ou qualquer outro provedor.
 * Trocar o provedor = trocar a implementação desta interface.
 */
interface KycProviderInterface
{
    /**
     * Executa verificação KYC completa (OCR + Liveness + FaceMatch)
     *
     * @param string $cpf             CPF do profissional
     * @param string $fullName        Nome completo
     * @param string $birthDate       Data de nascimento (Y-m-d)
     * @param string $documentUrl     URL da foto do documento
     * @param string $selfieUrl       URL da selfie
     * @return KycResult              Resultado normalizado
     */
    public function verify(
        string $cpf,
        string $fullName,
        string $birthDate,
        string $documentUrl,
        string $selfieUrl,
    ): KycResult;

    /**
     * Nome do provedor para auditoria
     */
    public function providerName(): string;

    /**
     * Indica se o provedor está configurado com credenciais reais
     */
    public function isConnected(): bool;
}
