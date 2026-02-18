<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Customer;

use LimpVix\Domain\Customer\CustomerRepositoryInterface;
use LimpVix\Application\DTO\Response\CustomerResponse;

defined("ABSPATH") || exit;

/**
 * List Customers Use Case
 * 
 * Listar clientes com filtros e paginação
 */
final class ListCustomers
{
    private CustomerRepositoryInterface $customerRepository;

    public function __construct(CustomerRepositoryInterface $customerRepository)
    {
        $this->customerRepository = $customerRepository;
    }

    /**
     * Executar use case
     * 
     * @param array $filters Filtros: search, status, min_spent
     * @param int $perPage Items por página
     * @param int $page Página atual
     * @return array {customers: array, total: int, page: int, per_page: int}
     */
    public function execute(array $filters = [], int $perPage = 20, int $page = 1): array
    {
        $offset = ($page - 1) * $perPage;

        $customers = $this->customerRepository->findAll($filters, $perPage, $offset);
        $total = $this->customerRepository->count($filters);

        return [
            "customers" => array_map(
                fn($customer) => CustomerResponse::toSimpleArray($customer),
                $customers
            ),
            "total" => $total,
            "page" => $page,
            "per_page" => $perPage,
            "total_pages" => (int) ceil($total / $perPage),
        ];
    }
}
