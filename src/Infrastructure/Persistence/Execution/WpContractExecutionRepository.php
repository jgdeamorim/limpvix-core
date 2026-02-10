<?php
/**
 * WpContractExecutionRepository - Implementação WordPress do Repository
 *
 * RESPONSABILIDADE:
 * - Persistir ContractExecution no banco de dados
 * - Converter entre Aggregate e formato de banco
 * - Despachar Domain Events
 *
 * @package LimpVix\Infrastructure\Persistence\Execution
 * @since 0.9.0
 */

namespace LimpVix\Infrastructure\Persistence\Execution;

use LimpVix\Domain\Execution\ContractExecution;
use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;
use LimpVix\Domain\Execution\ExecutionId;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Infrastructure\Events\WordPressEventDispatcher;

defined('ABSPATH') || exit;

final class WpContractExecutionRepository implements ContractExecutionRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;
    private WordPressEventDispatcher $eventDispatcher;

    public function __construct(?WordPressEventDispatcher $eventDispatcher = null)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_contract_executions';
        $this->eventDispatcher = $eventDispatcher ?? new WordPressEventDispatcher();
    }

    /**
     * Salvar execução (insert ou update)
     *
     * @param ContractExecution $execution
     * @return void
     * @throws \RuntimeException
     */
    public function save(ContractExecution $execution): void
    {
        $isNew = $execution->getId()->toInt() === 0;

        $data = $this->aggregateToArray($execution);

        if ($isNew) {
            // Insert
            $inserted = $this->wpdb->insert($this->table, $data);

            if ($inserted === false) {
                throw new \RuntimeException(
                    "Failed to insert execution: {$this->wpdb->last_error}"
                );
            }

            // Atualizar ID no aggregate
            $newId = ExecutionId::fromInt($this->wpdb->insert_id);
            $execution->setId($newId);
        } else {
            // Update
            $updated = $this->wpdb->update(
                $this->table,
                $data,
                ['id' => $execution->getId()->toInt()]
            );

            if ($updated === false) {
                throw new \RuntimeException(
                    "Failed to update execution {$execution->getId()->toInt()}: {$this->wpdb->last_error}"
                );
            }
        }

        // Despachar Domain Events após persistência bem-sucedida
        $this->dispatchEvents($execution);
    }

    /**
     * Buscar execução por ID
     *
     * @param ExecutionId $id
     * @return ContractExecution|null
     */
    public function findById(ExecutionId $id): ?ContractExecution
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id->toInt()
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return ContractExecution::reconstitute($row);
    }

    /**
     * Buscar todas as execuções de um contrato
     *
     * @param ContractId $contractId
     * @return array
     */
    public function findByContractId(ContractId $contractId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE contract_id = %d ORDER BY scheduled_date DESC",
            $contractId->toInt()
        ), ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map(
            fn($row) => ContractExecution::reconstitute($row),
            $rows
        );
    }

    /**
     * Buscar execuções de um professional
     *
     * @param int $professionalUserId
     * @return array
     */
    public function findByProfessionalId(int $professionalUserId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE professional_user_id = %d ORDER BY scheduled_date DESC",
            $professionalUserId
        ), ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map(
            fn($row) => ContractExecution::reconstitute($row),
            $rows
        );
    }

    /**
     * Buscar execuções agendadas em um período
     *
     * @param \DateTimeImmutable $startDate
     * @param \DateTimeImmutable $endDate
     * @return array
     */
    public function findScheduledBetween(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE scheduled_date BETWEEN %s AND %s
             AND status = 'scheduled'
             ORDER BY scheduled_date ASC",
            $startDate->format('Y-m-d H:i:s'),
            $endDate->format('Y-m-d H:i:s')
        ), ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map(
            fn($row) => ContractExecution::reconstitute($row),
            $rows
        );
    }

    /**
     * Converter Aggregate para array de banco
     *
     * @param ContractExecution $execution
     * @return array
     */
    private function aggregateToArray(ContractExecution $execution): array
    {
        $data = [
            'contract_id' => $execution->getContractId()->toInt(),
            'professional_user_id' => $execution->getProfessionalUserId(),
            'scheduled_date' => $execution->getScheduledDate()->format('Y-m-d H:i:s'),
            'status' => $execution->getStatus()->toString(),
            'notes' => $execution->getNotes(),
            'photos' => !empty($execution->getPhotos()) ? json_encode($execution->getPhotos()) : null,
            'updated_at' => $execution->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];

        // Campos opcionais
        if ($execution->getStartedAt()) {
            $data['started_at'] = $execution->getStartedAt()->format('Y-m-d H:i:s');
        }

        if ($execution->getCompletedAt()) {
            $data['completed_at'] = $execution->getCompletedAt()->format('Y-m-d H:i:s');
        }

        // ID apenas para update
        if ($execution->getId()->toInt() !== 0) {
            $data['id'] = $execution->getId()->toInt();
        } else {
            // Para insert, incluir created_at
            $data['created_at'] = $execution->getCreatedAt()->format('Y-m-d H:i:s');
        }

        return $data;
    }

    /**
     * Despachar Domain Events
     *
     * @param ContractExecution $execution
     * @return void
     */
    private function dispatchEvents(ContractExecution $execution): void
    {
        $events = $execution->releaseEvents();

        if (!empty($events)) {
            $this->eventDispatcher->dispatchAll($events);
        }
    }
}
