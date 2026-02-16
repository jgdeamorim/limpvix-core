<?php
/**
 * ApproveEvidenceTest - Tests for ApproveEvidence Use Case
 *
 * @package LimpVix\Tests\Application\UseCases\Execution
 * @group integration
 * @group evidence
 * @group gap-4
 */

namespace LimpVix\Tests\Application\UseCases\Execution;

use LimpVix\Application\UseCases\Execution\ApproveEvidence;
use PHPUnit\Framework\TestCase;

final class ApproveEvidenceTest extends TestCase
{
    private ApproveEvidence $useCase;
    private $wpdb;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;

        $this->useCase = new ApproveEvidence();
    }

    /**
     * @test
     */
    public function it_fails_when_execution_not_found(): void
    {
        $this->wpdb
            ->expects($this->once())
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 999');

        $this->wpdb
            ->expects($this->once())
            ->method('get_row')
            ->willReturn(null);

        $result = $this->useCase->execute(999, 1);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('not found', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_execution_has_no_evidence(): void
    {
        $execution = [
            'id' => 123,
            'evidence' => null,
            'evidence_status' => 'pending',
        ];

        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 123');

        $this->wpdb
            ->method('get_row')
            ->willReturn($execution);

        $result = $this->useCase->execute(123, 1);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('no evidence', $result->error());
    }

    /**
     * @test
     */
    public function it_returns_already_approved_when_evidence_already_approved(): void
    {
        $execution = [
            'id' => 123,
            'evidence' => '[{"type":"photo","url":"http://example.com/photo.jpg"}]',
            'evidence_status' => 'approved',
            'evidence_validated_at' => '2026-02-12 10:00:00',
            'evidence_validated_by' => 5,
        ];

        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 123');

        $this->wpdb
            ->method('get_row')
            ->willReturn($execution);

        $result = $this->useCase->execute(123, 1);

        $this->assertTrue($result->isOk());
        $this->assertEquals('already_approved', $result->value()['status']);
        $this->assertEquals(5, $result->value()['approved_by']);
    }

    /**
     * @test
     */
    public function it_approves_pending_evidence_successfully(): void
    {
        $execution = [
            'id' => 123,
            'evidence' => '[{"type":"photo","url":"http://example.com/photo.jpg"}]',
            'evidence_status' => 'pending',
        ];

        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 123');

        $this->wpdb
            ->method('get_row')
            ->willReturn($execution);

        $this->wpdb
            ->expects($this->once())
            ->method('update')
            ->with(
                'wp_limpvix_executions',
                $this->callback(function($data) {
                    return $data['evidence_status'] === 'approved'
                        && $data['evidence_validated_by'] === 1
                        && isset($data['evidence_validated_at']);
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $result = $this->useCase->execute(123, 1);

        $this->assertTrue($result->isOk());
        $this->assertEquals('approved', $result->value()['status']);
        $this->assertEquals(123, $result->value()['execution_id']);
        $this->assertEquals(1, $result->value()['approved_by']);
    }

    /**
     * @test
     */
    public function it_clears_rejection_reason_when_approving_previously_rejected_evidence(): void
    {
        $execution = [
            'id' => 123,
            'evidence' => '[{"type":"photo","url":"http://example.com/photo.jpg"}]',
            'evidence_status' => 'rejected',
            'evidence_rejection_reason' => 'Foto desfocada',
        ];

        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 123');

        $this->wpdb
            ->method('get_row')
            ->willReturn($execution);

        $this->wpdb
            ->expects($this->once())
            ->method('update')
            ->with(
                'wp_limpvix_executions',
                $this->callback(function($data) {
                    return $data['evidence_status'] === 'approved'
                        && $data['evidence_rejection_reason'] === null;
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $result = $this->useCase->execute(123, 1);

        $this->assertTrue($result->isOk());
        $this->assertEquals('approved', $result->value()['status']);
    }

    /**
     * @test
     */
    public function it_fails_when_database_update_fails(): void
    {
        $execution = [
            'id' => 123,
            'evidence' => '[{"type":"photo","url":"http://example.com/photo.jpg"}]',
            'evidence_status' => 'pending',
        ];

        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 123');

        $this->wpdb
            ->method('get_row')
            ->willReturn($execution);

        $this->wpdb
            ->method('update')
            ->willReturn(false);

        $result = $this->useCase->execute(123, 1);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Failed to update', $result->error());
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }
}
