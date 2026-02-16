<?php
/**
 * IssueCollectionTest - Unit Tests for IssueCollection
 *
 * GAP #4: Issue Reporting System - Test Coverage
 *
 * @package LimpVix\Tests\Domain\Execution
 * @since 1.0.0
 */

namespace LimpVix\Tests\Domain\Execution;

use PHPUnit\Framework\TestCase;
use LimpVix\Domain\Execution\Issue;
use LimpVix\Domain\Execution\ValueObjects\IssueCollection;

class IssueCollectionTest extends TestCase
{
    /**
     * @test
     */
    public function should_create_empty_collection()
    {
        $collection = IssueCollection::empty();

        $this->assertInstanceOf(IssueCollection::class, $collection);
        $this->assertEquals(0, $collection->count());
        $this->assertTrue($collection->isEmpty());
        $this->assertFalse($collection->hasOpenIssues());
    }

    /**
     * @test
     */
    public function should_add_issues()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Problem 1', 'customer', 123);
        $issue2 = Issue::create('damage', 'Problem 2', 'professional', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $this->assertEquals(2, $collection->count());
        $this->assertFalse($collection->isEmpty());
    }

    /**
     * @test
     */
    public function should_filter_by_status()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Open issue', 'customer', 123);
        $issue2 = Issue::create('damage', 'Resolved issue', 'customer', 123);
        $issue2->resolve('Fixed', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $openIssues = $collection->filterByStatus('open');
        $this->assertEquals(1, $openIssues->count());

        $resolvedIssues = $collection->filterByStatus('resolved');
        $this->assertEquals(1, $resolvedIssues->count());
    }

    /**
     * @test
     */
    public function should_filter_by_type()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Quality issue', 'customer', 123);
        $issue2 = Issue::create('damage', 'Damage issue', 'customer', 123);
        $issue3 = Issue::create('quality', 'Another quality issue', 'professional', 456);

        $collection->add($issue1);
        $collection->add($issue2);
        $collection->add($issue3);

        $qualityIssues = $collection->filterByType('quality');
        $this->assertEquals(2, $qualityIssues->count());

        $damageIssues = $collection->filterByType('damage');
        $this->assertEquals(1, $damageIssues->count());
    }

    /**
     * @test
     */
    public function should_filter_by_reported_by()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Customer issue', 'customer', 123);
        $issue2 = Issue::create('access', 'Professional issue', 'professional', 456);
        $issue3 = Issue::create('other', 'Admin issue', 'admin', 789);

        $collection->add($issue1);
        $collection->add($issue2);
        $collection->add($issue3);

        $customerIssues = $collection->filterByReportedBy('customer');
        $this->assertEquals(1, $customerIssues->count());

        $professionalIssues = $collection->filterByReportedBy('professional');
        $this->assertEquals(1, $professionalIssues->count());
    }

    /**
     * @test
     */
    public function should_get_open_issues()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Open 1', 'customer', 123);
        $issue2 = Issue::create('damage', 'Resolved', 'customer', 123);
        $issue2->resolve('Fixed', 456);
        $issue3 = Issue::create('access', 'Open 2', 'professional', 789);

        $collection->add($issue1);
        $collection->add($issue2);
        $collection->add($issue3);

        $openIssues = $collection->openIssues();
        $this->assertEquals(2, $openIssues->count());
    }

    /**
     * @test
     */
    public function should_get_resolved_issues()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Open', 'customer', 123);
        $issue2 = Issue::create('damage', 'Resolved', 'customer', 123);
        $issue2->resolve('Fixed', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $resolvedIssues = $collection->resolvedIssues();
        $this->assertEquals(1, $resolvedIssues->count());
    }

    /**
     * @test
     */
    public function should_check_has_open_issues()
    {
        $collection = IssueCollection::empty();

        $this->assertFalse($collection->hasOpenIssues());

        $issue = Issue::create('quality', 'Problem', 'customer', 123);
        $collection->add($issue);

        $this->assertTrue($collection->hasOpenIssues());

        $issue->resolve('Fixed', 456);

        $this->assertFalse($collection->hasOpenIssues());
    }

    /**
     * @test
     */
    public function should_get_issue_by_index()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'First', 'customer', 123);
        $issue2 = Issue::create('damage', 'Second', 'customer', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $this->assertInstanceOf(Issue::class, $collection->get(0));
        $this->assertEquals('First', $collection->get(0)->getDescription());

        $this->assertInstanceOf(Issue::class, $collection->get(1));
        $this->assertEquals('Second', $collection->get(1)->getDescription());

        $this->assertNull($collection->get(2));
    }

    /**
     * @test
     */
    public function should_convert_to_array()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Problem 1', 'customer', 123);
        $issue2 = Issue::create('damage', 'Problem 2', 'professional', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $array = $collection->toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);
        $this->assertIsArray($array[0]);
        $this->assertEquals('quality', $array[0]['type']);
        $this->assertEquals('damage', $array[1]['type']);
    }

    /**
     * @test
     */
    public function should_be_countable()
    {
        $collection = IssueCollection::empty();

        $this->assertEquals(0, count($collection));

        $collection->add(Issue::create('quality', 'Test', 'customer', 123));
        $this->assertEquals(1, count($collection));

        $collection->add(Issue::create('damage', 'Test 2', 'customer', 456));
        $this->assertEquals(2, count($collection));
    }

    /**
     * @test
     */
    public function should_be_iterable()
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'First', 'customer', 123);
        $issue2 = Issue::create('damage', 'Second', 'customer', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $descriptions = [];
        foreach ($collection as $issue) {
            $descriptions[] = $issue->getDescription();
        }

        $this->assertEquals(['First', 'Second'], $descriptions);
    }
}
