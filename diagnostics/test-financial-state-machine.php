<?php
/**
 * Test Financial State Machine (Sprint 0 - Dia 3)
 *
 * OBJETIVO: Validar State Machine de Financial.php
 * - Factory method
 * - Transições válidas
 * - Transições inválidas (CRÍTICAS)
 * - Estados terminais
 * - Regra crítica: captured → payout_authorized requer Order::COMPLETED
 *
 * REQUISITO: ≥15 testes
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

use LimpVix\Domain\Finance\Financial;
use LimpVix\Domain\Finance\Enums\FinancialStatusEnum;
use LimpVix\Domain\Finance\Exceptions\InvalidFinancialTransitionException;
use LimpVix\Domain\Order\Enums\OrderStatusEnum;

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

echo "=== FINANCIAL STATE MACHINE TESTS ===\n\n";

// ========================================
// FACTORY METHOD
// ========================================

test('Factory: create() starts as PENDING', function() {
    $financial = Financial::create('order-123', 100.0, 15.0, 85.0);
    assert($financial->getStatus() === FinancialStatusEnum::PENDING);
    assert($financial->getOrderUuid() === 'order-123');
    assert($financial->getAmount() === 100.0);
    assert($financial->getPlatformFee() === 15.0);
    assert($financial->getProfessionalPayout() === 85.0);
});

test('Factory: create() without order status', function() {
    $financial = Financial::create('order-456', 200.0, 30.0, 170.0);
    assert($financial->getOrderStatus() === null);
});

// ========================================
// VALID TRANSITIONS
// ========================================

test('Valid: PENDING → AUTHORIZED', function() {
    $financial = Financial::create('order-1', 100.0, 15.0, 85.0);
    $financial->authorize();
    assert($financial->getStatus() === FinancialStatusEnum::AUTHORIZED);
});

test('Valid: AUTHORIZED → CAPTURED', function() {
    $financial = new Financial('order-2', FinancialStatusEnum::AUTHORIZED, 100.0, 15.0, 85.0);
    $financial->capture();
    assert($financial->getStatus() === FinancialStatusEnum::CAPTURED);
});

test('Valid: CAPTURED → HELD', function() {
    $financial = new Financial('order-3', FinancialStatusEnum::CAPTURED, 100.0, 15.0, 85.0);
    $financial->hold();
    assert($financial->getStatus() === FinancialStatusEnum::HELD);
});

test('Valid: HELD → PAYOUT_AUTHORIZED (with Order::COMPLETED)', function() {
    $financial = new Financial(
        'order-4',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::COMPLETED
    );
    $financial->authorizePayout();
    assert($financial->getStatus() === FinancialStatusEnum::PAYOUT_AUTHORIZED);
});

test('Valid: PAYOUT_AUTHORIZED → PAYOUT_COMPLETED', function() {
    $financial = new Financial(
        'order-5',
        FinancialStatusEnum::PAYOUT_AUTHORIZED,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::COMPLETED
    );
    $financial->completePayout();
    assert($financial->getStatus() === FinancialStatusEnum::PAYOUT_COMPLETED);
});

test('Valid: AUTHORIZED → REFUNDED', function() {
    $financial = new Financial('order-6', FinancialStatusEnum::AUTHORIZED, 100.0, 15.0, 85.0);
    $financial->refund();
    assert($financial->getStatus() === FinancialStatusEnum::REFUNDED);
});

test('Valid: CAPTURED → REFUNDED', function() {
    $financial = new Financial('order-7', FinancialStatusEnum::CAPTURED, 100.0, 15.0, 85.0);
    $financial->refund();
    assert($financial->getStatus() === FinancialStatusEnum::REFUNDED);
});

test('Valid: HELD → REFUNDED', function() {
    $financial = new Financial('order-8', FinancialStatusEnum::HELD, 100.0, 15.0, 85.0);
    $financial->refund();
    assert($financial->getStatus() === FinancialStatusEnum::REFUNDED);
});

test('Valid: PENDING → FAILED', function() {
    $financial = Financial::create('order-9', 100.0, 15.0, 85.0);
    $financial->markAsFailed();
    assert($financial->getStatus() === FinancialStatusEnum::FAILED);
});

test('Valid: AUTHORIZED → FAILED', function() {
    $financial = new Financial('order-10', FinancialStatusEnum::AUTHORIZED, 100.0, 15.0, 85.0);
    $financial->markAsFailed();
    assert($financial->getStatus() === FinancialStatusEnum::FAILED);
});

// ========================================
// INVALID TRANSITIONS (CRITICAL)
// ========================================

test('Invalid: HELD → PAYOUT_AUTHORIZED without Order::COMPLETED (CRITICAL)', function() {
    $financial = new Financial(
        'order-11',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION // NOT COMPLETED
    );
    
    $exceptionThrown = false;
    try {
        $financial->authorizePayout();
    } catch (InvalidFinancialTransitionException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Order must be COMPLETED'));
    }
    assert($exceptionThrown);
    assert($financial->getStatus() === FinancialStatusEnum::HELD); // Status não mudou
});

test('Invalid: HELD → PAYOUT_AUTHORIZED with null Order status (CRITICAL)', function() {
    $financial = new Financial('order-12', FinancialStatusEnum::HELD, 100.0, 15.0, 85.0, null);
    
    $exceptionThrown = false;
    try {
        $financial->authorizePayout();
    } catch (InvalidFinancialTransitionException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Order must be COMPLETED'));
    }
    assert($exceptionThrown);
});

test('Invalid: CAPTURED → PAYOUT_AUTHORIZED (skip HELD)', function() {
    $financial = new Financial(
        'order-13',
        FinancialStatusEnum::CAPTURED,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::COMPLETED
    );
    
    $exceptionThrown = false;
    try {
        $financial->authorizePayout();
    } catch (InvalidFinancialTransitionException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

test('Invalid: PENDING → HELD (skip authorization)', function() {
    $financial = Financial::create('order-14', 100.0, 15.0, 85.0);
    
    $exceptionThrown = false;
    try {
        $financial->hold();
    } catch (InvalidFinancialTransitionException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

test('Invalid: PENDING → PAYOUT_COMPLETED (skip all)', function() {
    $financial = Financial::create('order-15', 100.0, 15.0, 85.0);
    
    $exceptionThrown = false;
    try {
        $financial->completePayout();
    } catch (InvalidFinancialTransitionException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

// ========================================
// TERMINAL STATES
// ========================================

test('Terminal: PAYOUT_COMPLETED blocks all transitions', function() {
    $financial = new Financial(
        'order-16',
        FinancialStatusEnum::PAYOUT_COMPLETED,
        100.0,
        15.0,
        85.0
    );
    
    $exceptionThrown = false;
    try {
        $financial->refund();
    } catch (InvalidFinancialTransitionException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'terminal'));
    }
    assert($exceptionThrown);
});

test('Terminal: REFUNDED blocks all transitions', function() {
    $financial = new Financial('order-17', FinancialStatusEnum::REFUNDED, 100.0, 15.0, 85.0);
    
    $exceptionThrown = false;
    try {
        $financial->authorize();
    } catch (InvalidFinancialTransitionException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'terminal'));
    }
    assert($exceptionThrown);
});

test('Terminal: FAILED blocks all transitions', function() {
    $financial = new Financial('order-18', FinancialStatusEnum::FAILED, 100.0, 15.0, 85.0);
    
    $exceptionThrown = false;
    try {
        $financial->authorize();
    } catch (InvalidFinancialTransitionException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'terminal'));
    }
    assert($exceptionThrown);
});

// ========================================
// EDGE CASES
// ========================================

test('Edge: updateOrderStatus() works correctly', function() {
    $financial = new Financial('order-19', FinancialStatusEnum::HELD, 100.0, 15.0, 85.0, null);
    assert($financial->getOrderStatus() === null);
    
    $financial->updateOrderStatus(OrderStatusEnum::COMPLETED);
    assert($financial->getOrderStatus() === OrderStatusEnum::COMPLETED);
    
    // Now authorizePayout should work
    $financial->authorizePayout();
    assert($financial->getStatus() === FinancialStatusEnum::PAYOUT_AUTHORIZED);
});

test('Edge: equals() compares by orderUuid', function() {
    $f1 = Financial::create('order-20', 100.0, 15.0, 85.0);
    $f2 = Financial::create('order-20', 200.0, 30.0, 170.0);
    $f3 = Financial::create('order-21', 100.0, 15.0, 85.0);
    
    assert($f1->equals($f2));
    assert(!$f1->equals($f3));
});

// ========================================
// RESULTS
// ========================================

echo "\n=== RESULTS ===\n";
echo "✅ Passed: $testsPassed\n";
echo "❌ Failed: $testsFailed\n";
echo "📊 Total: " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n🎉 ALL TESTS PASSED!\n";
    exit(0);
} else {
    echo "\n💥 SOME TESTS FAILED!\n";
    exit(1);
}
