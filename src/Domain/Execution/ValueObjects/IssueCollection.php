<?php
/**
 * IssueCollection - Value Object Collection
 *
 * Gerencia coleção de Issues de uma Execution
 *
 * GAP #4: Issue Reporting System
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 * @since 1.0.0 (GAP #4 Implementation)
 */

namespace LimpVix\Domain\Execution\ValueObjects;

use LimpVix\Domain\Execution\Issue;

defined('ABSPATH') || exit;

final class IssueCollection implements \Countable, \IteratorAggregate
{
    /** @var Issue[] */
    private array $issues;

    private function __construct(array $issues = [])
    {
        $this->issues = [];
        foreach ($issues as $issue) {
            $this->add($issue);
        }
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromArray(array $issues): self
    {
        return new self($issues);
    }

    public function add(Issue $issue): void
    {
        $this->issues[] = $issue;
    }

    public function count(): int
    {
        return count($this->issues);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->issues);
    }

    public function toArray(): array
    {
        return array_map(fn(Issue $issue) => $issue->toArray(), $this->issues);
    }

    /**
     * Filtrar issues por status
     */
    public function filterByStatus(string $status): self
    {
        $filtered = array_filter(
            $this->issues,
            fn(Issue $issue) => $issue->getStatus() === $status
        );

        return new self(array_values($filtered));
    }

    /**
     * Filtrar issues por tipo
     */
    public function filterByType(string $type): self
    {
        $filtered = array_filter(
            $this->issues,
            fn(Issue $issue) => $issue->getType() === $type
        );

        return new self(array_values($filtered));
    }

    /**
     * Filtrar issues reportados por
     */
    public function filterByReportedBy(string $reportedBy): self
    {
        $filtered = array_filter(
            $this->issues,
            fn(Issue $issue) => $issue->getReportedBy() === $reportedBy
        );

        return new self(array_values($filtered));
    }

    /**
     * Obter issues abertos
     */
    public function openIssues(): self
    {
        return $this->filterByStatus('open');
    }

    /**
     * Obter issues resolvidos
     */
    public function resolvedIssues(): self
    {
        $filtered = array_filter(
            $this->issues,
            fn(Issue $issue) => $issue->isResolved()
        );

        return new self(array_values($filtered));
    }

    /**
     * Verificar se tem issues abertos
     */
    public function hasOpenIssues(): bool
    {
        return $this->openIssues()->count() > 0;
    }

    /**
     * Verificar se está vazio
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Obter issue por índice
     */
    public function get(int $index): ?Issue
    {
        return $this->issues[$index] ?? null;
    }

    /**
     * Obter todos os issues
     */
    public function all(): array
    {
        return $this->issues;
    }
}
