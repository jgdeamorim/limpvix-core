<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Customer;

use LimpVix\Domain\Customer\CustomerId;
use LimpVix\Domain\Customer\CustomerRepositoryInterface;

defined("ABSPATH") || exit;

/**
 * Get Customer Briefings Use Case
 * 
 * Buscar briefings de um cliente
 */
final class GetCustomerBriefings
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
     * @return array Lista de briefings
     * @throws \RuntimeException se cliente não encontrado
     */
    public function execute(int $customerId): array
    {
        $customer = $this->customerRepository->findById(CustomerId::fromInt($customerId));

        if (!$customer) {
            throw new \RuntimeException("Customer not found");
        }

        $briefings = $this->customerRepository->getBriefings(CustomerId::fromInt($customerId));

        return [
            "customer_id" => $customerId,
            "briefings" => $briefings,
            "total" => count($briefings),
        ];
    }
}
