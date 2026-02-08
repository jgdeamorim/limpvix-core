<?php
/**
 * Order State Machine Tests (Sprint 0 - Dia 2)
 * Executa no container com WordPress carregado
 */

// Carregar WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Order\Enums\OrderStatusEnum;
use LimpVix\Domain\Order\Exceptions\InvalidOrderTransitionException;
use LimpVix\Domain\Finance\FinancialStatus;

echo "=============================================================\n";
echo "ORDER STATE MACHINE TESTS (Sprint 0 - Dia 2)\n";
echo "=============================================================\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

$passed = 0;
$total = 0;

function test(string $name, callable $fn): void {
    global $passed, $total;
    $total++;
    try {
        $fn();
        echo "  ✅ {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ❌ {$name}: " . $e->getMessage() . "\n";
    }
}

// ========================================
// 1. FACTORY METHOD
// ========================================
echo "1. FACTORY METHOD\n";

test('Order::create() starts as CREATED', function() {
    $order = Order::create('uuid-1', 123, FinancialStatus::PAID());
    assert($order->getStatus() === OrderStatusEnum::CREATED);
});

test('Order constructor accepts OrderStatusEnum', function() {
    $order = new Order('uuid-2', 456, OrderStatusEnum::CONFIRMED, FinancialStatus::PAID());
    assert($order->getStatus() === OrderStatusEnum::CONFIRMED);
});

// ========================================
// 2. VALID TRANSITIONS
// ========================================
echo "\n2. VALID TRANSITIONS\n";

test('CREATED → CONFIRMED', function() {
    $order = Order::create('uuid-3', 1, FinancialStatus::PAID());
    $order->confirm();
    assert($order->getStatus() === OrderStatusEnum::CONFIRMED);
});

test('CREATED → CANCELLED', function() {
    $order = Order::create('uuid-4', 2, FinancialStatus::PAID());
    $order->cancel();
    assert($order->getStatus() === OrderStatusEnum::CANCELLED);
});

test('CONFIRMED → SCHEDULED', function() {
    $order = new Order('uuid-5', 3, OrderStatusEnum::CONFIRMED, FinancialStatus::PAID());
    $order->schedule();
    assert($order->getStatus() === OrderStatusEnum::SCHEDULED);
});

test('CONFIRMED → CANCELLED', function() {
    $order = new Order('uuid-6', 4, OrderStatusEnum::CONFIRMED, FinancialStatus::PAID());
    $order->cancel();
    assert($order->getStatus() === OrderStatusEnum::CANCELLED);
});

test('SCHEDULED → IN_EXECUTION', function() {
    $order = new Order('uuid-7', 5, OrderStatusEnum::SCHEDULED, FinancialStatus::PAID());
    $order->startExecution();
    assert($order->getStatus() === OrderStatusEnum::IN_EXECUTION);
});

test('SCHEDULED → CANCELLED', function() {
    $order = new Order('uuid-8', 6, OrderStatusEnum::SCHEDULED, FinancialStatus::PAID());
    $order->cancel();
    assert($order->getStatus() === OrderStatusEnum::CANCELLED);
});

test('IN_EXECUTION → COMPLETED', function() {
    $order = new Order('uuid-9', 7, OrderStatusEnum::IN_EXECUTION, FinancialStatus::PAID());
    $order->complete();
    assert($order->getStatus() === OrderStatusEnum::COMPLETED);
});

test('IN_EXECUTION → DISPUTED', function() {
    $order = new Order('uuid-10', 8, OrderStatusEnum::IN_EXECUTION, FinancialStatus::PAID());
    $order->dispute();
    assert($order->getStatus() === OrderStatusEnum::DISPUTED);
});

test('COMPLETED → CLOSED', function() {
    $order = new Order('uuid-11', 9, OrderStatusEnum::COMPLETED, FinancialStatus::PAID());
    $order->close();
    assert($order->getStatus() === OrderStatusEnum::CLOSED);
});

