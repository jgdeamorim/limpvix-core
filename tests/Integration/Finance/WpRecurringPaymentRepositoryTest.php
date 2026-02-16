<?php
/**
 * WpRecurringPaymentRepositoryTest - Integration Tests
 *
 * @package LimpVix\Tests\Integration\Finance
 * @group integration
 * @group finance
 */

namespace LimpVix\Tests\Integration\Finance;

use LimpVix\Domain\Finance\RecurringPayment;
use LimpVix\Infrastructure\Persistence\Finance\WpRecurringPaymentRepository;
use PHPUnit\Framework\TestCase;

final class WpRecurringPaymentRepositoryTest extends TestCase
{
    private WpRecurringPaymentRepository $repository;
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->repository = new WpRecurringPaymentRepository();
        
        // Clean table before each test
        $this->wpdb->query("TRUNCATE TABLE {$this->wpdb->prefix}limpvix_recurring_payments");
    }

    /**
     * @test
     */
    public function it_saves_new_recurring_payment(): void
    {
        $payment = RecurringPayment::create(
            contractId: 123,
            billingCycleNumber: 1,
            amount: 150.00,
            dueDate: new \DateTimeImmutable('2026-03-15')
        );

        $this->repository->save($payment);

        $this->assertNotNull($payment->getId());
        
        // Verify in database
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->wpdb->prefix}limpvix_recurring_payments WHERE id = %d",
                $payment->getId()
            ),
            ARRAY_A
        );

        $this->assertNotNull($row);
        $this->assertEquals(123, $row['contract_id']);
        $this->assertEquals(1, $row['billing_cycle_number']);
        $this->assertEquals(150.00, $row['amount']);
        $this->assertEquals('pending', $row['status']);
    }

    /**
     * @test
     */
    public function it_finds_payment_by_uuid(): void
    {
        $payment = RecurringPayment::create(123, 1, 150.00, new \DateTimeImmutable());
        $this->repository->save($payment);

        $found = $this->repository->findByUuid($payment->getPaymentUuid());

        $this->assertNotNull($found);
        $this->assertEquals($payment->getPaymentUuid(), $found->getPaymentUuid());
    }

    /**
     * @test
     */
    public function it_finds_payment_by_contract_and_cycle(): void
    {
        $payment = RecurringPayment::create(123, 2, 150.00, new \DateTimeImmutable());
        $this->repository->save($payment);

        $found = $this->repository->findByContractAndCycle(123, 2);

        $this->assertNotNull($found);
        $this->assertEquals(2, $found->getBillingCycleNumber());
    }

    /**
     * @test
     */
    public function it_finds_retryable_payments(): void
    {
        $payment1 = RecurringPayment::create(123, 1, 150.00, new \DateTimeImmutable());
        $payment1->markAsProcessing('mp_123');
        $payment1->markAsFailed('Insufficient funds');
        $this->repository->save($payment1);

        $payment2 = RecurringPayment::create(456, 1, 200.00, new \DateTimeImmutable());
        $payment2->markAsProcessing('mp_456');
        $payment2->markAsFailed('Card expired');
        $payment2->incrementAttempt();
        $payment2->incrementAttempt();
        $payment2->incrementAttempt(); // Max attempts, not retryable
        $this->repository->save($payment2);

        $retryable = $this->repository->findRetryablePayments();

        $this->assertCount(1, $retryable);
        $this->assertEquals(123, $retryable[0]->getContractId());
    }

    protected function tearDown(): void
    {
        // Clean up
        $this->wpdb->query("TRUNCATE TABLE {$this->wpdb->prefix}limpvix_recurring_payments");
    }
}
