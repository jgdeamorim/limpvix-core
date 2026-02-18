<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Customer;

use LimpVix\Domain\Customer\CustomerId;
use LimpVix\Domain\Customer\CustomerRepositoryInterface;

defined("ABSPATH") || exit;

/**
 * Get Customer Contracts Use Case
 * 
 * Buscar contratos de um cliente
 */
final class GetCustomerContracts
{
    private CustomerRepositoryInterface $customerRepository;

    public function __construct(CustomerRepositoryInterface $customerRepository)
    {
        $this->customerRepository = $customerRepository;
    }

    /**
     * Executar use case
     * 
     * @param int $customerId ID do cliente
     * @return array Lista de contratos
     * @throws \RuntimeException se cliente não encontrado
     */
    public function execute(int $customerId): array
    {
        $customer = $this->customerRepository->findById(CustomerId::fromInt($customerId));

        if (!$customer) {
            throw new \RuntimeException("Customer not found");
        }

        $contracts = $this->customerRepository->getContracts(CustomerId::fromInt($customerId));

        return [
            "customer_id" => $customerId,
            "contracts" => $contracts,
            "total" => count($contracts),
        ];
    }
}
