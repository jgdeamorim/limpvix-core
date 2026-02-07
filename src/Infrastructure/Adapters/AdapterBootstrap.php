<?php
/**
 * AdapterBootstrap - Inicialização de Adaptadores
 *
 * RESPONSABILIDADE:
 * - Construir e conectar todos os adaptadores
 * - Dependency Injection manual
 * - Registro no AdapterRegistry
 *
 * PRINCÍPIOS:
 * - Factory Pattern
 * - Dependency Injection
 * - Single Responsibility
 *
 * USO:
 * ```php
 * // No plugin principal
 * $bootstrap = new AdapterBootstrap($container);
 * $bootstrap->boot();
 * ```
 *
 * IMPORTANTE:
 * - Este arquivo conecta todas as camadas
 * - Use DI Container em produção (se disponível)
 * - Por ora, DI manual é suficiente
 *
 * PASSO 5.4 - Adaptadores de Eventos
 *
 * @package LimpVix\Infrastructure\Adapters
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\ProcessPaymentConfirmed;
use LimpVix\Application\UseCases\ProcessServiceCompleted;
use LimpVix\Application\UseCases\ProcessFeedbackReceived;
use LimpVix\Application\UseCases\ProcessTimerExpired;
use LimpVix\Application\UseCases\TransitionFinancialStatus;
use LimpVix\Application\UseCases\AppendLedgerEntry;
use LimpVix\Application\UseCases\ExecuteTransfer;
use LimpVix\Application\Services\PlatformFeeCalculator;
use LimpVix\Domain\Finance\FinancialPolicy;
use LimpVix\Infrastructure\Persistence\WpLedgerRepository;
use LimpVix\Infrastructure\Persistence\WpOrderRepository;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPayoutProvider;
use LimpVix\Infrastructure\Adapters\BookneticBridge;

defined('ABSPATH') || exit;

class AdapterBootstrap
{
    /**
     * Registry de adaptadores
     *
     * @var AdapterRegistry
     */
    private $registry;

    /**
     * Construtor
     */
    public function __construct()
    {
        $this->registry = new AdapterRegistry();
    }

    /**
     * Inicializar todos os adaptadores
     *
     * @return void
     */
    public function boot(): void
    {
        // 0. Registrar Booknetic Bridge (traduz hooks nativos)
        $bookneticBridge = new BookneticBridge();
        $bookneticBridge->register();

        // 1. Construir dependências compartilhadas
        $orderRepo = new WpOrderRepository();
        $ledgerRepo = new WpLedgerRepository();
        $policy = new FinancialPolicy();
        $feeCalculator = new PlatformFeeCalculator();

        // 2. Construir Use Cases
        $appendLedger = new AppendLedgerEntry($ledgerRepo);
        $transitionUseCase = new TransitionFinancialStatus(
            $orderRepo,
            $ledgerRepo,
            $policy,
            $appendLedger
        );

        // 3. Construir Use Cases específicos
        $processPayment = new ProcessPaymentConfirmed(
            $transitionUseCase,
            $orderRepo,
            $feeCalculator
        );
        $processService = new ProcessServiceCompleted($transitionUseCase);
        $processFeedback = new ProcessFeedbackReceived($transitionUseCase);
        $processTimer = new ProcessTimerExpired($transitionUseCase);

        // BLC-000: Construir Use Case de repasse
        $payoutRepo = new WpPayoutRepository();
        $payoutProvider = new MercadoPagoPayoutProvider();
        $executeTransfer = new ExecuteTransfer(
            $orderRepo,
            $payoutRepo,
            $payoutProvider,
            $transitionUseCase
        );

        // 4. Construir Adaptadores
        $wooCommerceAdapter = new WooCommercePaymentAdapter($processPayment, $orderRepo);
        $wooCommerceStatusSync = new WooCommerceStatusSyncAdapter();
        $bookneticAdapter = new BookneticServiceAdapter($processService);
        $feedbackAdapter = new FeedbackAdapter($processFeedback);
        $timerAdapter = new TimerCronAdapter($processTimer, $ledgerRepo);
        $automaticPayoutDispatcher = new AutomaticPayoutDispatcher($executeTransfer, $orderRepo);

        // 5. Registrar adaptadores
        $this->registry->add($wooCommerceAdapter, 'woocommerce_payment');
        $this->registry->add($wooCommerceStatusSync, 'woocommerce_status_sync');
        $this->registry->add($bookneticAdapter, 'booknetic_service');
        $this->registry->add($feedbackAdapter, 'customer_feedback');
        $this->registry->add($timerAdapter, 'review_timer');
        $this->registry->add($automaticPayoutDispatcher, 'automatic_payout');

        // 6. Registrar todos os hooks
        $this->registry->registerAll();
    }

    /**
     * Obter registry
     *
     * @return AdapterRegistry
     */
    public function getRegistry(): AdapterRegistry
    {
        return $this->registry;
    }
}
