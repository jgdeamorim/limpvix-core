<?php
/**
 * IssueTest - Unit Tests for Issue Value Object
 *
 * GAP #4: Issue Reporting System - Test Coverage
 *
 * @package LimpVix\Tests\Domain\Execution
 * @since 1.0.0
 */

namespace LimpVix\Tests\Domain\Execution;

use PHPUnit\Framework\TestCase;
use LimpVix\Domain\Execution\Issue;

class IssueTest extends TestCase
{
    /**
     * @test
     */
    public function should_create_issue_with_valid_data()
    {
        $issue = Issue::create(
            'quality',
            'Serviço mal feito, chão não foi lavado',
            'customer',
            123,
            ['https://example.com/photo1.jpg']
        );

        $this->assertInstanceOf(Issue::class, $issue);
        $this->assertEquals('quality', $issue->getType());
        $this->assertEquals('Serviço mal feito, chão não foi lavado', $issue->getDescription());
        $this->assertEquals('customer', $issue->getReportedBy());
        $this->assertEquals(123, $issue->getReportedByUserId());
        $this->assertEquals('open', $issue->getStatus());
        $this->assertTrue($issue->isOpen());
        $this->assertFalse($issue->isResolved());
        $this->assertCount(1, $issue->getEvidenceUrls());
    }

    /**
     * @test
     */
    public function should_reject_invalid_issue_type()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid issue type: invalid_type');

        Issue::create(
            'invalid_type',
            'Some description',
            'customer',
            123
        );
    }

    /**
     * @test
     */
    public function should_reject_invalid_reported_by()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid reportedBy: invalid_reporter');

        Issue::create(
            'quality',
            'Some description',
            'invalid_reporter',
            123
        );
    }

    /**
     * @test
     */
    public function should_reject_empty_description()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Issue description cannot be empty');

        Issue::create(
            'quality',
            '',
            'customer',
            123
        );
    }

    /**
     * @test
     */
    public function should_resolve_issue()
    {
        $issue = Issue::create('damage', 'Vaso quebrado', 'customer', 123);

        $issue->resolve('Vaso foi reposto pelo profissional', 456);

        $this->assertEquals('resolved', $issue->getStatus());
        $this->assertEquals('Vaso foi reposto pelo profissional', $issue->getResolution());
        $this->assertEquals(456, $issue->getResolvedByUserId());
        $this->assertTrue($issue->isResolved());
        $this->assertFalse($issue->isOpen());
        $this->assertInstanceOf(\DateTimeImmutable::class, $issue->getResolvedAt());
    }

    /**
     * @test
     */
    public function should_not_resolve_already_resolved_issue()
    {
        $issue = Issue::create('quality', 'Problem', 'customer', 123);
        $issue->resolve('Fixed', 456);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Issue already resolved or closed');

        $issue->resolve('Trying to resolve again', 789);
    }

    /**
     * @test
     */
    public function should_mark_as_investigating()
    {
        $issue = Issue::create('access', 'Portão trancado', 'professional', 123);

        $issue->markAsInvestigating();

        $this->assertEquals('investigating', $issue->getStatus());
    }

    /**
     * @test
     */
    public function should_close_resolved_issue()
    {
        $issue = Issue::create('equipment', 'Aspirador quebrou', 'professional', 123);
        $issue->resolve('Equipamento substituído', 456);

        $issue->close();

        $this->assertEquals('closed', $issue->getStatus());
        $this->assertTrue($issue->isResolved());
    }

    /**
     * @test
     */
    public function should_not_close_non_resolved_issue()
    {
        $issue = Issue::create('other', 'Algum problema', 'admin', 123);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Can only close resolved issues');

        $issue->close();
    }

    /**
     * @test
     */
    public function should_add_evidence()
    {
        $issue = Issue::create('damage', 'Mesa riscada', 'customer', 123);

        $issue->addEvidence('https://example.com/scratch1.jpg');
        $issue->addEvidence('https://example.com/scratch2.jpg');

        $this->assertCount(2, $issue->getEvidenceUrls());
    }

    /**
     * @test
     */
    public function should_convert_to_array()
    {
        $issue = Issue::create(
            'missing_items',
            'Detergente não foi usado',
            'customer',
            123,
            ['https://example.com/evidence.jpg']
        );

        $array = $issue->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('missing_items', $array['type']);
        $this->assertEquals('Detergente não foi usado', $array['description']);
        $this->assertEquals('customer', $array['reported_by']);
        $this->assertEquals(123, $array['reported_by_user_id']);
        $this->assertEquals('open', $array['status']);
        $this->assertArrayHasKey('reported_at', $array);
        $this->assertJson($array['evidence_urls']);
    }

    /**
     * @test
     */
    public function should_get_type_label()
    {
        $issue = Issue::create('quality', 'Test', 'customer', 123);
        $this->assertEquals('Problema de Qualidade', $issue->getTypeLabel());

        $issue2 = Issue::create('damage', 'Test', 'customer', 123);
        $this->assertEquals('Dano Causado', $issue2->getTypeLabel());

        $issue3 = Issue::create('missing_items', 'Test', 'customer', 123);
        $this->assertEquals('Itens Faltando', $issue3->getTypeLabel());
    }

    /**
     * @test
     */
    public function should_get_status_color()
    {
        $issue = Issue::create('quality', 'Test', 'customer', 123);
        $this->assertEquals('red', $issue->getStatusColor());

        $issue->markAsInvestigating();
        $this->assertEquals('orange', $issue->getStatusColor());

        $issue->resolve('Fixed', 456);
        $this->assertEquals('green', $issue->getStatusColor());

        $issue->close();
        $this->assertEquals('gray', $issue->getStatusColor());
    }
}
