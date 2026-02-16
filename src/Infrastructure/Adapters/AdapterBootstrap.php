<?php
/**
 * AdapterBootstrap - Inicialização de Adaptadores
 *
 * FLOW 4.4 INTEGRATION:
 * - Injects FeedbackRepository into ExecutePayout
 * - Registers ReleasePayoutHoldOnFeedbackApproved event listener
 * - Enables payout hold logic based on feedback rating
 */

namespace LimpVix\Infrastructure\Adapters;

use LimpVix\Application\UseCases\ProcessPaymentConfirmed;
use LimpVix\Application\UseCases\ProcessServiceCompleted;
use LimpVix\Application\UseCases\ProcessFeedbackReceived;
use LimpVix\Application\UseCases\ProcessTimerExpired;
use LimpVix\Application\UseCases\TransitionFinancialStatus;
use LimpVix\Application\UseCases\AppendLedgerEntry;
use LimpVix\Application\UseCases\Financial\ExecutePayout;
use LimpVix\Application\Services\PlatformFeeCalculator;
use LimpVix\Domain\Finance\FinancialPolicy;
use LimpVix\Infrastructure\Persistence\WpLedgerRepository;
use LimpVix\Infrastructure\Persistence\WpOrderRepository;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPayoutProvider;
use LimpVix\Infrastructure\Adapters\BookneticBridge;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Infrastructure\Persistence\WpExecutionRepository;
use LimpVix\Domain\Feedback\FeedbackRepositoryInterface; // NEW: Flow 4.4
use LimpVix\Infrastructure\Feedback\Repositories\WpFeedbackRepository; // NEW: Flow 4.4
use LimpVix\Infrastructure\EventListeners\ReleasePayoutHoldOnFeedbackApproved; // NEW: Flow 4.4
use LimpVix\Application\UseCases\Feedback\CalculateProfessionalScore; // NEW: Flow 4.6
use LimpVix\Infrastructure\EventListeners\UpdateProfessionalScoreOnFeedbackApproved; // NEW: Flow 4.6
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface; // NEW: Flow 4.6
use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository; // NEW: Flow 4.6

defined('ABSPATH') || exit;

class AdapterBootstrap
{
    private $registry;

    public function __construct()
    {
        $this->registry = new AdapterRegistry();
    }

    /**
     * Inicializar todos os adaptadores
     *
     * FLOW 4.4: Now includes Feedback integration for payout hold logic
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

        // ✅ FLOW 4.4: Construct ExecutePayout with FeedbackRepository
        $professionalRepo = new WpMarketplaceProfessionalRepository(); // NEW: Flow 4.6
        $executionRepo = new WpExecutionRepository();
        $payoutRepo = new WpPayoutRepository();
        $payoutProvider = new MercadoPagoPayoutProvider();
        $feedbackRepo = new WpFeedbackRepository(); // NEW: Flow 4.4
        
        $executePayout = new ExecutePayout(
            $executionRepo,
            $payoutProvider,
            $payoutRepo,
            $feedbackRepo // NEW: Flow 4.4 - enables payout hold logic
        );

        // 4. Construir Adaptadores
        $wooCommerceAdapter = new WooCommercePaymentAdapter($processPayment, $orderRepo);
        $wooCommerceStatusSync = new WooCommerceStatusSyncAdapter();
        $bookneticAdapter = new BookneticServiceAdapter($processService);
        $feedbackAdapter = new FeedbackAdapter($processFeedback);
        $timerAdapter = new TimerCronAdapter($processTimer, $ledgerRepo);
        
        $automaticPayoutDispatcher = new AutomaticPayoutDispatcher($executePayout, $orderRepo);

        // 5. Registrar adaptadores
        $this->registry->add($wooCommerceAdapter, 'woocommerce_payment');
        $this->registry->add($wooCommerceStatusSync, 'woocommerce_status_sync');
        $this->registry->add($bookneticAdapter, 'booknetic_service');
        $this->registry->add($feedbackAdapter, 'customer_feedback');
        $this->registry->add($timerAdapter, 'review_timer');
        $this->registry->add($automaticPayoutDispatcher, 'automatic_payout');

        // 6. Registrar todos os hooks
        $this->registry->registerAll();

        // ✅ FLOW 4.4: Register event listener for payout hold release
        ReleasePayoutHoldOnFeedbackApproved::register($payoutRepo, $executePayout);

        // ✅ FLOW 4.6: Register event listener for professional score recalculation
        $calculateScore = new CalculateProfessionalScore($feedbackRepo, $professionalRepo);
        UpdateProfessionalScoreOnFeedbackApproved::register($calculateScore);
    }

    public function getRegistry(): AdapterRegistry
    {
        return $this->registry;
    }
}
