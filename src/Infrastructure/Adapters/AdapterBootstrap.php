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
use LimpVix\Infrastructure\Finance\Providers\EfiPayoutProvider;
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
        // Selecao de provider: EFI Bank (primario) -> MercadoPago (fallback)
        $efiProvider    = new EfiPayoutProvider();
        $mpProvider     = new MercadoPagoPayoutProvider();
        $payoutProvider = $efiProvider->isAvailable() ? $efiProvider : $mpProvider;
        error_log("[LimpVix][AdapterBootstrap] Payout provider: " . ($efiProvider->isAvailable() ? "EFI Bank" : "MercadoPago (fallback)"));
        $feedbackRepo = new WpFeedbackRepository(); // NEW: Flow 4.4
        
        $executePayout = new ExecutePayout(
            $executionRepo,
            $payoutProvider,
            $payoutRepo,
            $feedbackRepo,    // Flow 4.4 - enables payout hold logic
            $professionalRepo // Flow 4.6 - PIX key lookup for automatic payout
        );

        // 4. Construir Adaptadores
        $feedbackAdapter = new FeedbackAdapter($processFeedback);
        $timerAdapter = new TimerCronAdapter($processTimer, $ledgerRepo);

        // 5. Registrar adaptadores
        $this->registry->add($feedbackAdapter, 'customer_feedback');
        $this->registry->add($timerAdapter, 'review_timer');

        // ✅ FLOW 4.4: Register event listener for payout hold release (unconditional)
        // Payout hold/release works independently of WooCommerce — EFI Bank PIX is the primary provider
        ReleasePayoutHoldOnFeedbackApproved::register($payoutRepo, $executePayout);

        // Guard: WooCommerce deve estar ativo para os adapters de pagamento WC
        if (!class_exists('WooCommerce')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[LimpVix] WooCommerce não está ativo — adapters de pagamento WC não registrados.');
            }
            // Continua boot sem os adapters WC (features de payout de profissionais funcionam normalmente)
        } else {
            $wooCommerceAdapter = new WooCommercePaymentAdapter($processPayment, $orderRepo);
            $wooCommerceStatusSync = new WooCommerceStatusSyncAdapter();
            $automaticPayoutDispatcher = new AutomaticPayoutDispatcher($executePayout, $orderRepo);

            $this->registry->add($wooCommerceAdapter, 'woocommerce_payment');
            $this->registry->add($wooCommerceStatusSync, 'woocommerce_status_sync');
            $this->registry->add($automaticPayoutDispatcher, 'automatic_payout');
        }

        // 6. Registrar todos os hooks
        $this->registry->registerAll();

        // ✅ FLOW 4.6: Register event listener for professional score recalculation
        $calculateScore = new CalculateProfessionalScore($feedbackRepo, $professionalRepo);
        UpdateProfessionalScoreOnFeedbackApproved::register($calculateScore);

        // ✅ Boot Payout Crons (register hooks + schedule jobs)
        \LimpVix\Application\Services\PayoutReconciliationService::registerCronHooks();
        \LimpVix\Application\Services\PayoutReconciliationService::scheduleCronJobs();

        // ✅ Wire Feedback Submitted → Payout Authorization (FSM rules)
        // When structured feedback is submitted, authorize payout based on rating:
        // 5★ = immediate, 4★ = 24h delay, ≤3★ = on_hold for admin review
        add_action('limpvix_feedback_submitted', function (array $eventData) {
            $orderUuid = $eventData['order_uuid'] ?? '';
            $finalScore = (int) round($eventData['final_score'] ?? 0);

            if (empty($orderUuid) || $finalScore < 1) {
                return;
            }

            // Resolve order_id from order_uuid
            global $wpdb;
            $orderId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}limpvix_orders WHERE uuid = %s",
                $orderUuid
            ));

            if ($orderId > 0) {
                $reconciliation = new \LimpVix\Application\Services\PayoutReconciliationService();
                $reconciliation->approvePayout($orderId, $finalScore);
            }
        });

        // ✅ S2-HOLD-PREEMPTIVE: Preemptive payout hold on bad feedback
        // When feedback is submitted with rating ≤ 2, immediately mark any pending
        // payout as preemptive_hold (before ExecutePayout even runs)
        add_action('limpvix_feedback_submitted', function (array $eventData) use ($payoutRepo) {
            $orderUuid = $eventData['order_uuid'] ?? '';
            $finalScore = (int) round($eventData['final_score'] ?? 0);

            if (empty($orderUuid) || $finalScore > 2) {
                return; // Only preemptive hold for ratings ≤ 2
            }

            global $wpdb;
            $orderId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}limpvix_orders WHERE uuid = %s",
                $orderUuid
            ));

            if ($orderId <= 0) {
                return;
            }

            // Find pending/scheduled payout for this order
            $payoutId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}limpvix_payouts WHERE order_id = %d AND status IN ('pending', 'scheduled')",
                $orderId
            ));

            if ($payoutId > 0) {
                $payoutRepo->updateStatus($payoutId, 'on_hold');
                error_log(sprintf(
                    '[S2-HOLD-PREEMPTIVE] Payout #%d placed on preemptive hold: feedback rating %d/5 for order #%d',
                    $payoutId,
                    $finalScore,
                    $orderId
                ));

                do_action('limpvix_admin_notification', [
                    'type' => 'warning',
                    'title' => 'Payout Bloqueado Preventivamente',
                    'message' => sprintf(
                        'Payout #%d foi bloqueado preventivamente porque o cliente deu nota %d/5. Revisão admin necessária.',
                        $payoutId,
                        $finalScore
                    ),
                    'source' => 'PreemptivePayoutHold',
                ]);
            }
        }, 5); // Priority 5 = before the general feedback handler (priority 10)
    }

    public function getRegistry(): AdapterRegistry
    {
        return $this->registry;
    }
}
