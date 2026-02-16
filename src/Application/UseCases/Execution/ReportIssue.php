<?php
declare(strict_types=1);

/**
 * ReportIssue - Use Case para reportar problemas durante execução
 *
 * GAP #4: Issue Reporting System
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Validar permissões (customer/professional pode reportar)
 * - Orquestrar Execution::reportIssue()
 * - Persistir mudanças
 * - Disparar notificação para admin
 *
 * TIPOS DE ISSUES:
 * - quality: Problema de qualidade
 * - damage: Dano causado
 * - missing_items: Itens faltando
 * - access: Problema de acesso
 * - equipment: Problema com equipamento
 * - other: Outros
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 1.0.0 (GAP #4 Implementation)
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;

defined('ABSPATH') || exit;

class ReportIssue
{
    public function __construct(
        private ExecutionRepositoryInterface $executionRepository
    ) {}

    /**
     * Executar Use Case
     *
     * @param string $executionUuid Execution UUID
     * @param string $type Issue type
     * @param string $description Issue description
     * @param string $reportedBy Who reported (customer, professional, admin)
     * @param int $reportedByUserId User ID of reporter
     * @param array $evidenceUrls URLs of photos/videos
     * @return Result<array, string>
     */
    public function execute(
        string $executionUuid,
        string $type,
        string $description,
        string $reportedBy,
        int $reportedByUserId,
        array $evidenceUrls = []
    ): Result {
        try {
            // 1. Buscar Execution
            $execution = $this->executionRepository->findByUuid($executionUuid);

            if ($execution === null) {
                return Result::fail(sprintf(
                    'Execution not found: %s',
                    $executionUuid
                ));
            }

            // 2. Validar permissões
            // TODO: Adicionar validação de que reportedByUserId realmente é customer ou professional desta execution

            // 3. Report issue no domain
            $execution->reportIssue($type, $description, $reportedBy, $reportedByUserId, $evidenceUrls);

            // 4. Persistir mudanças
            $this->executionRepository->save($execution);

            // 5. Retornar sucesso
            return Result::ok([
                'execution_uuid' => $execution->getExecutionUuid(),
                'issue_reported' => true,
                'issues_count' => $execution->getIssues()->count(),
                'has_open_issues' => $execution->hasOpenIssues(),
            ]);

        } catch (\InvalidArgumentException $e) {
            return Result::fail(sprintf(
                'Invalid issue data: %s',
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            return Result::fail(sprintf(
                'Unexpected error reporting issue: %s',
                $e->getMessage()
            ));
        }
    }
}
