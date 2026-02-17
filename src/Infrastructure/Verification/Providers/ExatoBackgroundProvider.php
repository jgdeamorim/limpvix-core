<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Verification\Providers;

use LimpVix\Domain\Verification\Contracts\BackgroundProviderInterface;
use LimpVix\Domain\Verification\ValueObjects\BackgroundResult;

/**
 * ExatoBackgroundProvider — Integração real com Exato Digital
 *
 * STATUS: STUB — aguardando contratação do serviço Exato Digital
 *
 * Ativado automaticamente pelo VerificationProviderFactory quando as
 * credenciais estiverem configuradas em:
 *   Settings → Verificação → Exato → API Key + Token
 *
 * REGRAS DE ENGENHARIA (imutáveis):
 * - Nunca expor retorno bruto da Exato ao domínio
 * - Mapear para BackgroundResult com enums internos fixos
 * - Dados sensíveis NUNCA persistidos (apenas classificação final)
 * - Circuit breaker obrigatório (implementar com wp_cache ou transient)
 *
 * @see VerificationProviderFactory::backgroundProvider()
 * @todo Implementar quando credenciais Exato forem fornecidas
 */
final class ExatoBackgroundProvider implements BackgroundProviderInterface
{
    private string $apiKey;
    private string $token;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey   = (string) get_option('limpvix_exato_api_key', '');
        $this->token    = (string) get_option('limpvix_exato_token', '');
        $this->endpoint = (string) get_option('limpvix_exato_endpoint', 'https://api.exatodigital.com.br/v1');
    }

    public function check(
        string $cpf,
        string $fullName,
        string $birthDate,
    ): BackgroundResult {
        // TODO: Implementar chamada real à API Exato Digital
        //
        // Etapas:
        // 1. Verificar consentimento LGPD antes da consulta
        // 2. POST /consultas/background — enviar dados
        // 3. GET /consultas/{id}/resultado — aguardar resultado
        // 4. MAPEAR resposta para enums internos (nunca string livre)
        // 5. Aplicar PolicyEngine nas categorias retornadas
        // 6. Persistir APENAS a classificação final (não dados brutos)
        //
        // Circuit breaker: se Exato indisponível, retornar BackgroundStatus::PENDING
        // e agendar retry (não bloquear o profissional)

        throw new \RuntimeException(
            'ExatoBackgroundProvider não está implementado. ' .
            'Configure as credenciais Exato em Settings → Verificação → Exato Digital. ' .
            'O sistema usará MockBackgroundProvider até que as credenciais sejam fornecidas.'
        );
    }

    public function providerName(): string
    {
        return 'exato_digital';
    }

    public function isConnected(): bool
    {
        return !empty($this->apiKey) && !empty($this->token);
    }
}
