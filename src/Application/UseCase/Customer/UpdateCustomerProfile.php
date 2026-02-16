<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCase\Customer;

use LimpVix\Domain\Customer\CustomerId;
use LimpVix\Domain\Customer\CustomerRepositoryInterface;
use LimpVix\Application\DTO\Response\CustomerResponse;

defined("ABSPATH") || exit;

/**
 * Update Customer Profile Use Case
 * 
 * Atualizar perfil de um cliente
 */
final class UpdateCustomerProfile
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
     * @param array $data Dados a atualizar: name, phone, address
     * @return array Customer data atualizado
     * @throws \RuntimeException se cliente não encontrado
     */
    public function execute(int $customerId, array $data): array
    {
        $customer = $this->customerRepository->findById(CustomerId::fromInt($customerId));

        if (!$customer) {
            throw new \RuntimeException("Customer not found");
        }

        $customer->updateProfile(
            $data["name"] ?? null,
            $data["phone"] ?? null,
            $data["address"] ?? null
        );

        if (isset($data["email"])) {
            $customer->updateEmail($data["email"]);
        }

        $this->customerRepository->save($customer);

        return CustomerResponse::fromCustomer($customer);
    }
}
