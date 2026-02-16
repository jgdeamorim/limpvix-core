<?php
/**
 * SendOffersTest - Integration Tests for SendOffers Use Case
 *
 * @package LimpVix\Tests\Application\UseCases\Briefing
 * @group integration
 * @group matching
 * @group gap-3
 */

namespace LimpVix\Tests\Application\UseCases\Briefing;

use LimpVix\Application\UseCase\Briefing\SendOffers;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use LimpVix\Domain\Professional\Professional;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractId;
use PHPUnit\Framework\TestCase;

final class SendOffersTest extends TestCase
{
    private SendOffers $useCase;
    private $professionalRepo;
    private $contractRepo;
    private $wpdb;

    protected function setUp(): void
    {
        $this->professionalRepo = $this->createMock(ProfessionalRepositoryInterface::class);
        $this->contractRepo = $this->createMock(ContractRepositoryInterface::class);
        
        // Mock wpdb
        global $wpdb;
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;

        $this->useCase = new SendOffers(
            $this->professionalRepo,
            $this->contractRepo
        );
    }

    /**
     * @test
     */
    public function it_throws_exception_when_contract_not_found(): void
    {
        $this->contractRepo
            ->method('findById')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contract #999 not found');

        $this->useCase->execute(999);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_service_address_missing(): void
    {
        $contract = $this->createMock(Contract::class);
        $contract->method('getServiceCode')->willReturn('residential_basic');
        $contract->method('getStartDate')->willReturn(new \DateTimeImmutable());
        $contract->method('getMonthlyValue')->willReturn(150.00);

        $this->contractRepo
            ->method('findById')
            ->willReturn($contract);

        // Mock wpdb to return null for service_address
        $this->wpdb
            ->expects($this->once())
            ->method('get_var')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no service address');

        $this->useCase->execute(123);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_coordinates_invalid(): void
    {
        $contract = $this->createMock(Contract::class);
        $contract->method('getServiceCode')->willReturn('residential_basic');

        $this->contractRepo
            ->method('findById')
            ->willReturn($contract);

        // Mock wpdb to return address with zero coordinates
        $this->wpdb
            ->method('get_var')
            ->willReturn(json_encode([
                'coordinates' => ['latitude' => 0.0, 'longitude' => 0.0]
            ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has invalid coordinates');

        $this->useCase->execute(123);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_contract_has_pending_offers(): void
    {
        $contract = $this->createMock(Contract::class);
        $contract->method('getServiceCode')->willReturn('residential_basic');

        $this->contractRepo
            ->method('findById')
            ->willReturn($contract);

        // Mock service_address with valid coordinates
        $this->wpdb
            ->method('get_var')
            ->will($this->onConsecutiveCalls(
                json_encode([
                    'coordinates' => ['latitude' => -20.3155, 'longitude' => -40.3128]
                ]),
                5 // Existing pending offers
            ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already has 5 pending offers');

        $this->useCase->execute(123);
    }

    /**
     * @test
     */
    public function it_throws_exception_when_no_eligible_professionals_found(): void
    {
        $contract = $this->createMock(Contract::class);
        $contract->method('getServiceCode')->willReturn('residential_basic');
        $contract->method('getStartDate')->willReturn(new \DateTimeImmutable());
        $contract->method('getMonthlyValue')->willReturn(150.00);

        $this->contractRepo
            ->method('findById')
            ->willReturn($contract);

        // Mock service_address
        $this->wpdb
            ->method('get_var')
            ->will($this->onConsecutiveCalls(
                json_encode([
                    'coordinates' => ['latitude' => -20.3155, 'longitude' => -40.3128]
                ]),
                0 // No pending offers
            ));

        // Mock prepares
        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT COUNT(*) FROM wp_limpvix_contract_offers WHERE contract_id = 123 AND status = \'pending\'');

        // No eligible professionals
        $this->professionalRepo
            ->method('findEligibleFor')
            ->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No eligible professionals found');

        $this->useCase->execute(123);
    }

    /**
     * @test
     */
    public function it_sends_offers_to_top_professionals_successfully(): void
    {
        $this->markTestSkipped('Requires full wpdb mock setup - complex dependencies');
        
        // This test would require:
        // - Full Contract mock with service_address
        // - Full Professional mocks with service regions, scores, etc.
        // - wpdb mock for insert operations
        // - WordPress action hooks (do_action)
        // Too complex for current scope
    }

    /**
     * @test
     */
    public function it_calculates_match_scores_correctly(): void
    {
        $this->markTestSkipped('Requires reflection to test private methods or refactor to service');
    }

    /**
     * @test
     */
    public function it_ranks_professionals_by_score_descending(): void
    {
        $this->markTestSkipped('Requires professional matching service extraction');
    }

    /**
     * @test
     */
    public function it_limits_offers_to_specified_count(): void
    {
        $this->markTestSkipped('Requires full integration setup');
    }

    /**
     * @test
     */
    public function it_expires_pending_offers_correctly(): void
    {
        // Test the expirePendingOffers method
        $this->wpdb
            ->expects($this->once())
            ->method('update')
            ->with(
                'wp_limpvix_contract_offers',
                $this->arrayHasKey('status'),
                ['contract_id' => 123, 'status' => 'pending']
            )
            ->willReturn(5); // 5 offers expired

        $result = $this->useCase->expirePendingOffers(123);

        $this->assertEquals(5, $result);
    }

    /**
     * @test
     */
    public function it_uses_haversine_formula_for_distance_calculation(): void
    {
        $this->markTestSkipped('Requires reflection to test private calculateDistance method');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }
}
