<?php
namespace LimpVix\Infrastructure\KYC;

defined('ABSPATH') || exit;

/**
 * PPID Provider Factory
 *
 * Retorna provider correto baseado em configuração:
 * - MOCK: Para desenvolvimento/testes (quando API real não disponível)
 * - REAL: Para produção (API PPID real)
 */
final class PPIDProviderFactory
{
    /**
     * Cria provider baseado em configuração
     *
     * @return PPIDProvider|PPIDMockProvider
     */
    public static function create()
    {
        $useMock = filter_var(get_option('limpvix_ppid_use_mock', '1'), FILTER_VALIDATE_BOOLEAN);

        if ($useMock) {
            error_log('[PPID] Usando MOCK Provider (desenvolvimento)');
            return new PPIDMockProvider();
        }

        error_log('[PPID] Usando REAL Provider (produção)');
        return new PPIDProvider();
    }

    /**
     * Testa conexão (escolhe provider automaticamente)
     */
    public static function testConnection(string $email, string $senha): array
    {
        $useMock = filter_var(get_option('limpvix_ppid_use_mock', '1'), FILTER_VALIDATE_BOOLEAN);

        if ($useMock) {
            return PPIDMockProvider::testConnection($email, $senha);
        }

        return PPIDProvider::testConnection($email, $senha);
    }
}
