<?php
/**
 * Briefing - Aggregate Root
 *
 * RESPONSABILIDADE:
 * - Representar um Briefing completo no domínio
 * - Controlar state machine (draft → locked)
 * - Encapsular regras de cálculo de métricas
 * - Coordenar Value Objects (Status, PropertyType, Frequency, etc)
 *
 * PRINCÍPIOS:
 * - Aggregate Root (entidade principal)
 * - Identificável por UUID
 * - Mutável via métodos controlados (transições de estado)
 * - Zero dependências do WordPress
 * - Validações delegadas para BriefingPolicy
 *
 * IMPORTANTE:
 * - Este é o coração do módulo Briefing
 * - Todas as operações de negócio passam por aqui
 * - Nunca acesse diretamente - use Use Cases
 * - Imutável após lock() (estado final)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class Briefing
{
    /**
     * UUID do Briefing (identidade única)
     *
     * @var string
     */
    private $uuid;

    /**
     * Order ID (vinculação com WooCommerce/LimpVix Order)
     * Null até pagamento confirmado
     *
     * @var int|null
     */
    private $orderId;

    /**
     * User ID (WordPress user ID do cliente)
     *
     * @var int
     */
    private $userId;

    /**
     * Status atual da state machine
     *
     * @var BriefingStatus
     */
    private $status;

    /**
     * Tipo de propriedade
     *
     * @var PropertyType
     */
    private $propertyType;

    /**
     * Estrutura do imóvel (pode ser null enquanto draft)
     *
     * @var PropertyStructure|null
     */
    private $structure;

    /**
     * Frequência de execução (pode ser null enquanto draft)
     *
     * @var Frequency|null
     */
    private $frequency;

    /**
     * Métricas calculadas (m², tempo, buffer)
     * Null até estrutura e limpeza serem definidas
     *
     * @var EstimatedMetrics|null
     */
    private $metrics;

    /**
     * Pacote de serviço selecionado (basic, standard, premium)
     * Null até ser selecionado
     *
     * @var Package|null
     */
    private $package;

    /**
     * Telefone verificado via Firebase OTP?
     *
     * @var bool
     */
    private $phoneVerified;

    /**
     * Versão do schema do Briefing (para versionamento)
     *
     * @var string
     */
    private $version;

    /**
     * Data de criação
     *
     * @var \DateTimeImmutable
     */
    private $createdAt;

    /**
     * Data de última atualização
     *
     * @var \DateTimeImmutable
     */
    private $updatedAt;

    /**
     * Data de lock (quando transicionou para locked)
     * Null enquanto não estiver locked
     *
     * @var \DateTimeImmutable|null
     */
    private $lockedAt;

    /**
     * Construtor
     *
     * @param string $uuid UUID único
     * @param int $userId WordPress user ID
     * @param PropertyType $propertyType Tipo de propriedade
     * @param BriefingStatus|null $status Status (default: draft)
     * @param int|null $orderId Order ID vinculada
     * @param PropertyStructure|null $structure Estrutura do imóvel
     * @param Frequency|null $frequency Frequência
     * @param EstimatedMetrics|null $metrics Métricas calculadas
     * @param Package|null $package Pacote de serviço selecionado
     * @param bool $phoneVerified Telefone verificado?
     * @param string $version Versão do schema
     * @param \DateTimeImmutable|null $createdAt Data criação
     * @param \DateTimeImmutable|null $updatedAt Data atualização
     * @param \DateTimeImmutable|null $lockedAt Data lock
     */
    public function __construct(
        string $uuid,
        int $userId,
        PropertyType $propertyType,
        ?BriefingStatus $status = null,
        ?int $orderId = null,
        ?PropertyStructure $structure = null,
        ?Frequency $frequency = null,
        ?EstimatedMetrics $metrics = null,
        ?Package $package = null,
        bool $phoneVerified = false,
        string $version = '1.0',
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $updatedAt = null,
        ?\DateTimeImmutable $lockedAt = null
    ) {
        // Validações básicas
        if (empty($uuid)) {
            throw new \InvalidArgumentException("UUID não pode ser vazio");
        }

        if ($userId <= 0) {
            throw new \InvalidArgumentException("User ID deve ser maior que zero");
        }

        $this->uuid = $uuid;
        $this->userId = $userId;
        $this->propertyType = $propertyType;
        $this->status = $status ?? BriefingStatus::draft();
        $this->orderId = $orderId;
        $this->structure = $structure;
        $this->frequency = $frequency;
        $this->metrics = $metrics;
        $this->package = $package;
        $this->phoneVerified = $phoneVerified;
        $this->version = $version;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $updatedAt ?? new \DateTimeImmutable();
        $this->lockedAt = $lockedAt;
    }

    /**
     * Factory: Criar novo Briefing (estado inicial: draft)
     *
     * @param string $uuid UUID gerado
     * @param int $userId WordPress user ID
     * @param PropertyType $propertyType Tipo de propriedade
     * @return self
     */
    public static function create(string $uuid, int $userId, PropertyType $propertyType): self
    {
        return new self(
            uuid: $uuid,
            userId: $userId,
            propertyType: $propertyType,
            status: BriefingStatus::draft()
        );
    }

    // ==================== GETTERS ====================

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getStatus(): BriefingStatus
    {
        return $this->status;
    }

    public function getPropertyType(): PropertyType
    {
        return $this->propertyType;
    }

    public function getStructure(): ?PropertyStructure
    {
        return $this->structure;
    }

    public function getFrequency(): ?Frequency
    {
        return $this->frequency;
    }

    public function getMetrics(): ?EstimatedMetrics
    {
        return $this->metrics;
    }

    public function getPackage(): ?Package
    {
        return $this->package;
    }

    public function isPhoneVerified(): bool
    {
        return $this->phoneVerified;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    // ==================== MÉTODOS DE NEGÓCIO ====================

    /**
     * Verificar se está locked (estado final)
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }

    /**
     * Verificar se requer contrato
     *
     * @return bool
     */
    public function requiresContract(): bool
    {
        return $this->frequency !== null && $this->frequency->requiresContract();
    }

    /**
     * Transicionar para novo estado
     *
     * ⚠️ IMPORTANTE: BriefingPolicy deve validar antes de chamar este método
     *
     * @param BriefingStatus $newStatus
     * @return void
     * @throws \DomainException Se transição inválida
     */
    public function transitionTo(BriefingStatus $newStatus): void
    {
        // Verificação básica (Policy deve fazer validação completa)
        if ($this->isLocked()) {
            throw new \DomainException("Briefing locked não pode transicionar");
        }

        if (!$this->status->canTransitionTo($newStatus)) {
            throw new \DomainException(
                "Transição inválida: {$this->status->getValue()} → {$newStatus->getValue()}"
            );
        }

        $this->status = $newStatus;
        $this->touch(); // Atualizar updatedAt
    }

    /**
     * Lock do Briefing (transição para estado final)
     *
     * @param int $orderId Order ID vinculada
     * @return void
     * @throws \DomainException Se não puder fazer lock
     */
    public function lock(int $orderId): void
    {
        if ($this->isLocked()) {
            throw new \DomainException("Briefing já está locked");
        }

        if (!$this->status->isPaid()) {
            throw new \DomainException("Briefing deve estar 'paid' para fazer lock");
        }

        $this->status = BriefingStatus::locked();
        $this->orderId = $orderId;
        $this->lockedAt = new \DateTimeImmutable();
        $this->touch();
    }

    /**
     * Atualizar estrutura do imóvel
     *
     * @param PropertyStructure $structure
     * @return void
     * @throws \DomainException Se locked
     */
    public function updateStructure(PropertyStructure $structure): void
    {
        $this->assertNotLocked();
        $this->structure = $structure;
        $this->touch();
    }

    /**
     * Atualizar frequência
     *
     * @param Frequency $frequency
     * @return void
     * @throws \DomainException Se locked
     */
    public function updateFrequency(Frequency $frequency): void
    {
        $this->assertNotLocked();
        $this->frequency = $frequency;
        $this->touch();
    }

    /**
     * Atualizar métricas calculadas
     *
     * @param EstimatedMetrics $metrics
     * @return void
     */
    public function updateMetrics(EstimatedMetrics $metrics): void
    {
        $this->metrics = $metrics;
        $this->touch();
    }

    /**
     * Marcar telefone como verificado
     *
     * @return void
     */
    public function verifyPhone(): void
    {
        $this->phoneVerified = true;
        $this->touch();
    }

    /**
     * Selecionar pacote de serviço
     *
     * @param Package $package
     * @return void
     * @throws \DomainException Se locked
     */
    public function selectPackage(Package $package): void
    {
        $this->assertNotLocked();
        $this->package = $package;
        $this->touch();
    }

    /**
     * Calcular métricas baseado na estrutura atual
     *
     * Método simplificado - cálculo completo está em BriefingMetricsCalculator
     *
     * @return EstimatedMetrics|null
     */
    public function calculateMetrics(): ?EstimatedMetrics
    {
        if ($this->structure === null) {
            return null;
        }

        // Cálculo simplificado (m² × 3 minutos)
        $m2 = $this->structure->calculateEstimatedM2();
        $durationMinutes = (int) ($m2 * 3);
        $bufferMinutes = 30;

        return new EstimatedMetrics($m2, $durationMinutes, $bufferMinutes);
    }

    // ==================== MÉTODOS AUXILIARES ====================

    /**
     * Atualizar timestamp updatedAt
     *
     * @return void
     */
    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Assert: não está locked
     *
     * @return void
     * @throws \DomainException
     */
    private function assertNotLocked(): void
    {
        if ($this->isLocked()) {
            throw new \DomainException("Operação não permitida: Briefing está locked");
        }
    }

    /**
     * Comparar com outro Briefing
     *
     * @param Briefing $other
     * @return bool
     */
    public function equals(Briefing $other): bool
    {
        return $this->uuid === $other->uuid;
    }

    /**
     * Converter para array (para persistência)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'order_id' => $this->orderId,
            'user_id' => $this->userId,
            'status' => $this->status->getValue(),
            'property_type' => $this->propertyType->getValue(),
            'structure' => $this->structure ? $this->structure->toArray() : null,
            'frequency' => $this->frequency ? $this->frequency->toArray() : null,
            'metrics' => $this->metrics ? $this->metrics->toArray() : null,
            'package' => $this->package ? $this->package->toArray() : null,
            'phone_verified' => $this->phoneVerified,
            'requires_contract' => $this->requiresContract(),
            'version' => $this->version,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
            'locked_at' => $this->lockedAt ? $this->lockedAt->format('Y-m-d H:i:s') : null
        ];
    }
}
