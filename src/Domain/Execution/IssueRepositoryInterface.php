<?php

declare(strict_types=1);

namespace LimpVix\Domain\Execution;

/**
 * IssueRepositoryInterface - Contrato para persistência de Issues
 *
 * Define as operações de persistência para o aggregate Issue.
 * Seguindo o princípio da inversão de dependências (DIP), a camada
 * de domínio depende desta interface, não da implementação concreta.
 *
 * GAP #4: Issue Reporting System
 *
 * @package LimpVix\Domain\Execution
 * @since 1.0.0 (GAP #4 Implementation)
 */
interface IssueRepositoryInterface
{
    /**
     * Persiste um Issue (insert ou update)
     *
     * @param Issue $issue
     * @return void
     */
    public function save(Issue $issue): void;

    /**
     * Busca todos os issues de uma execução
     *
     * @param string $executionUuid UUID da execução
     * @return Issue[]
     */
    public function findByExecutionUuid(string $executionUuid): array;

    /**
     * Busca issues por status
     *
     * @param string $executionUuid UUID da execução
     * @param string $status        'open' | 'investigating' | 'resolved' | 'closed'
     * @return Issue[]
     */
    public function findByStatus(string $executionUuid, string $status): array;

    /**
     * Busca issues por tipo
     *
     * @param string $executionUuid UUID da execução
     * @param string $type          'quality' | 'damage' | 'missing_items' | 'access' | 'equipment' | 'other'
     * @return Issue[]
     */
    public function findByType(string $executionUuid, string $type): array;

    /**
     * Conta issues abertos de uma execução
     *
     * @param string $executionUuid UUID da execução
     * @return int
     */
    public function countOpen(string $executionUuid): int;

    /**
     * Verifica se existem issues não resolvidos que bloqueiam a validação
     *
     * @param string $executionUuid UUID da execução
     * @return bool
     */
    public function hasBlockingIssues(string $executionUuid): bool;
}
