<?php
declare(strict_types=1);

/**
 * IssueCollectionTest - Unit Tests for IssueCollection Value Object
 *
 * GAP #4: Issue Reporting System - Test Coverage
 *
 * Tests collection operations: add, count, filter, iterate.
 * IssueCollection implements Countable and IteratorAggregate.
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
    // ========================================
    // CREATION TESTS
    // ========================================

    /**
     * @test
     */
    public function test_create_empty_collection(): void
    {
        $collection = IssueCollection::empty();

        $this->assertInstanceOf(IssueCollection::class, $collection);
        $this->assertSame(0, $collection->count());
        $this->assertTrue($collection->isEmpty());
        $this->assertFalse($collection->hasOpenIssues());
    }

    /**
     * @test
     */
    public function test_create_from_array(): void
    {
        $issue1 = Issue::create('quality', 'Problem 1', 'customer', 123);
        $issue2 = Issue::create('damage', 'Problem 2', 'professional', 456);

        $collection = IssueCollection::fromArray([$issue1, $issue2]);

        $this->assertSame(2, $collection->count());
        $this->assertFalse($collection->isEmpty());
    }

    // ========================================
    // ADD / COUNT TESTS
    // ========================================

    /**
     * @test
     */
    public function test_add_issue_to_collection(): void
    {
        $collection = IssueCollection::empty();

        $issue = Issue::create('quality', 'Problem description', 'customer', 123);
        $collection->add($issue);

        $this->assertSame(1, $collection->count());
        $this->assertFalse($collection->isEmpty());
    }

    /**
     * @test
     */
    public function test_count_returns_correct_number(): void
    {
        $collection = IssueCollection::empty();

        $collection->add(Issue::create('quality', 'Issue 1', 'customer', 100));
        $collection->add(Issue::create('damage', 'Issue 2', 'customer', 100));
        $collection->add(Issue::create('access', 'Issue 3', 'professional', 200));

        $this->assertSame(3, $collection->count());
    }

    // ========================================
    // GETTER TESTS
    // ========================================

    /**
     * @test
     */
    public function test_get_issues_returns_all(): void
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'First', 'customer', 123);
        $issue2 = Issue::create('damage', 'Second', 'customer', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $all = $collection->all();

        $this->assertIsArray($all);
        $this->assertCount(2, $all);
        $this->assertSame($issue1, $all[0]);
        $this->assertSame($issue2, $all[1]);
    }

    /**
     * @test
     */
    public function test_get_issue_by_index(): void
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'First', 'customer', 123);
        $issue2 = Issue::create('damage', 'Second', 'customer', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $this->assertInstanceOf(Issue::class, $collection->get(0));
        $this->assertSame('First', $collection->get(0)->getDescription());
        $this->assertInstanceOf(Issue::class, $collection->get(1));
        $this->assertSame('Second', $collection->get(1)->getDescription());
        $this->assertNull($collection->get(99));
    }

    // ========================================
    // HAS UNRESOLVED / OPEN ISSUES TESTS
    // ========================================

    /**
     * @test
     */
    public function test_has_unresolved_returns_true(): void
    {
        $collection = IssueCollection::empty();

        $issue = Issue::create('quality', 'Open issue', 'customer', 123);
        $collection->add($issue);

        $this->assertTrue($collection->hasOpenIssues());
    }

    /**
     * @test
     */
    public function test_has_unresolved_returns_false(): void
    {
        $collection = IssueCollection::empty();

        // Add issue and resolve it
        $issue = Issue::create('quality', 'Resolved issue', 'customer', 123);
        $issue->resolve('Fixed the problem', 456);
        $collection->add($issue);

        $this->assertFalse($collection->hasOpenIssues());
    }

    /**
     * @test
     */
    public function test_has_unresolved_returns_false_for_empty(): void
    {
        $collection = IssueCollection::empty();
        $this->assertFalse($collection->hasOpenIssues());
    }

    // ========================================
    // FILTER TESTS
    // ========================================

    /**
     * @test
     */
    public function test_filter_by_type(): void
    {
        $collection = IssueCollection::empty();

        $collection->add(Issue::create('quality', 'Quality 1', 'customer', 123));
        $collection->add(Issue::create('damage', 'Damage 1', 'customer', 123));
        $collection->add(Issue::create('quality', 'Quality 2', 'professional', 456));

        $qualityIssues = $collection->filterByType('quality');
        $this->assertSame(2, $qualityIssues->count());

        $damageIssues = $collection->filterByType('damage');
        $this->assertSame(1, $damageIssues->count());

        $accessIssues = $collection->filterByType('access');
        $this->assertSame(0, $accessIssues->count());
    }

    /**
     * @test
     */
    public function test_filter_by_status(): void
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Open issue', 'customer', 123);
        $issue2 = Issue::create('damage', 'To be resolved', 'customer', 123);
        $issue2->resolve('Fixed', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $openIssues = $collection->filterByStatus('open');
        $this->assertSame(1, $openIssues->count());

        $resolvedIssues = $collection->filterByStatus('resolved');
        $this->assertSame(1, $resolvedIssues->count());
    }

    /**
     * @test
     */
    public function test_filter_by_reported_by(): void
    {
        $collection = IssueCollection::empty();

        $collection->add(Issue::create('quality', 'Customer issue', 'customer', 123));
        $collection->add(Issue::create('access', 'Professional issue', 'professional', 456));
        $collection->add(Issue::create('other', 'Admin issue', 'admin', 789));

        $customerIssues = $collection->filterByReportedBy('customer');
        $this->assertSame(1, $customerIssues->count());

        $professionalIssues = $collection->filterByReportedBy('professional');
        $this->assertSame(1, $professionalIssues->count());

        $adminIssues = $collection->filterByReportedBy('admin');
        $this->assertSame(1, $adminIssues->count());
    }

    /**
     * @test
     */
    public function test_open_issues_filter(): void
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
        $this->assertSame(2, $openIssues->count());
    }

    /**
     * @test
     */
    public function test_resolved_issues_filter(): void
    {
        $collection = IssueCollection::empty();

        $issue1 = Issue::create('quality', 'Open', 'customer', 123);
        $issue2 = Issue::create('damage', 'Resolved', 'customer', 123);
        $issue2->resolve('Fixed', 456);

        $collection->add($issue1);
        $collection->add($issue2);

        $resolvedIssues = $collection->resolvedIssues();
        $this->assertSame(1, $resolvedIssues->count());
    }

    // ========================================
    // TO_ARRAY TESTS
    // ========================================

    /**
     * @test
     */
    public function test_to_array(): void
    {
        $collection = IssueCollection::empty();

        $collection->add(Issue::create('quality', 'Problem 1', 'customer', 123));
        $collection->add(Issue::create('damage', 'Problem 2', 'professional', 456));

        $array = $collection->toArray();

        $this->assertIsArray($array);
        $this->assertCount(2, $array);
        $this->assertIsArray($array[0]);
        $this->assertSame('quality', $array[0]['type']);
        $this->assertSame('Problem 1', $array[0]['description']);
        $this->assertSame('damage', $array[1]['type']);
        $this->assertSame('Problem 2', $array[1]['description']);
    }

    /**
     * @test
     */
    public function test_to_array_empty_collection(): void
    {
        $collection = IssueCollection::empty();
        $array = $collection->toArray();

        $this->assertIsArray($array);
        $this->assertEmpty($array);
    }

    // ========================================
    // COUNTABLE / ITERATOR TESTS
    // ========================================

    /**
     * @test
     */
    public function test_collection_is_countable(): void
    {
        $collection = IssueCollection::empty();

        $this->assertSame(0, count($collection));

        $collection->add(Issue::create('quality', 'Test', 'customer', 123));
        $this->assertSame(1, count($collection));

        $collection->add(Issue::create('damage', 'Test 2', 'customer', 456));
        $this->assertSame(2, count($collection));
    }

    /**
     * @test
     */
    public function test_collection_is_iterable(): void
    {
        $collection = IssueCollection::empty();

        $collection->add(Issue::create('quality', 'First', 'customer', 123));
        $collection->add(Issue::create('damage', 'Second', 'customer', 456));

        $descriptions = [];
        foreach ($collection as $issue) {
            $descriptions[] = $issue->getDescription();
        }

        $this->assertSame(['First', 'Second'], $descriptions);
    }

    /**
     * @test
     */
    public function test_collection_implements_countable_interface(): void
    {
        $collection = IssueCollection::empty();
        $this->assertInstanceOf(\Countable::class, $collection);
    }

    /**
     * @test
     */
    public function test_collection_implements_iterator_aggregate(): void
    {
        $collection = IssueCollection::empty();
        $this->assertInstanceOf(\IteratorAggregate::class, $collection);
    }

    // ========================================
    // EMPTY STATE TESTS
    // ========================================

    /**
     * @test
     */
    public function test_is_empty_on_new_collection(): void
    {
        $collection = IssueCollection::empty();
        $this->assertTrue($collection->isEmpty());
    }

    /**
     * @test
     */
    public function test_is_not_empty_after_adding(): void
    {
        $collection = IssueCollection::empty();
        $collection->add(Issue::create('quality', 'Problem', 'customer', 123));
        $this->assertFalse($collection->isEmpty());
    }
}
