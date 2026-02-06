<?php
/**
 * MercadoPagoModule - Bootstrap do Módulo Mercado Pago
 *
 * RESPONSABILIDADE:
 * - Inicializar todo o stack de payout MP
 * - Dependency Injection
 * - Configuração e validação
 *
 * PRINCÍPIOS:
 * - Factory Pattern
 * - Lazy Loading
 * - Configuration Validation
 *
 * USO:
 * ```php
 * $module = new MercadoPagoModule();
 * $module->boot();
 *
 * $provider = $module->getPayoutProvider();
 * $result = $provider->transfer($payout);
 * ```
 *
 * CONFIGURAÇÃO:
 * - LIMPVIX_MP_ACCESS_TOKEN (wp-config.php)
 * - LIMPVIX_MP_TIMEOUT (opcional, default: 30)
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Modules\Payouts\MercadoPago
 */

namespace LimpVix\Modules\Payouts\MercadoPago;

use LimpVix\Modules\Payouts\PayoutProviderInterface;

defined('ABSPATH') || exit;

class MercadoPagoModule
{
    /**
     * Client HTTP
     *
     * @var MercadoPagoClient|null
     */
    private $client;

    /**
     * Provider
     *
     * @var MercadoPagoPayoutProvider|null
     */
    private $provider;

    /**
     * Repository
     *
     * @var RepasseRepository|null
     */
    private $repository;

    /**
     * Access Token
     *
     * @var string|null
     */
    private $accessToken;

    /**
     * Timeout (segundos)
     *
     * @var int
     */
    private $timeout;

    /**
     * Construtor
     *
     * @param string|null $accessToken Access Token (se null, busca de wp-config)
     * @param int $timeout Timeout em segundos
     */
    public function __construct(?string $accessToken = null, int $timeout = 30)
    {
        $this->accessToken = $accessToken ?? $this->getAccessTokenFromConfig();
        $this->timeout = $timeout;
    }

    /**
     * Inicializar módulo
     *
     * @return void
     * @throws \RuntimeException
     */
    public function boot(): void
    {
        // Validar configuração
        $this->validateConfiguration();

        // Construir stack (lazy)
        $this->getPayoutProvider();
        $this->getRepository();

        // Log de inicialização
        $this->logInitialization();
    }

    /**
     * Obter Payout Provider
     *
     * @return PayoutProviderInterface
     */
    public function getPayoutProvider(): PayoutProviderInterface
    {
        if ($this->provider === null) {
            $client = $this->getClient();
            $this->provider = new MercadoPagoPayoutProvider($client);
        }

        return $this->provider;
    }

    /**
     * Obter Repository
     *
     * @return RepasseRepository
     */
    public function getRepository(): RepasseRepository
    {
        if ($this->repository === null) {
            $this->repository = new RepasseRepository();
        }

        return $this->repository;
    }

    /**
     * Obter Client (privado)
     *
     * @return MercadoPagoClient
     */
    private function getClient(): MercadoPagoClient
    {
        if ($this->client === null) {
            $this->client = new MercadoPagoClient($this->accessToken, $this->timeout);
        }

        return $this->client;
    }

    /**
     * Obter Access Token de wp-config.php
     *
     * @return string|null
     */
    private function getAccessTokenFromConfig(): ?string
    {
        if (defined('LIMPVIX_MP_ACCESS_TOKEN')) {
            return LIMPVIX_MP_ACCESS_TOKEN;
        }

        return null;
    }

    /**
     * Validar configuração
     *
     * @return void
     * @throws \RuntimeException
     */
    private function validateConfiguration(): void
    {
        if (empty($this->accessToken)) {
            throw new \RuntimeException(
                'Mercado Pago Access Token não configurado. ' .
                'Defina LIMPVIX_MP_ACCESS_TOKEN em wp-config.php'
            );
        }

        // Validar formato básico do token
        if (strlen($this->accessToken) < 20) {
            throw new \RuntimeException(
                'Mercado Pago Access Token parece inválido (muito curto)'
            );
        }

        // Validar timeout
        if ($this->timeout < 5 || $this->timeout > 120) {
            throw new \RuntimeException(
                'Timeout deve estar entre 5 e 120 segundos'
            );
        }
    }

    /**
     * Verificar se módulo está disponível
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $this->validateConfiguration();
            return $this->getPayoutProvider()->isAvailable();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Log de inicialização
     *
     * @return void
     */
    private function logInitialization(): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_mp_module_initialized', [
            'provider' => 'mercadopago',
            'timeout' => $this->timeout,
            'token_preview' => substr($this->accessToken, 0, 10) . '...',
            'timestamp' => current_time('mysql')
        ]);
    }

    /**
     * Obter informações de configuração (para admin)
     *
     * @return array
     */
    public function getInfo(): array
    {
        return [
            'provider' => 'mercadopago',
            'timeout' => $this->timeout,
            'token_configured' => !empty($this->accessToken),
            'token_preview' => !empty($this->accessToken) ? substr($this->accessToken, 0, 10) . '...' : 'NOT_SET',
            'available' => $this->isAvailable()
        ];
    }
}
