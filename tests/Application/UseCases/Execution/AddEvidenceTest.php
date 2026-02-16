<?php
/**
 * AddEvidenceTest - Tests for AddEvidence Use Case
 *
 * @package LimpVix\Tests\Application\UseCases\Execution
 * @group integration
 * @group evidence
 * @group gap-5
 */

namespace LimpVix\Tests\Application\UseCases\Execution;

use LimpVix\Application\UseCases\Execution\AddEvidence;
use PHPUnit\Framework\TestCase;

final class AddEvidenceTest extends TestCase
{
    private AddEvidence $useCase;
    private $wpdb;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = $this->createMock(\wpdb::class);
        $wpdb->prefix = 'wp_';
        $this->wpdb = $wpdb;

        $this->useCase = new AddEvidence();
    }

    /**
     * @test
     */
    public function it_fails_when_evidence_type_invalid(): void
    {
        $result = $this->useCase->execute(123, 'invalid', 'http://example.com/photo.jpg');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Invalid evidence type', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_url_invalid(): void
    {
        $result = $this->useCase->execute(123, 'photo', 'not-a-url');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Invalid evidence URL', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_url_empty(): void
    {
        $result = $this->useCase->execute(123, 'photo', '');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Invalid evidence URL', $result->error());
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

        $result = $this->useCase->execute(999, 'photo', 'http://example.com/photo.jpg');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('not found', $result->error());
    }

    /**
     * @test
     */
    public function it_adds_first_evidence_successfully(): void
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

        $this->wpdb
            ->expects($this->once())
            ->method('update')
            ->with(
                'wp_limpvix_executions',
                $this->callback(function($data) {
                    $evidence = json_decode($data['evidence'], true);
                    return is_array($evidence)
                        && count($evidence) === 1
                        && $evidence[0]['type'] === 'photo'
                        && $evidence[0]['url'] === 'http://example.com/photo.jpg'
                        && isset($evidence[0]['uploadedAt']);
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $result = $this->useCase->execute(123, 'photo', 'http://example.com/photo.jpg');

        $this->assertTrue($result->isOk());
        $this->assertEquals(1, $result->value()['evidence_count']);
        $this->assertEquals('photo', $result->value()['new_evidence']['type']);
        $this->assertEquals('http://example.com/photo.jpg', $result->value()['new_evidence']['url']);
    }

    /**
     * @test
     */
    public function it_adds_additional_evidence_to_existing_collection(): void
    {
        $existingEvidence = [
            ['type' => 'photo', 'url' => 'http://example.com/photo1.jpg', 'uploadedAt' => '2026-02-12 10:00:00'],
        ];

        $execution = [
            'id' => 123,
            'evidence' => json_encode($existingEvidence),
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
                    return is_array($evidence)
                        && count($evidence) === 2
                        && $evidence[1]['type'] === 'video'
                        && $evidence[1]['url'] === 'http://example.com/video.mp4';
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $result = $this->useCase->execute(123, 'video', 'http://example.com/video.mp4');

        $this->assertTrue($result->isOk());
        $this->assertEquals(2, $result->value()['evidence_count']);
        $this->assertEquals('video', $result->value()['new_evidence']['type']);
    }

    /**
     * @test
     */
    public function it_fails_when_database_update_fails(): void
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

        $this->wpdb
            ->method('update')
            ->willReturn(false);

        $result = $this->useCase->execute(123, 'photo', 'http://example.com/photo.jpg');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Failed to add evidence', $result->error());
    }

    /**
     * @test
     */
    public function it_adds_multiple_evidences_at_once(): void
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

        $this->wpdb
            ->expects($this->once())
            ->method('update')
            ->with(
                'wp_limpvix_executions',
                $this->callback(function($data) {
                    $evidence = json_decode($data['evidence'], true);
                    return count($evidence) === 3;
                }),
                ['id' => 123]
            )
            ->willReturn(1);

        $evidences = [
            ['type' => 'photo', 'url' => 'http://example.com/photo1.jpg'],
            ['type' => 'photo', 'url' => 'http://example.com/photo2.jpg'],
            ['type' => 'video', 'url' => 'http://example.com/video.mp4'],
        ];

        $result = $this->useCase->executeMultiple(123, $evidences);

        $this->assertTrue($result->isOk());
        $this->assertEquals(3, $result->value()['evidence_count']);
        $this->assertEquals(3, $result->value()['added_count']);
    }

    /**
     * @test
     */
    public function it_fails_multiple_when_evidences_empty(): void
    {
        $result = $this->useCase->executeMultiple(123, []);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('No evidences provided', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_multiple_when_evidence_missing_fields(): void
    {
        $evidences = [
            ['type' => 'photo'], // Missing 'url'
        ];

        $result = $this->useCase->executeMultiple(123, $evidences);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('must have "type" and "url"', $result->error());
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }
}
