<?php

declare(strict_types=1);

namespace LimpVix\Domain\Customer;

defined("ABSPATH") || exit;

/**
 * Customer Repository Interface
 * 
 * Contrato para persistência de clientes.
 * Implementação concreta em Infrastructure layer.
 */
interface CustomerRepositoryInterface
{
    /**
     * Buscar cliente por ID
     */
    public function findById(CustomerId $id): ?Customer;

    /**
     * Buscar cliente por email
     */
    public function findByEmail(string $email): ?Customer;

    /**
     * Listar todos os clientes com paginação
     * 
     * @param array $filters Filtros opcionais: search, status, min_spent
     * @param int $limit Limite de resultados
     * @param int $offset Offset para paginação
     * @return Customer[]
     */
    public function findAll(array $filters = [], int $limit = 20, int $offset = 0): array;

    /**
     * Contar total de clientes (para paginação)
     */
    public function count(array $filters = []): int;

    /**
     * Salvar/Atualizar cliente
     */
    public function save(Customer $customer): void;

    /**
     * Deletar cliente
     */
    public function delete(CustomerId $id): void;

    /**
     * Buscar contratos do cliente
     * 
     * @return array Array de Contract entities
     */
    public function getContracts(CustomerId $customerId): array;

    /**
     * Buscar briefings do cliente
     * 
     * @return array Array de Briefing aggregates
     */
    public function getBriefings(CustomerId $customerId): array;

    /**
     * Obter estatísticas do cliente
     * 
     * @return array {
     *   total_contracts: int,
     *   active_contracts: int,
     *   total_spent: float,
     *   lifetime_value: float
     * }
     */
    public function getStatistics(CustomerId $customerId): array;
}
