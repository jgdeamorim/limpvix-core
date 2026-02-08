<?php
/**
 * Integration Tests: Order + Financial State Machines (Sprint 0 - Dia 4)
 *
 * OBJETIVO: Validar fluxos end-to-end com Use Cases
 * - Happy path completo (payment → execution → payout)
 * - Tentativa de pular execução (BLOCKED)
 * - Tentativa de payout antecipado (BLOCKED - regra crítica)
 *
 * COBERTURA:
 * - CreateOrder (Order + Financial criados)
 * - AuthorizePayment (PENDING → AUTHORIZED)
 * - CapturePayment (AUTHORIZED → CAPTURED, CREATED → CONFIRMED)
 * - CompleteServiceWithPayout (IN_EXECUTION → COMPLETED + HELD → PAYOUT_AUTHORIZED)
 * - Validação de regra crítica (payout SÓ se Order::COMPLETED)
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

use LimpVix\Application\UseCases\Order\CreateOrder;
use LimpVix\Application\UseCases\Order\AuthorizePayment;
use LimpVix\Application\UseCases\Order\CapturePayment;
use LimpVix\Application\UseCases\Order\CompleteServiceWithPayout;
use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Finance\Financial;
use LimpVix\Domain\Order\Enums\OrderStatusEnum;
use LimpVix\Domain\Finance\Enums\FinancialStatusEnum;
use LimpVix\Domain\Finance\FinancialStatus;

$testsPassed = 0;
$testsFailed = 0;

function test(string $name, callable $fn): void {
    global $testsPassed, $testsFailed;
    try {
        $fn();
        echo "✅ $name\n";
        $testsPassed++;
    } catch (Exception $e) {
        echo "❌ $name\n";
        echo "   Error: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
}

echo "=== INTEGRATION TESTS: ORDER + FINANCIAL ===\n\n";

// ========================================
// USE CASE: CreateOrder
// ========================================

test('CreateOrder: creates Order in CREATED and Financial in PENDING', function() {
    $useCase = new CreateOrder();
    $result = $useCase->execute('test-uuid-001', 1, 100.0, 15.0);
    
    assert($result->isOk());
    $data = $result->value();
    
    assert($data['order'] instanceof Order);
    assert($data['financial'] instanceof Financial);
    assert($data['order']->getStatus() === OrderStatusEnum::CREATED);
    assert($data['financial']->getStatus() === FinancialStatusEnum::PENDING);
    assert($data['total_amount'] === 100.0);
    assert($data['platform_fee'] === 15.0);
    assert($data['professional_payout'] === 85.0);
});

test('CreateOrder: fails with negative amount', function() {
    $useCase = new CreateOrder();
    $result = $useCase->execute('test-uuid-002', 2, -100.0);
    
    assert($result->isFail());
    assert(str_contains($result->error(), 'positive'));
});

test('CreateOrder: fails with invalid fee percentage', function() {
    $useCase = new CreateOrder();
    $result = $useCase->execute('test-uuid-003', 3, 100.0, 150.0);
    
    assert($result->isFail());
    assert(str_contains($result->error(), 'between 0 and 100'));
});

// ========================================
// USE CASE: AuthorizePayment
// ========================================

test('AuthorizePayment: transitions Financial PENDING → AUTHORIZED', function() {
    $financial = Financial::create('test-uuid-010', 100.0, 15.0, 85.0);
    assert($financial->getStatus() === FinancialStatusEnum::PENDING);
    
    $useCase = new AuthorizePayment();
    $result = $useCase->execute($financial);
    
    assert($result->isOk());
    assert($result->value()->getStatus() === FinancialStatusEnum::AUTHORIZED);
});

test('AuthorizePayment: fails if already authorized', function() {
    $financial = new Financial(
        'test-uuid-011',
        FinancialStatusEnum::AUTHORIZED,
        100.0,
        15.0,
        85.0
    );
    
    $useCase = new AuthorizePayment();
    $result = $useCase->execute($financial);
    
    assert($result->isFail());
    assert(str_contains($result->error(), 'Cannot authorize payment'));
});

// ========================================
// USE CASE: CapturePayment
// ========================================

test('CapturePayment: captures payment and confirms order', function() {
    // Setup
    $financialStatusLegacy = FinancialStatus::CREATED();
    $order = Order::create('test-uuid-020', 20, $financialStatusLegacy, 100.0);
    $financial = Financial::create('test-uuid-020', 100.0, 15.0, 85.0);
    
    // Authorize first
    $financial->authorize();
    
    // Now capture
    $useCase = new CapturePayment();
    $result = $useCase->execute($order, $financial);
    
    assert($result->isOk());
    $data = $result->value();
    assert($data['order']->getStatus() === OrderStatusEnum::CONFIRMED);
    assert($data['financial']->getStatus() === FinancialStatusEnum::CAPTURED);
});

test('CapturePayment: fails if payment not authorized', function() {
    $financialStatusLegacy = FinancialStatus::CREATED();
    $order = Order::create('test-uuid-021', 21, $financialStatusLegacy, 100.0);
    $financial = Financial::create('test-uuid-021', 100.0, 15.0, 85.0);
    
    $useCase = new CapturePayment();
    $result = $useCase->execute($order, $financial);
    
    assert($result->isFail());
    assert(str_contains($result->error(), 'Cannot capture payment'));
});

// ========================================
// USE CASE: CompleteServiceWithPayout (CRITICAL)
// ========================================

test('CompleteServiceWithPayout: completes service and authorizes payout (HAPPY PATH)', function() {
    // Setup: Order in IN_EXECUTION, Financial in HELD
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'test-uuid-030',
        30,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'test-uuid-030',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial);
    
    assert($result->isOk());
    $data = $result->value();
    assert($data['order']->getStatus() === OrderStatusEnum::COMPLETED);
    assert($data['financial']->getStatus() === FinancialStatusEnum::PAYOUT_AUTHORIZED);
    assert($data['payout_authorized'] === true);
});

test('CompleteServiceWithPayout: BLOCKS payout if Order NOT completed (CRITICAL)', function() {
    // Setup: Order NOT in IN_EXECUTION (tentativa de pular)
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'test-uuid-031',
        31,
        OrderStatusEnum::CONFIRMED, // NOT IN_EXECUTION
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'test-uuid-031',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::CONFIRMED
    );
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial);
    
    assert($result->isFail());
    // Order não pode transicionar de CONFIRMED → COMPLETED
    assert(str_contains($result->error(), 'Cannot complete order'));
});

test('CompleteServiceWithPayout: BLOCKS if Financial not in HELD', function() {
    // Setup: Order ok, mas Financial não está em HELD
    $financialStatusLegacy = FinancialStatus::PAID();
    $order = new Order(
        'test-uuid-032',
        32,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'test-uuid-032',
        FinancialStatusEnum::CAPTURED, // NOT HELD
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial);
    
    // Order completa, mas Financial falha ao autorizar payout
    assert($result->isFail());
    assert(str_contains($result->error(), 'Cannot authorize payout'));
});

// ========================================
// INTEGRATION: Happy Path Completo
// ========================================

test('INTEGRATION: Happy path completo (CREATE → AUTHORIZE → CAPTURE → COMPLETE → PAYOUT)', function() {
    // 1. CREATE
    $createUseCase = new CreateOrder();
    $createResult = $createUseCase->execute('test-uuid-100', 100, 200.0, 15.0);
    assert($createResult->isOk());
    
    $order = $createResult->value()['order'];
    $financial = $createResult->value()['financial'];
    
    assert($order->getStatus() === OrderStatusEnum::CREATED);
    assert($financial->getStatus() === FinancialStatusEnum::PENDING);
    
    // 2. AUTHORIZE
    $authorizeUseCase = new AuthorizePayment();
    $authorizeResult = $authorizeUseCase->execute($financial);
    assert($authorizeResult->isOk());
    assert($financial->getStatus() === FinancialStatusEnum::AUTHORIZED);
    
    // 3. CAPTURE + CONFIRM
    $captureUseCase = new CapturePayment();
    $captureResult = $captureUseCase->execute($order, $financial);
    assert($captureResult->isOk());
    assert($order->getStatus() === OrderStatusEnum::CONFIRMED);
    assert($financial->getStatus() === FinancialStatusEnum::CAPTURED);
    
    // 4. SCHEDULE (manual)
    $order->schedule();
    assert($order->getStatus() === OrderStatusEnum::SCHEDULED);
    
    // 5. START EXECUTION (manual)
    $order->startExecution();
    assert($order->getStatus() === OrderStatusEnum::IN_EXECUTION);
    
    // 6. HOLD (manual)
    $financial->hold();
    assert($financial->getStatus() === FinancialStatusEnum::HELD);
    
    // 7. COMPLETE + PAYOUT
    $completeUseCase = new CompleteServiceWithPayout();
    $completeResult = $completeUseCase->execute($order, $financial);
    assert($completeResult->isOk());
    assert($order->getStatus() === OrderStatusEnum::COMPLETED);
    assert($financial->getStatus() === FinancialStatusEnum::PAYOUT_AUTHORIZED);
});

// ========================================
// INTEGRATION: Tentativa de Pular Execução (BLOCKED)
// ========================================

test('INTEGRATION: BLOCKS skipping execution (CREATED → COMPLETED directly)', function() {
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = Order::create('test-uuid-200', 200, $financialStatusLegacy, 100.0);
    
    // Tentar completar diretamente (sem passar por SCHEDULED, IN_EXECUTION)
    $exceptionThrown = false;
    try {
        $order->complete();
    } catch (\Exception $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Transition not allowed'));
    }
    assert($exceptionThrown);
    assert($order->getStatus() === OrderStatusEnum::CREATED); // Status não mudou
});

// ========================================
// INTEGRATION: Tentativa de Payout Antecipado (BLOCKED - CRITICAL)
// ========================================

test('INTEGRATION: BLOCKS early payout without Order::COMPLETED (CRITICAL RULE)', function() {
    // Setup: Financial em HELD, mas Order NÃO está COMPLETED
    $financial = new Financial(
        'test-uuid-300',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION // NOT COMPLETED
    );
    
    // Tentar autorizar payout diretamente
    $exceptionThrown = false;
    try {
        $financial->authorizePayout();
    } catch (\Exception $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Order must be COMPLETED'));
    }
    assert($exceptionThrown);
    assert($financial->getStatus() === FinancialStatusEnum::HELD); // Status não mudou
});

// ========================================
// RESULTS
// ========================================

echo "\n=== RESULTS ===\n";
echo "✅ Passed: $testsPassed\n";
echo "❌ Failed: $testsFailed\n";
echo "📊 Total: " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n🎉 ALL INTEGRATION TESTS PASSED!\n";
    exit(0);
} else {
    echo "\n💥 SOME TESTS FAILED!\n";
    exit(1);
}
