<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Verification\Providers;

use LimpVix\Domain\Verification\Contracts\KycProviderInterface;
use LimpVix\Domain\Verification\ValueObjects\KycResult;

/**
 * PpidKycProvider — Integração real com PPID (Prova de Posse de Identidade Digital)
 *
 * STATUS: STUB — aguardando contratação do serviço PPID
 *
 * Ativado automaticamente pelo VerificationProviderFactory quando as
 * credenciais estiverem configuradas em:
 *   Settings → Verificação → PPID → API Key + Endpoint
 *
 * @see VerificationProviderFactory::kycProvider()
 * @todo Implementar quando credenciais PPID forem fornecidas
 */
final class PpidKycProvider implements KycProviderInterface
{
    private string $apiKey;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey   = (string) get_option('limpvix_ppid_api_key', '');
        $this->endpoint = (string) get_option('limpvix_ppid_endpoint', 'https://api.ppid.com.br/v1');
    }

    public function verify(
        string $cpf,
        string $fullName,
        string $birthDate,
        string $documentUrl,
        string $selfieUrl,
    ): KycResult {
        // TODO: Implementar chamada real à API PPID
        // Etapas:
        // 1. POST /sessions — criar sessão
        // 2. POST /sessions/{id}/ocr — OCR do documento
        // 3. POST /sessions/{id}/liveness — liveness detection
        // 4. POST /sessions/{id}/facematch — face match
        // 5. GET /sessions/{id}/result — resultado final normalizado
        //
        // Mapear resposta da PPID para KycResult (nunca expor payload bruto)

        throw new \RuntimeException(
            'PpidKycProvider não está implementado. ' .
            'Configure as credenciais PPID em Settings → Verificação → PPID. ' .
            'O sistema usará MockKycProvider até que as credenciais sejam fornecidas.'
        );
    }

    public function providerName(): string
    {
        return 'ppid';
    }

    public function isConnected(): bool
    {
        return !empty($this->apiKey) && !empty($this->endpoint);
    }
}
