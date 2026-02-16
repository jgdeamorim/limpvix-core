<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Persistence\Customer;

use LimpVix\Domain\Customer\Customer;
use LimpVix\Domain\Customer\CustomerId;
use LimpVix\Domain\Customer\CustomerRepositoryInterface;

defined("ABSPATH") || exit;

/**
 * WordPress Customer Repository Implementation
 * 
 * Usa wp_users como base + metadados em wp_usermeta
 * Queries em wp_limpvix_contracts e wp_limpvix_briefings para estatísticas
 */
final class WpCustomerRepository implements CustomerRepositoryInterface
{
    private \wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function findById(CustomerId $id): ?Customer
    {
        $user = \get_user_by("id", $id->toInt());

        if (!$user || !in_array("limpvix_customer", (array) $user->roles, true)) {
            return null;
        }

        return $this->mapUserToCustomer($user);
    }

    public function findByEmail(string $email): ?Customer
    {
        $user = \get_user_by("email", $email);

        if (!$user || !in_array("limpvix_customer", (array) $user->roles, true)) {
            return null;
        }

        return $this->mapUserToCustomer($user);
    }

    public function findAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $args = [
            "role" => "limpvix_customer",
            "number" => $limit,
            "offset" => $offset,
            "orderby" => "registered",
            "order" => "DESC",
        ];

        // Filtro por busca (nome ou email)
        if (!empty($filters["search"])) {
            $args["search"] = "*" . $filters["search"] . "*";
            $args["search_columns"] = ["user_login", "user_email", "display_name"];
        }

        $query = new \WP_User_Query($args);
        $users = $query->get_results();

        if (empty($users)) {
            return [];
        }

        // OTIMIZAÇÃO: Batch loading de estatísticas (1 query ao invés de N queries)
        $userIds = array_map(fn($user) => $user->ID, $users);
        $statisticsBatch = $this->getStatisticsBatch($userIds);

        $customers = array_map(
            fn($user) => $this->mapUserToCustomerWithStats($user, $statisticsBatch[$user->ID]),
            $users
        );

        // Filtros pós-query (status, min_spent)
        if (!empty($filters["status"])) {
            if ($filters["status"] === "active") {
                $customers = array_filter($customers, fn($c) => $c->hasActiveContracts());
            } elseif ($filters["status"] === "inactive") {
                $customers = array_filter($customers, fn($c) => !$c->hasActiveContracts());
            }
        }

        if (!empty($filters["min_spent"])) {
            $minSpent = (float) $filters["min_spent"];
            $customers = array_filter($customers, fn($c) => $c->getTotalSpent() >= $minSpent);
        }

