<?php

declare(strict_types=1);

namespace LimpVix\Domain\Customer;

defined("ABSPATH") || exit;

/**
 * Customer Aggregate Root
 * 
 * Representa um cliente da LimpVix.
 * Baseado em WordPress User (wp_users) com metadados adicionais.
 */
final class Customer
{
    private CustomerId $id;
    private string $name;
    private string $email;
    private ?string $phone;
    private ?array $address;
    private string $role;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $updatedAt;

    // Estatísticas (calculadas pelo repository)
    private int $totalContracts = 0;
    private int $activeContracts = 0;
    private float $totalSpent = 0.0;
    private float $lifetimeValue = 0.0;

    private function __construct(
        CustomerId $id,
        string $name,
        string $email,
        ?string $phone,
        ?array $address,
        string $role,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $updatedAt = null
    ) {
        $this->id = $id;
        $this->setName($name);
        $this->setEmail($email);
        $this->phone = $phone;
        $this->address = $address;
        $this->role = $role;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Criar novo cliente
     */
    public static function create(
        int $wordpressUserId,
        string $name,
        string $email,
        ?string $phone = null,
        ?array $address = null
    ): self {
        return new self(
            CustomerId::fromInt($wordpressUserId),
            $name,
            $email,
            $phone,
            $address,
            "limpvix_customer",
            new \DateTimeImmutable()
        );
    }

    /**
     * Reconstituir customer a partir de dados persistidos
     */
    public static function reconstitute(array $data): self
    {
        $customer = new self(
            CustomerId::fromInt((int) $data["id"]),
            $data["name"],
            $data["email"],
            $data["phone"] ?? null,
            isset($data["address"]) ? (is_string($data["address"]) ? json_decode($data["address"], true) : $data["address"]) : null,
            $data["role"] ?? "limpvix_customer",
            new \DateTimeImmutable($data["created_at"] ?? "now"),
            isset($data["updated_at"]) ? new \DateTimeImmutable($data["updated_at"]) : null
        );

        // Estatísticas (se disponíveis)
        if (isset($data["total_contracts"])) {
            $customer->totalContracts = (int) $data["total_contracts"];
        }
        if (isset($data["active_contracts"])) {
            $customer->activeContracts = (int) $data["active_contracts"];
        }
        if (isset($data["total_spent"])) {
            $customer->totalSpent = (float) $data["total_spent"];
        }
        if (isset($data["lifetime_value"])) {
            $customer->lifetimeValue = (float) $data["lifetime_value"];
        }

        return $customer;
    }

    // Getters

    public function getId(): CustomerId
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getAddress(): ?array
    {
        return $this->address;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getTotalContracts(): int
    {
        return $this->totalContracts;
    }

    public function getActiveContracts(): int
    {
        return $this->activeContracts;
    }

    public function getTotalSpent(): float
    {
        return $this->totalSpent;
    }

    public function getLifetimeValue(): float
    {
        return $this->lifetimeValue;
    }

    // Setters com validação

    public function updateProfile(
        ?string $name = null,
        ?string $phone = null,
        ?array $address = null
    ): void {
        if ($name !== null) {
            $this->setName($name);
        }

        if ($phone !== null) {
            $this->phone = $phone;
        }

        if ($address !== null) {
            $this->address = $address;
        }

        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateEmail(string $email): void
    {
        $this->setEmail($email);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setStatistics(
        int $totalContracts,
        int $activeContracts,
        float $totalSpent,
        float $lifetimeValue
    ): void {
        $this->totalContracts = $totalContracts;
        $this->activeContracts = $activeContracts;
        $this->totalSpent = $totalSpent;
        $this->lifetimeValue = $lifetimeValue;
    }

    // Business Logic

    public function hasActiveContracts(): bool
    {
        return $this->activeContracts > 0;
    }

    public function isHighValueCustomer(): bool
    {
        return $this->lifetimeValue >= 5000.0;
    }

    public function canCreateBriefing(): bool
    {
        // Regra: Cliente pode criar briefing se tiver role correto
        return $this->role === "limpvix_customer";
    }

    /**
     * Serializar para array (para persistência)
     */
    public function toArray(): array
    {
        return [
            "id" => $this->id->toInt(),
            "name" => $this->name,
            "email" => $this->email,
            "phone" => $this->phone,
            "address" => $this->address ? json_encode($this->address) : null,
            "role" => $this->role,
            "created_at" => $this->createdAt->format("Y-m-d H:i:s"),
            "updated_at" => $this->updatedAt ? $this->updatedAt->format("Y-m-d H:i:s") : null,
            "total_contracts" => $this->totalContracts,
            "active_contracts" => $this->activeContracts,
            "total_spent" => $this->totalSpent,
            "lifetime_value" => $this->lifetimeValue,
        ];
    }

    // Private helpers

    private function setName(string $name): void
    {
        if (strlen($name) < 3) {
            throw new \InvalidArgumentException("Customer name must be at least 3 characters");
        }

        $this->name = $name;
    }

    private function setEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email format");
        }

        $this->email = $email;
    }
}