test('DISPUTED → COMPLETED (resolve)', function() {
    $order = new Order('uuid-12', 10, OrderStatusEnum::DISPUTED, FinancialStatus::PAID());
    $order->resolveDispute();
    assert($order->getStatus() === OrderStatusEnum::COMPLETED);
});

// ========================================
// 3. INVALID TRANSITIONS (CRITICAL)
// ========================================
echo "\n3. INVALID TRANSITIONS (CRITICAL)\n";

test('❌ CREATED → COMPLETED (skip execution)', function() {
    $order = Order::create('uuid-13', 11, FinancialStatus::PAID());
    try {
        $order->complete();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'created → completed'));
    }
});

test('❌ CREATED → IN_EXECUTION (skip confirm)', function() {
    $order = Order::create('uuid-14', 12, FinancialStatus::PAID());
    try {
        $order->startExecution();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'created → in_execution'));
    }
});

test('❌ CONFIRMED → IN_EXECUTION (skip schedule)', function() {
    $order = new Order('uuid-15', 13, OrderStatusEnum::CONFIRMED, FinancialStatus::PAID());
    try {
        $order->startExecution();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'confirmed → in_execution'));
    }
});

test('❌ IN_EXECUTION → CANCELLED (cannot cancel during execution)', function() {
    $order = new Order('uuid-16', 14, OrderStatusEnum::IN_EXECUTION, FinancialStatus::PAID());
    try {
        $order->cancel();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'in_execution → cancelled'));
    }
});

test('❌ COMPLETED → SCHEDULED (backwards)', function() {
    $order = new Order('uuid-17', 15, OrderStatusEnum::COMPLETED, FinancialStatus::PAID());
    try {
        $order->schedule();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'completed → scheduled'));
    }
});

test('❌ COMPLETED → CANCELLED (cannot cancel after completion)', function() {
    $order = new Order('uuid-18', 16, OrderStatusEnum::COMPLETED, FinancialStatus::PAID());
    try {
        $order->cancel();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'completed → cancelled'));
    }
});

// ========================================
// 4. TERMINAL STATES
// ========================================
echo "\n4. TERMINAL STATES\n";

test('❌ CLOSED → CONFIRMED (terminal state)', function() {
    $order = new Order('uuid-19', 17, OrderStatusEnum::CLOSED, FinancialStatus::PAID());
    try {
        $order->confirm();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'terminal'));
    }
});

test('❌ CANCELLED → CONFIRMED (terminal state)', function() {
    $order = new Order('uuid-20', 18, OrderStatusEnum::CANCELLED, FinancialStatus::PAID());
    try {
        $order->confirm();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'terminal'));
    }
});

test('❌ CLOSED → any transition fails', function() {
    $order = new Order('uuid-21', 19, OrderStatusEnum::CLOSED, FinancialStatus::PAID());
    try {
        $order->schedule();
        throw new \Exception('Should have thrown InvalidOrderTransitionException');
    } catch (InvalidOrderTransitionException $e) {
        assert(str_contains($e->getMessage(), 'terminal'));
    }
});

test('OrderStatusEnum::CLOSED->isTerminal() returns true', function() {
    assert(OrderStatusEnum::CLOSED->isTerminal());
    assert(OrderStatusEnum::CANCELLED->isTerminal());
    assert(!OrderStatusEnum::CREATED->isTerminal());
});

// ========================================
// RESUMO
// ========================================
echo "\n=============================================================\n";
echo "RESUMO FINAL\n";
echo "=============================================================\n";
echo "Total: $total | Passou: $passed | Falhou: " . ($total - $passed) . "\n";
echo "Taxa de Sucesso: " . round(($passed / $total) * 100) . "%\n\n";

if ($passed === $total) {
    echo "✅ TODOS OS TESTES PASSARAM\n";
    echo "✅ State Machine validada\n";
    echo "✅ Transições críticas protegidas\n";
    echo "✅ Estados terminais imutáveis\n";
    echo "\n✅ DIA 2 CHECKPOINT: APROVADO\n";
    exit(0);
} else {
    echo "❌ ALGUNS TESTES FALHARAM\n";
    exit(1);
}
