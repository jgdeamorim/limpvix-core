<?php
/**
 * BriefingSnapshot - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar estado imutável do Briefing no momento do lock (pagamento confirmado)
 * - Garantir rastreabilidade jurídica (disputas, auditoria financeira, SLA)
 * - Normalizar dados v1 e v2 para formato unificado
 * - Validar integridade via hash SHA-256 (anti-tamper)
 *
 * PRINCÍPIOS:
 * - Value Object (imutável após criação)
 * - Versionado (v1, v2, v3...)
 * - Hash-based integrity (SHA-256)
 * - Formato normalizado (independente de schema version)
 *
 * CASOS DE USO CRÍTICOS:
 * - Disputa jurídica: "Quanto tempo foi estimado?"
 * - Auditoria financeira: "Por que esse preço?"
 * - SLA tracking: "Profissional cumpriu prazo baseado em qual estimativa?"
 * - AllocationEngine: input de alocação vem do snapshot (não do Briefing mutável)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.3.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingSnapshot
{
    /**
     * UUID do Briefing original
     *
     * @var string
     */
    private $briefingUuid;

    /**
     * Versão do schema (v1, v2, v3...)
     *
     * @var string
     */
    private $version;

    /**
     * Dados normalizados (formato unificado independente de versão)
     *
     * Schema: veja BRIEFING_SNAPSHOT_SPEC.md
     *
     * @var array
     */
    private $normalizedData;

    /**
     * Métricas derivadas (calculadas NO MOMENTO do snapshot)
     *
     * Inclui:
     * - estimated_m2
     * - estimated_duration_minutes
     * - complexity_multiplier
     * - requires_multiple_professionals
     * - pricing_breakdown
     *
     * @var array
     */
    private $derivedMetrics;

    /**
     * Timestamp de quando snapshot foi criado
     *
     * @var \DateTimeImmutable
     */
    private $snapshotAt;

    /**
     * Hash SHA-256 para validação de integridade
     *
     * Hash = SHA256(json_encode(normalizedData) + timestamp + briefingUuid)
     *
     * @var string
     */
    private $snapshotHash;

    /**
     * Construtor
     *
     * @param string $briefingUuid UUID do Briefing original
     * @param string $version Versão do schema (v1, v2...)
     * @param array $normalizedData Dados normalizados
     * @param array $derivedMetrics Métricas calculadas
     * @param \DateTimeImmutable $snapshotAt Timestamp do snapshot
     * @param string $snapshotHash Hash SHA-256
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $briefingUuid,
        string $version,
        array $normalizedData,
        array $derivedMetrics,
        \DateTimeImmutable $snapshotAt,
        string $snapshotHash
    ) {
        // Validações
        if (empty($briefingUuid)) {
            throw new \InvalidArgumentException("Briefing UUID não pode ser vazio");
        }

        if (!in_array($version, ['v1', 'v2', 'v3'], true)) {
            throw new \InvalidArgumentException("Versão inválida: {$version}");
        }

        if (empty($normalizedData)) {
            throw new \InvalidArgumentException("Dados normalizados não podem ser vazios");
        }

        if (empty($derivedMetrics)) {
            throw new \InvalidArgumentException("Métricas derivadas não podem ser vazias");
        }

        if (strlen($snapshotHash) !== 64) {
            throw new \InvalidArgumentException("Hash SHA-256 deve ter 64 caracteres");
        }

        $this->briefingUuid = $briefingUuid;
        $this->version = $version;
        $this->normalizedData = $normalizedData;
        $this->derivedMetrics = $derivedMetrics;
        $this->snapshotAt = $snapshotAt;
        $this->snapshotHash = $snapshotHash;
    }

    /**
     * Factory: Criar snapshot a partir de Briefing
     *
     * @param Briefing $briefing Briefing locked
     * @return self
     * @throws \DomainException Se Briefing não estiver locked
     */
    public static function createFromBriefing(Briefing $briefing): self
    {
        // Validar que Briefing está locked
        if (!$briefing->isLocked()) {
            throw new \DomainException("Snapshot só pode ser criado de Briefing locked");
        }

        // Determinar versão do schema
        $version = $briefing->getVersion(); // '1.0' → 'v1', '2.0' → 'v2'
        $schemaVersion = 'v' . (int) explode('.', $version)[0];

        // Normalizar dados baseado na versão
        $normalizedData = self::normalizeData($briefing, $schemaVersion);

        // Calcular métricas derivadas
        $derivedMetrics = self::calculateDerivedMetrics($briefing, $normalizedData);

        // Timestamp atual
        $snapshotAt = new \DateTimeImmutable();

        // Calcular hash
        $hash = self::calculateHashForData($normalizedData, $snapshotAt, $briefing->getUuid());

        return new self(
            briefingUuid: $briefing->getUuid(),
            version: $schemaVersion,
            normalizedData: $normalizedData,
            derivedMetrics: $derivedMetrics,
            snapshotAt: $snapshotAt,
            snapshotHash: $hash
        );
    }

    // ==================== GETTERS ====================

    public function getBriefingUuid(): string
    {
        return $this->briefingUuid;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getNormalizedData(): array
    {
        return $this->normalizedData;
    }

    public function getDerivedMetrics(): array
    {
        return $this->derivedMetrics;
    }

    public function getSnapshotAt(): \DateTimeImmutable
    {
        return $this->snapshotAt;
    }

    public function getSnapshotHash(): string
    {
        return $this->snapshotHash;
    }

    // ==================== MÉTODOS DE NEGÓCIO ====================

    /**
     * Verificar integridade do snapshot (validar hash)
     *
     * @return bool True se hash é válido
     */
    public function verifyIntegrity(): bool
    {
        $expectedHash = self::calculateHashForData(
            $this->normalizedData,
            $this->snapshotAt,
            $this->briefingUuid
        );

        return $expectedHash === $this->snapshotHash;
    }

    /**
     * Obter campo específico dos dados normalizados
     *
     * @param string $path Caminho (dot notation) ex: 'complexity.level'
     * @param mixed $default Valor default se não existir
     * @return mixed
     */
    public function getData(string $path, $default = null)
    {
        $keys = explode('.', $path);
        $value = $this->normalizedData;

        foreach ($keys as $key) {
            if (!is_array($value) || !isset($value[$key])) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Obter métrica específica
     *
     * @param string $key Chave da métrica
     * @param mixed $default Valor default
     * @return mixed
     */
    public function getMetric(string $key, $default = null)
    {
        return $this->derivedMetrics[$key] ?? $default;
    }

    // ==================== MÉTODOS AUXILIARES (private) ====================

    /**
     * Normalizar dados do Briefing para formato unificado
     *
     * @param Briefing $briefing
     * @param string $schemaVersion v1 ou v2
     * @return array Dados normalizados
     */
    private static function normalizeData(Briefing $briefing, string $schemaVersion): array
    {
        if ($schemaVersion === 'v1') {
            return self::normalizeV1($briefing);
        } elseif ($schemaVersion === 'v2') {
            return self::normalizeV2($briefing);
        }

        throw new \InvalidArgumentException("Schema version não suportado: {$schemaVersion}");
    }

    /**
     * Normalizar Briefing v1 → Unified Schema
     *
     * @param Briefing $briefing
     * @return array
     */
    private static function normalizeV1(Briefing $briefing): array
    {
        $structure = $briefing->getStructure();
        $frequency = $briefing->getFrequency();

        return [
            'property_type' => $briefing->getPropertyType()->getValue(),
            'service_type' => 'standard',  // v1 não tinha service_type

            'structure' => $structure ? [
                'bedrooms' => $structure->getBedrooms(),
                'bathrooms' => $structure->getBathrooms(),
                'has_living_room' => $structure->hasLivingRoom(),
                'has_kitchen' => $structure->hasKitchen(),
                'has_office' => $structure->hasOffice(),
                'has_external_area' => $structure->hasExternalArea(),
                'actual_m2' => null,  // v1 não coletava
            ] : null,

            'complexity' => [
                'level' => 'medium',  // Default conservador
                'packages' => [],
                'required_skills' => ['limpeza_basica'],
                'has_pets' => false,
                'has_children' => false,
                'property_condition' => 'dirty',  // Default conservador
                'ceiling_cleaning' => null,
                'window_cleaning' => null,
                'post_construction' => null,
            ],

            'requested_schedule' => null,  // v1 NÃO coletava → admin deve preencher

            'service_location' => null,  // v1 NÃO coletava → admin deve preencher

            'frequency' => $frequency ? [
                'type' => $frequency->getType(),
                'requires_contract' => $frequency->requiresContract(),
                'contract_details' => null,  // v1 não tinha detalhes
            ] : null,

            'contact' => [
                'phone' => '',  // Buscar de user meta?
                'phone_verified' => $briefing->isPhoneVerified(),
                'emergency_contact' => null,
                'preferred_communication' => 'whatsapp',
            ],

            'building_details' => null,  // v1 não coletava

            'metadata' => [
                'user_id' => $briefing->getUserId(),
                'created_at' => $briefing->getCreatedAt()->format('Y-m-d\TH:i:sP'),
                'locked_at' => $briefing->getLockedAt() ? $briefing->getLockedAt()->format('Y-m-d\TH:i:sP') : null,
                '_schema_version' => 'v1',
                '_requires_manual_input' => true,  // Admin deve completar dados ausentes
            ],
        ];
    }

    /**
     * Normalizar Briefing v2 → Unified Schema
     *
     * @param Briefing $briefing
     * @return array
     */
    private static function normalizeV2(Briefing $briefing): array
    {
        // TODO: Implementar quando Briefing v2 estiver pronto
        // Por enquanto, retorna v1
        return self::normalizeV1($briefing);
    }

    /**
     * Calcular métricas derivadas
     *
     * @param Briefing $briefing
     * @param array $normalizedData
     * @return array
     */
    private static function calculateDerivedMetrics(Briefing $briefing, array $normalizedData): array
    {
        $metrics = $briefing->getMetrics();

        if (!$metrics) {
            // Se métricas não calculadas, calcular agora
            $metrics = $briefing->calculateMetrics();
        }

        $package = $briefing->getPackage();
        $complexity = $briefing->getComplexity();
        $estimatedM2 = $metrics ? $metrics->getEstimatedM2() : 0;
        $baseDurationMinutes = $metrics ? $metrics->getDurationMinutes() : 0;
        $bufferMinutes = $metrics ? $metrics->getBufferMinutes() : 30;
        $totalEstimatedDuration = $baseDurationMinutes + $bufferMinutes;

        // Complexity multiplier baseado na complexidade (ou fallback para package)
        $complexityMultiplier = 1.0;
        if ($complexity) {
            $complexityMultiplier = $complexity->getMultiplier();
        } elseif ($package) {
            $complexityMultiplier = $package->getMultiplier();
        }

        // Determinar profissionais necessários via Policy (usa Complexity + Package + Duration)
        $allocation = ProfessionalAllocationPolicy::calculate($briefing);
        $requiredProfessionalsCount = $allocation->getRequiredCount();
        $requiresMultipleProfessionals = $allocation->requiresMultiple();

        // Pricing breakdown
        $pricePerM2 = (float) get_option('limpvix_briefing_price_per_m2', 15.0);
        $basePrice = $estimatedM2 * $pricePerM2;

        $packagesPrice = 0.0;
        $totalPrice = $basePrice;

        if ($package) {
            $totalPrice = $package->calculateFinalPrice($basePrice);
            $packagesPrice = $totalPrice - $basePrice;
        }

        return [
            'estimated_m2' => $estimatedM2,
            'base_duration_minutes' => $baseDurationMinutes,
            'complexity_multiplier' => $complexityMultiplier,
            'estimated_duration_minutes' => $baseDurationMinutes,
            'buffer_minutes' => $bufferMinutes,
            'total_estimated_duration' => $totalEstimatedDuration,
            'requires_multiple_professionals' => $requiresMultipleProfessionals,
            'required_professionals_count' => $requiredProfessionalsCount,
            'pricing_breakdown' => [
                'base_price' => $basePrice,
                'package_increase' => $packagesPrice,
                'total_price' => $totalPrice,
                'package_type' => $package ? $package->getType()->getValue() : null,
                'package_percentage' => $package ? $package->getPercentageDisplay() : '0%',
            ],
        ];
    }

    /**
     * Calcular hash SHA-256 para validação de integridade
     *
     * @param array $normalizedData
     * @param \DateTimeImmutable $timestamp
     * @param string $briefingUuid
     * @return string Hash SHA-256 (64 caracteres)
     */
    private static function calculateHashForData(
        array $normalizedData,
        \DateTimeImmutable $timestamp,
        string $briefingUuid
    ): string {
        $dataToHash = json_encode($normalizedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestampStr = $timestamp->format('Y-m-d\TH:i:sP');
        $hashInput = $dataToHash . $timestampStr . $briefingUuid;

        return hash('sha256', $hashInput);
    }

    /**
     * Converter para array (para persistência)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'briefing_uuid' => $this->briefingUuid,
            'version' => $this->version,
            'normalized_data' => $this->normalizedData,
            'derived_metrics' => $this->derivedMetrics,
            'snapshot_at' => $this->snapshotAt->format('Y-m-d H:i:s'),
            'snapshot_hash' => $this->snapshotHash,
        ];
    }
}
