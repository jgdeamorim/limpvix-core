<?php
/**
 * RejectEvidenceTest - Tests for RejectEvidence Use Case
 *
 * @package LimpVix\Tests\Application\UseCases\Execution
 * @group integration
 * @group evidence  
 * @group gap-4
 */

namespace LimpVix\Tests\Application\UseCases\Execution;

use LimpVix\Application\UseCases\Execution\RejectEvidence;
use PHPUnit\Framework\TestCase;

final class RejectEvidenceTest extends TestCase
{
    private RejectEvidence $useCase;
    private $wpdb;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;

        $this->useCase = new RejectEvidence();
    }

    /**
     * @test
     */
    public function it_fails_when_rejection_reason_is_empty(): void
    {
        $result = $this->useCase->execute(123, 1, '');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('required', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_rejection_reason_too_short(): void
    {
        $result = $this->useCase->execute(123, 1, 'Ruim');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('at least 10 characters', $result->error());
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

        $result = $this->useCase->execute(999, 1, 'Foto está muito desfocada');

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

        $result = $this->useCase->execute(123, 1, 'Sem evidências para rejeitar');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('no evidence', $result->error());
    }

    /**
     * @test
     */
    public function it_returns_already_rejected_when_same_reason(): void
    {
        $reason = 'Foto está muito desfocada e não mostra o trabalho completo';

        $execution = [
            'id' => 123,
            'evidence' => '[{"type":"photo","url":"http://example.com/photo.jpg"}]',
            'evidence_status' => 'rejected',
            'evidence_rejection_reason' => $reason,
            'evidence_validated_at' => '2026-02-12 10:00:00',
            'evidence_validated_by' => 5,
        ];

        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 123');

        $this->wpdb
            ->method('get_row')
            ->willReturn($execution);

        $result = $this->useCase->execute(123, 1, $reason);

        $this->assertTrue($result->isOk());
        $this->assertEquals('already_rejected', $result->value()['status']);
        $this->assertEquals($reason, $result->value()['rejection_reason']);
    }

    /**
     * @test
     */
    public function it_rejects_pending_evidence_successfully(): void
    {
        $reason = 'Foto está muito desfocada, por favor tire outra mais nítida';

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
                $this->callback(function($data) use ($reason) {
                    return $data['evidence_status'] === 'rejected'
                        && $data['evidence_validated_by'] === 1
                        && $data['evidence_rejection_reason'] === $reason
                        && isset($data['evidence_validated_at']);
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $result = $this->useCase->execute(123, 1, $reason);

        $this->assertTrue($result->isOk());
        $this->assertEquals('rejected', $result->value()['status']);
        $this->assertEquals(123, $result->value()['execution_id']);
        $this->assertEquals($reason, $result->value()['rejection_reason']);
        $this->assertEquals(1, $result->value()['rejected_by']);
    }

    /**
     * @test
     */
    public function it_rejects_approved_evidence_with_new_reason(): void
    {
        $newReason = 'Após revisão, percebemos que a área mostrada não corresponde ao endereço do serviço';

        $execution = [
            'id' => 123,
            'evidence' => '[{"type":"photo","url":"http://example.com/photo.jpg"}]',
            'evidence_status' => 'approved',
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
            ->willReturn(1);

        $result = $this->useCase->execute(123, 1, $newReason);

        $this->assertTrue($result->isOk());
        $this->assertEquals('rejected', $result->value()['status']);
        $this->assertEquals($newReason, $result->value()['rejection_reason']);
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

        $result = $this->useCase->execute(123, 1, 'Motivo válido de rejeição');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Failed to update', $result->error());
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }
}
