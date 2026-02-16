<?php
/**
 * RemoveEvidenceTest - Tests for RemoveEvidence Use Case
 *
 * @package LimpVix\Tests\Application\UseCases\Execution
 * @group integration
 * @group evidence
 * @group gap-5
 */

namespace LimpVix\Tests\Application\UseCases\Execution;

use LimpVix\Application\UseCases\Execution\RemoveEvidence;
use PHPUnit\Framework\TestCase;

final class RemoveEvidenceTest extends TestCase
{
    private RemoveEvidence $useCase;
    private $wpdb;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;

        $this->useCase = new RemoveEvidence();
    }

    /**
     * @test
     */
    public function it_fails_when_execution_not_found(): void
    {
        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 999');

        $this->wpdb
            ->method('get_row')
            ->willReturn(null);

        $result = $this->useCase->execute(999, 0, 1);

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
        ];

        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 123');

        $this->wpdb
            ->method('get_row')
            ->willReturn($execution);

        $result = $this->useCase->execute(123, 0, 1);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('no evidence', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_evidence_index_invalid(): void
    {
        $evidences = [
            ['type' => 'photo', 'url' => 'http://example.com/photo.jpg', 'uploadedAt' => '2026-02-12 10:00:00'],
        ];

        $execution = [
            'id' => 123,
            'evidence' => json_encode($evidences),
        ];

        $this->wpdb
            ->method('prepare')
            ->willReturn('SELECT * FROM wp_limpvix_executions WHERE id = 123');

        $this->wpdb
            ->method('get_row')
            ->willReturn($execution);

        $result = $this->useCase->execute(123, 5, 1); // Index 5 doesn't exist

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Evidence index 5 not found', $result->error());
    }

    /**
     * @test
     */
    public function it_removes_evidence_successfully(): void
    {
        $evidences = [
            ['type' => 'photo', 'url' => 'http://example.com/photo1.jpg', 'uploadedAt' => '2026-02-12 10:00:00'],
            ['type' => 'video', 'url' => 'http://example.com/video.mp4', 'uploadedAt' => '2026-02-12 10:05:00'],
            ['type' => 'photo', 'url' => 'http://example.com/photo2.jpg', 'uploadedAt' => '2026-02-12 10:10:00'],
        ];

        $execution = [
            'id' => 123,
            'evidence' => json_encode($evidences),
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
                    $evidence = json_decode($data['evidence'], true);
                    return is_array($evidence) && count($evidence) === 2;
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $result = $this->useCase->execute(123, 1, 1); // Remove index 1 (video)

        $this->assertTrue($result->isOk());
        $this->assertEquals(2, $result->value()['evidence_count']);
        $this->assertEquals('video', $result->value()['removed_evidence']['type']);
    }

    /**
     * @test
     */
    public function it_sets_evidence_to_null_when_removing_last_evidence(): void
    {
        $evidences = [
            ['type' => 'photo', 'url' => 'http://example.com/photo.jpg', 'uploadedAt' => '2026-02-12 10:00:00'],
        ];

        $execution = [
            'id' => 123,
            'evidence' => json_encode($evidences),
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
                    return $data['evidence'] === null;
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $result = $this->useCase->execute(123, 0, 1);

        $this->assertTrue($result->isOk());
        $this->assertEquals(0, $result->value()['evidence_count']);
    }

    /**
     * @test
     */
    public function it_fails_when_database_update_fails(): void
    {
        $evidences = [
            ['type' => 'photo', 'url' => 'http://example.com/photo.jpg', 'uploadedAt' => '2026-02-12 10:00:00'],
        ];

        $execution = [
            'id' => 123,
            'evidence' => json_encode($evidences),
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

        $result = $this->useCase->execute(123, 0, 1);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Failed to remove evidence', $result->error());
    }

    /**
     * @test
     */
    public function it_removes_all_evidences_with_reason(): void
    {
        $evidences = [
            ['type' => 'photo', 'url' => 'http://example.com/photo1.jpg', 'uploadedAt' => '2026-02-12 10:00:00'],
            ['type' => 'photo', 'url' => 'http://example.com/photo2.jpg', 'uploadedAt' => '2026-02-12 10:05:00'],
        ];

        $execution = [
            'id' => 123,
            'evidence' => json_encode($evidences),
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
                    return $data['evidence'] === null
                        && $data['evidence_status'] === 'pending'
                        && !empty($data['evidence_rejection_reason']);
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $result = $this->useCase->executeRemoveAll(123, 1, 'Evidências inapropriadas - conteúdo impróprio detectado');

        $this->assertTrue($result->isOk());
        $this->assertEquals(0, $result->value()['evidence_count']);
    }

    /**
     * @test
     */
    public function it_fails_remove_all_when_reason_too_short(): void
    {
        $result = $this->useCase->executeRemoveAll(123, 1, 'Ruim');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('at least 10 characters', $result->error());
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }
}
