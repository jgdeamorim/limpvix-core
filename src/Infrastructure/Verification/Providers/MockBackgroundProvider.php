<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Verification\Providers;

use LimpVix\Domain\Verification\Contracts\BackgroundProviderInterface;
use LimpVix\Domain\Verification\ValueObjects\BackgroundResult;

/**
 * MockBackgroundProvider — Provedor de background check para dev/staging
 *
 * Aprovação automática com risco baixo.
 * Ativado quando as credenciais da Exato NÃO estão configuradas.
 * Nunca usar em produção.
 */
final class MockBackgroundProvider implements BackgroundProviderInterface
{
    public function check(
        string $cpf,
        string $fullName,
        string $birthDate,
    ): BackgroundResult {
        // Simula latência da API real (500ms)
        usleep(500000);

        error_log("[LimpVix][MockBackgroundProvider] Background mock aprovado para CPF {$cpf} — configure credenciais Exato para usar o provedor real.");

        return BackgroundResult::approved(
            provider:    $this->providerName(),
            validityDays: 365,
        );
    }

    public function providerName(): string
    {
        return 'mock_background';
    }

    public function isConnected(): bool
    {
        return false; // Mock nunca é "conectado" — é fallback de dev
    }
}
