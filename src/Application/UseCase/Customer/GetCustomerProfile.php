<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCase\Customer;

use LimpVix\Domain\Customer\CustomerId;
use LimpVix\Domain\Customer\CustomerRepositoryInterface;
use LimpVix\Application\DTO\Response\CustomerResponse;

defined("ABSPATH") || exit;

/**
 * Get Customer Profile Use Case
 * 
 * Buscar perfil completo de um cliente
 */
final class GetCustomerProfile
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
     * @return array Customer data ou null se não encontrado
     * @throws \RuntimeException se cliente não encontrado
     */
    public function execute(int $customerId): array
    {
        $customer = $this->customerRepository->findById(CustomerId::fromInt($customerId));

        if (!$customer) {
            throw new \RuntimeException("Customer not found");
        }

        return CustomerResponse::fromCustomer($customer);
    }
}