        return array_values($customers);
    }

    public function count(array $filters = []): int
    {
        $args = [
            "role" => "limpvix_customer",
            "fields" => "ID",
        ];

        if (!empty($filters["search"])) {
            $args["search"] = "*" . $filters["search"] . "*";
            $args["search_columns"] = ["user_login", "user_email", "display_name"];
        }

        $query = new \WP_User_Query($args);
        return $query->get_total();
    }

    public function save(Customer $customer): void
    {
        $userId = $customer->getId()->toInt();

        // Atualizar wp_users
        \wp_update_user([
            "ID" => $userId,
            "display_name" => $customer->getName(),
            "user_email" => $customer->getEmail(),
        ]);

        // Atualizar metadados
        if ($customer->getPhone()) {
            \update_user_meta($userId, "billing_phone", $customer->getPhone());
        }

        if ($customer->getAddress()) {
            \update_user_meta($userId, "limpvix_address", json_encode($customer->getAddress()));
        }
    }

    public function delete(CustomerId $id): void
    {
        \wp_delete_user($id->toInt());
    }

    public function getContracts(CustomerId $customerId): array
    {
        $contractsTable = $this->wpdb->prefix . "limpvix_contracts";

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$contractsTable}
             WHERE client_user_id = %d
             ORDER BY created_at DESC",
            $customerId->toInt()
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return $results ?: [];
    }

    public function getBriefings(CustomerId $customerId): array
    {
        $briefingsTable = $this->wpdb->prefix . "limpvix_briefings";

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$briefingsTable}
             WHERE user_id = %d
             ORDER BY created_at DESC",
            $customerId->toInt()
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        return $results ?: [];
    }

    public function getStatistics(CustomerId $customerId): array
    {
        $batch = $this->getStatisticsBatch([$customerId->toInt()]);
        return $batch[$customerId->toInt()] ?? [
            "total_contracts" => 0,
            "active_contracts" => 0,
            "total_spent" => 0.0,
            "lifetime_value" => 0.0,
        ];
    }

    /**
     * Batch loading de estatísticas para múltiplos clientes
     * SOLUÇÃO PARA N+1 QUERY PROBLEM
     *
     * @param array $userIds Array de user IDs
     * @return array Array associativo [userId => statistics]
     */
    private function getStatisticsBatch(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $contractsTable = $this->wpdb->prefix . "limpvix_contracts";

        // Sanitizar IDs
        $userIds = array_map('intval', $userIds);
        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));

        // UMA query com agregações usando CASE WHEN
        $sql = $this->wpdb->prepare(
            "SELECT
                client_user_id,
                COUNT(*) as total_contracts,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_contracts,
                SUM(CASE WHEN status IN ('active', 'completed') THEN monthly_value ELSE 0 END) as total_spent,
                SUM(CASE WHEN status = 'active' THEN monthly_value * 12 ELSE 0 END) as lifetime_value
            FROM {$contractsTable}
            WHERE client_user_id IN ({$placeholders})
            GROUP BY client_user_id",
            ...$userIds
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A);

        // Organizar em array associativo
        $statistics = [];
        foreach ($results as $row) {
            $statistics[(int) $row['client_user_id']] = [
                "total_contracts" => (int) $row['total_contracts'],
                "active_contracts" => (int) $row['active_contracts'],
                "total_spent" => (float) $row['total_spent'],
                "lifetime_value" => (float) $row['lifetime_value'],
            ];
        }

        // Preencher usuários sem contratos com zeros
        foreach ($userIds as $userId) {
            if (!isset($statistics[$userId])) {
                $statistics[$userId] = [
                    "total_contracts" => 0,
                    "active_contracts" => 0,
                    "total_spent" => 0.0,
                    "lifetime_value" => 0.0,
                ];
            }
        }

        return $statistics;
    }

    /**
     * Mapear WP_User para Customer entity
     */
    /**
     * Mapear WP_User para Customer entity (com stats pré-carregadas)
     * OTIMIZAÇÃO: Evita N+1 queries ao usar batch loading
     */
    private function mapUserToCustomerWithStats(\WP_User $user, array $stats): Customer
    {
        $userId = $user->ID;
        $address = \get_user_meta($userId, "limpvix_address", true);

        $customer = Customer::reconstitute([
            "id" => $userId,
            "name" => $user->display_name,
            "email" => $user->user_email,
            "phone" => \get_user_meta($userId, "billing_phone", true) ?: null,
            "address" => $address ? json_decode($address, true) : null,
            "role" => "limpvix_customer",
            "created_at" => $user->user_registered,
            "updated_at" => null,
        ]);

        // Setar estatísticas pré-carregadas
        $customer->setStatistics(
            $stats["total_contracts"],
            $stats["active_contracts"],
            $stats["total_spent"],
            $stats["lifetime_value"]
        );

        return $customer;
    }

    private function mapUserToCustomer(\WP_User $user): Customer
    {
        $userId = $user->ID;
        $address = \get_user_meta($userId, "limpvix_address", true);

        $customer = Customer::reconstitute([
            "id" => $userId,
            "name" => $user->display_name,
            "email" => $user->user_email,
            "phone" => \get_user_meta($userId, "billing_phone", true) ?: null,
            "address" => $address ? json_decode($address, true) : null,
            "role" => "limpvix_customer",
            "created_at" => $user->user_registered,
            "updated_at" => null,
        ]);

        // Carregar estatísticas
        $stats = $this->getStatistics(CustomerId::fromInt($userId));
        $customer->setStatistics(
            $stats["total_contracts"],
            $stats["active_contracts"],
            $stats["total_spent"],
            $stats["lifetime_value"]
        );

        return $customer;
    }
}
