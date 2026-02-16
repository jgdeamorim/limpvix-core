<?php
declare(strict_types=1);

/**
 * WpPayoutRepositoryTest - Integration Test
 *
 * OBJECTIVE:
 * - Validate PayoutRepositoryInterface implementation
 * - Validate CRUD operations work correctly
 * - Validate table name is correct (limpvix_payouts, not limpvix_mp_payouts)
 *
 * CRITICAL TESTS:
 * - ✅ Create payout persists correctly
 * - ✅ GetById returns correct data
 * - ✅ UpdateStatus changes status and timestamps
 * - ✅ Uses correct table name
 *
 * Part of: Financial Regression Safety Sprint
 *
 * @package LimpVix\Tests\Integration\Finance
 */

namespace LimpVix\Tests\Integration\Finance;

use WP_UnitTestCase;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;

class WpPayoutRepositoryTest extends WP_UnitTestCase
{
    private $repository;
    private $wpdb;

    public function setUp(): void
    {
        parent::setUp();
        
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->repository = new WpPayoutRepository();
    }

    /**
     * @test
     * CRITICAL: Verify repository uses correct table name
     */
    public function it_uses_correct_table_name(): void
    {
        global $wpdb;
        
        $reflection = new \ReflectionClass($this->repository);
        $tableProperty = $reflection->getProperty('table');
        $tableProperty->setAccessible(true);
        $tableName = $tableProperty->getValue($this->repository);

        $expectedTableName = $wpdb->prefix . 'limpvix_payouts';
        
        $this->assertEquals(
            $expectedTableName,
            $tableName,
            'Repository MUST use wp_limpvix_payouts (not wp_limpvix_mp_payouts)'
        );
    }

    /**
     * @test
     * Create payout persists correctly
     */
    public function it_creates_payout_correctly(): void
    {
        // Arrange
        $data = [
            'order_id' => 123,
            'professional_id' => 456,
            'gross_amount' => 100.00,
            'platform_fee' => 15.00,
            'net_amount' => 85.00,
            'status' => 'pending',
            'gateway' => 'mercadopago',
            'recipient_type' => 'pix',
            'recipient_key' => '+5527999999999',
            'recipient_name' => 'Test Professional',
        ];

        // Act
        $payoutId = $this->repository->create($data);

        // Assert
        $this->assertIsInt($payoutId);
        $this->assertGreaterThan(0, $payoutId);

        // Verify persisted
        $payout = $this->repository->getById($payoutId);
        $this->assertNotNull($payout);
        $this->assertEquals('pending', $payout['status']);
        $this->assertEquals(85.00, (float) $payout['net_amount']);
    }

    /**
     * @test
     * UpdateStatus changes status and timestamps correctly
     */
    public function it_updates_status_correctly(): void
    {
        // Arrange
        $payoutId = $this->repository->create([
            'order_id' => 123,
            'professional_id' => 456,
            'net_amount' => 85.00,
            'status' => 'pending',
        ]);

        // Act
        $result = $this->repository->updateStatus($payoutId, 'processing');

        // Assert
        $this->assertTrue($result, 'updateStatus should return true on success');

        $payout = $this->repository->getById($payoutId);
        $this->assertEquals('processing', $payout['status']);
        $this->assertNotNull($payout['processed_at'], 'processed_at timestamp should be set');
    }

    /**
     * @test
     * GetById returns null for non-existent payout
     */
    public function it_returns_null_for_non_existent_payout(): void
    {
        $payout = $this->repository->getById(999999);
        
        $this->assertNull($payout);
    }

    /**
     * @test
     * GetByStatus filters correctly
     */
    public function it_filters_by_status_correctly(): void
    {
        // Arrange
        $this->repository->create(['order_id' => 1, 'professional_id' => 1, 'net_amount' => 100, 'status' => 'pending']);
        $this->repository->create(['order_id' => 2, 'professional_id' => 1, 'net_amount' => 100, 'status' => 'completed']);
        $this->repository->create(['order_id' => 3, 'professional_id' => 1, 'net_amount' => 100, 'status' => 'pending']);

        // Act
        $pending = $this->repository->getByStatus('pending');

        // Assert
        $this->assertGreaterThanOrEqual(2, count($pending));
        
        foreach ($pending as $payout) {
            $this->assertEquals('pending', $payout['status']);
        }
    }
}
