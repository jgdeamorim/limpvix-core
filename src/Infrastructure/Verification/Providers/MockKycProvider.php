<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Verification\Providers;

use LimpVix\Domain\Verification\Contracts\KycProviderInterface;
use LimpVix\Domain\Verification\ValueObjects\KycResult;

/**
 * MockKycProvider — Provedor KYC para ambiente de desenvolvimento/staging
 *
 * Aprovação automática com score alto.
 * Ativado quando as credenciais do PPID NÃO estão configuradas.
 * Nunca usar em produção.
 */
final class MockKycProvider implements KycProviderInterface
{
    public function verify(
        string $cpf,
        string $fullName,
        string $birthDate,
        string $documentUrl,
        string $selfieUrl,
    ): KycResult {
        // Simula latência da API real (200ms)
        usleep(200000);

        error_log("[LimpVix][MockKycProvider] KYC mock aprovado para CPF {$cpf} — configure credenciais PPID para usar o provedor real.");

        return KycResult::approved(
            confidence: 0.99,
            provider:   $this->providerName(),
        );
    }

    public function providerName(): string
    {
        return 'mock_kyc';
    }

    public function isConnected(): bool
    {
        return false; // Mock nunca é "conectado" — é fallback de dev
    }
}
