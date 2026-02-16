<?php

declare(strict_types=1);

namespace LimpVix\Application\DTO\Response;

use LimpVix\Domain\Customer\Customer;

defined("ABSPATH") || exit;

/**
 * Customer Response DTO
 * 
 * Formata dados do Customer para resposta da API
 */
final class CustomerResponse
{
    public static function fromCustomer(Customer $customer): array
    {
        return [
            "id" => $customer->getId()->toInt(),
            "name" => $customer->getName(),
            "email" => $customer->getEmail(),
            "phone" => $customer->getPhone(),
            "address" => $customer->getAddress(),
            "role" => $customer->getRole(),
            "statistics" => [
                "total_contracts" => $customer->getTotalContracts(),
                "active_contracts" => $customer->getActiveContracts(),
                "total_spent" => $customer->getTotalSpent(),
                "lifetime_value" => $customer->getLifetimeValue(),
                "is_high_value" => $customer->isHighValueCustomer(),
                "has_active_contracts" => $customer->hasActiveContracts(),
            ],
            "created_at" => $customer->getCreatedAt()->format("Y-m-d H:i:s"),
            "updated_at" => $customer->getUpdatedAt() ? $customer->getUpdatedAt()->format("Y-m-d H:i:s") : null,
        ];
    }

    /**
     * Resposta simplificada (para listagens)
     */
    public static function toSimpleArray(Customer $customer): array
    {
        return [
            "id" => $customer->getId()->toInt(),
            "name" => $customer->getName(),
            "email" => $customer->getEmail(),
            "phone" => $customer->getPhone(),
            "total_contracts" => $customer->getTotalContracts(),
            "active_contracts" => $customer->getActiveContracts(),
            "total_spent" => $customer->getTotalSpent(),
            "lifetime_value" => $customer->getLifetimeValue(),
            "created_at" => $customer->getCreatedAt()->format("Y-m-d"),
        ];
    }
}
