<?php
/**
 * WpContractRepository - Implementação WordPress do ContractRepositoryInterface
 *
 * RESPONSABILIDADE:
 * - Persistir e recuperar Contracts usando wpdb
 * - Gerenciar transações do banco de dados
 * - Abstrair detalhes de SQL do domínio
 *
 * TABELA: wp_limpvix_contracts
 *
 * @package LimpVix\Infrastructure\Persistence\Contract
 * @since 0.8.0
 */

namespace LimpVix\Infrastructure\Persistence\Contract;

use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Exceptions\ContractNotFoundException;

defined('ABSPATH') || exit;

final class WpContractRepository implements ContractRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_contracts';
    }

    /**
     * {@inheritdoc}
     */
    public function save(Contract $contract): void
    {
        $data = ContractMapper::toDatabase($contract);
        $id = $contract->getId()->toInt();

        if ($id === 0) {
            // Insert
            $result = $this->wpdb->insert(
                $this->table,
                $data,
                $this->getFormat($data)
            );

            if ($result === false) {
                throw new \RuntimeException(
                    "Failed to insert contract: " . $this->wpdb->last_error
                );
            }

            // Atualizar ID do aggregate root via reflexão
            $insertedId = (int) $this->wpdb->insert_id;
            $this->setContractId($contract, ContractId::fromInt($insertedId));
        } else {
            // Update
            $result = $this->wpdb->update(
                $this->table,
                $data,
                ['id' => $id],
                $this->getFormat($data),
                ['%d']
            );

            if ($result === false) {
                throw new \RuntimeException(
                    "Failed to update contract {$id}: " . $this->wpdb->last_error
                );
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function findById(ContractId $id): ?Contract
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id->toInt()
        );

        $row = $this->wpdb->get_row($sql);

        if (!$row) {
            return null;
        }

        return ContractMapper::toDomain($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findByNumber(string $contractNumber): ?Contract
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE contract_number = %s",
            $contractNumber
        );

        $row = $this->wpdb->get_row($sql);

        if (!$row) {
            return null;
        }

        return ContractMapper::toDomain($row);
    }

    /**
     * {@inheritdoc}
     */
    public function findByClientId(int $clientUserId): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE client_user_id = %d ORDER BY created_at DESC",
            $clientUserId
        );

        $rows = $this->wpdb->get_results($sql);

        return ContractMapper::toDomainCollection($rows);
    }

    /**
     * {@inheritdoc}
     */
    public function findActiveContracts(): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE status = 'active' ORDER BY created_at DESC";

        $rows = $this->wpdb->get_results($sql);

        return ContractMapper::toDomainCollection($rows);
    }

    /**
     * {@inheritdoc}
     */
    public function findExpiringContracts(\DateTimeImmutable $beforeDate): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
             AND end_date IS NOT NULL
             AND end_date <= %s
             ORDER BY end_date ASC",
            $beforeDate->format('Y-m-d')
        );

        $rows = $this->wpdb->get_results($sql);

        return ContractMapper::toDomainCollection($rows);
    }

    /**
     * {@inheritdoc}
     */
    public function findContractsNeedingExecution(\DateTimeImmutable $date): array
    {
        // NOTA: nextExecutionDate não está persistido no banco
        // Esta query retorna contratos ativos e o cálculo da próxima execução
        // deve ser feito no domínio
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'active'
             AND start_date <= %s
             ORDER BY created_at ASC",
            $date->format('Y-m-d')
        );

        $rows = $this->wpdb->get_results($sql);

        return ContractMapper::toDomainCollection($rows);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(ContractId $id): void
    {
        // Soft delete: marcar como cancelled
        $result = $this->wpdb->update(
            $this->table,
            [
                'status' => 'cancelled',
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id->toInt()],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new \RuntimeException(
                "Failed to delete contract {$id->toInt()}: " . $this->wpdb->last_error
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function generateContractNumber(): string
    {
        // Formato: CTYYYYMMDD-XXXX
        $prefix = 'CT' . date('Ymd');

        // Buscar último número do dia
        $sql = $this->wpdb->prepare(
            "SELECT contract_number FROM {$this->table}
             WHERE contract_number LIKE %s
             ORDER BY contract_number DESC
             LIMIT 1",
            $prefix . '-%'
        );

        $lastNumber = $this->wpdb->get_var($sql);

        if ($lastNumber) {
            // Extrair sequencial e incrementar
            preg_match('/(\d{4})$/', $lastNumber, $matches);
            $sequence = isset($matches[1]) ? ((int) $matches[1]) + 1 : 1;
        } else {
            $sequence = 1;
        }

        return sprintf('%s-%04d', $prefix, $sequence);
    }

    // ==================== MÉTODOS AUXILIARES ====================

    /**
     * Obter formato wpdb para cada campo
     *
     * @param array $data
     * @return array
     */
    private function getFormat(array $data): array
    {
        $format = [];

        foreach ($data as $key => $value) {
            if (in_array($key, ['id', 'client_user_id', 'recurrence_day', 'auto_renew', 'allocated_professional_id'], true)) {
                $format[] = '%d';
            } elseif (in_array($key, ['monthly_value'], true)) {
                $format[] = '%f';
            } else {
                $format[] = '%s';
            }
        }

        return $format;
    }

    /**
     * Atualizar ID do Contract via reflexão
     *
     * NOTA: Necessário porque Contract tem ID privado sem setter
     *
     * @param Contract $contract
     * @param ContractId $id
     * @return void
     */
    private function setContractId(Contract $contract, ContractId $id): void
    {
        $reflection = new \ReflectionClass($contract);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($contract, $id);
        $property->setAccessible(false);
    }
}
