<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Verification\Providers;

use LimpVix\Domain\Verification\Contracts\BackgroundProviderInterface;
use LimpVix\Domain\Verification\Contracts\KycProviderInterface;

/**
 * VerificationProviderFactory — Seleção automática de provedor real vs mock
 *
 * LÓGICA:
 * - Se credenciais PPID configuradas → PpidKycProvider (real)
 * - Se credenciais PPID ausentes → MockKycProvider (test mode)
 *
 * - Se credenciais Exato configuradas → ExatoBackgroundProvider (real)
 * - Se credenciais Exato ausentes → MockBackgroundProvider (test mode)
 *
 * O sistema troca automaticamente ao preencher as credenciais no admin.
 * Zero mudança de código necessária.
 */
final class VerificationProviderFactory
{
    /**
     * Retorna o provedor KYC ativo (real ou mock)
     */
    public static function kycProvider(): KycProviderInterface
    {
        $ppid = new PpidKycProvider();

        if ($ppid->isConnected()) {
            return $ppid;
        }

        return new MockKycProvider();
    }

    /**
     * Retorna o provedor de background check ativo (real ou mock)
     */
    public static function backgroundProvider(): BackgroundProviderInterface
    {
        $exato = new ExatoBackgroundProvider();

        if ($exato->isConnected()) {
            return $exato;
        }

        return new MockBackgroundProvider();
    }

    /**
     * Retorna o status de conexão para exibição no admin settings
     *
     * @return array{kyc: array, background: array}
     */
    public static function connectionStatus(): array
    {
        $kycProvider        = self::kycProvider();
        $backgroundProvider = self::backgroundProvider();

        return [
            'kyc' => [
                'provider'    => $kycProvider->providerName(),
                'connected'   => $kycProvider->isConnected(),
                'mode'        => $kycProvider->isConnected() ? 'production' : 'test',
                'label'       => $kycProvider->isConnected() ? '✅ PPID Conectado' : '🔴 PPID Desconectado (modo teste)',
            ],
            'background' => [
                'provider'    => $backgroundProvider->providerName(),
                'connected'   => $backgroundProvider->isConnected(),
                'mode'        => $backgroundProvider->isConnected() ? 'production' : 'test',
                'label'       => $backgroundProvider->isConnected() ? '✅ Exato Digital Conectado' : '🔴 Exato Digital Desconectado (modo teste)',
            ],
        ];
    }
}
