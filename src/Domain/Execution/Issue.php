<?php
/**
 * Issue - Value Object
 *
 * Representa um problema/issue reportado durante execução
 *
 * GAP #4: Issue Reporting System
 * - Cliente pode reportar problemas (qualidade, danos, itens faltando)
 * - Professional pode reportar dificuldades (acesso, equipamento)
 * - Admin pode visualizar e resolver issues
 *
 * TIPOS DE ISSUES:
 * - quality: Problema de qualidade do serviço
 * - damage: Dano causado durante serviço
 * - missing_items: Itens/produtos faltando
 * - access: Dificuldade de acesso ao local
 * - equipment: Problema com equipamento
 * - other: Outros problemas
 *
 * @package LimpVix\Domain\Execution
 * @since 1.0.0 (GAP #4 Implementation)
 */

namespace LimpVix\Domain\Execution;

use DateTimeImmutable;

defined('ABSPATH') || exit;

final class Issue
{
    private string $type;
    private string $description;
    private ?string $reportedBy; // 'customer' | 'professional' | 'admin'
    private int $reportedByUserId;
    private DateTimeImmutable $reportedAt;
    private ?string $status; // 'open' | 'investigating' | 'resolved' | 'closed'
    private ?string $resolution;
    private ?int $resolvedByUserId;
    private ?DateTimeImmutable $resolvedAt;
    private array $evidenceUrls; // Fotos/vídeos do problema

    private function __construct(
        string $type,
        string $description,
        string $reportedBy,
        int $reportedByUserId,
        DateTimeImmutable $reportedAt,
        string $status = 'open',
        ?string $resolution = null,
        ?int $resolvedByUserId = null,
        ?DateTimeImmutable $resolvedAt = null,
        array $evidenceUrls = []
    ) {
        $this->type = $type;
        $this->description = $description;
        $this->reportedBy = $reportedBy;
        $this->reportedByUserId = $reportedByUserId;
        $this->reportedAt = $reportedAt;
        $this->status = $status;
        $this->resolution = $resolution;
        $this->resolvedByUserId = $resolvedByUserId;
        $this->resolvedAt = $resolvedAt;
        $this->evidenceUrls = $evidenceUrls;
    }

    /**
     * Criar novo issue
     *
     * @param string $type Tipo do issue (quality, damage, missing_items, access, equipment, other)
     * @param string $description Descrição do problema
     * @param string $reportedBy Quem reportou (customer, professional, admin)
     * @param int $reportedByUserId User ID de quem reportou
     * @param array $evidenceUrls URLs de fotos/vídeos do problema
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function create(
        string $type,
        string $description,
        string $reportedBy,
        int $reportedByUserId,
        array $evidenceUrls = []
    ): self {
        // Validar tipo
        $validTypes = ['quality', 'damage', 'missing_items', 'access', 'equipment', 'other'];
        if (!in_array($type, $validTypes, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid issue type: %s. Valid types: %s', $type, implode(', ', $validTypes))
            );
        }

        // Validar reportedBy
        $validReporters = ['customer', 'professional', 'admin'];
        if (!in_array($reportedBy, $validReporters, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid reportedBy: %s. Valid values: %s', $reportedBy, implode(', ', $validReporters))
            );
        }

        // Validar descrição
        if (empty(trim($description))) {
            throw new \InvalidArgumentException('Issue description cannot be empty');
        }

        return new self(
            $type,
            trim($description),
            $reportedBy,
            $reportedByUserId,
            new DateTimeImmutable(),
            'open',
            null,
            null,
            null,
            $evidenceUrls
        );
    }

    /**
     * Reconstituir issue de dados persistidos
     */
    public static function reconstitute(array $data): self
    {
        return new self(
            $data['type'],
            $data['description'],
            $data['reported_by'],
            (int) $data['reported_by_user_id'],
            new DateTimeImmutable($data['reported_at']),
            $data['status'] ?? 'open',
            $data['resolution'] ?? null,
            isset($data['resolved_by_user_id']) ? (int) $data['resolved_by_user_id'] : null,
            isset($data['resolved_at']) ? new DateTimeImmutable($data['resolved_at']) : null,
            !empty($data['evidence_urls']) ? json_decode($data['evidence_urls'], true) : []
        );
    }

    /**
     * Marcar como resolvido
     */
    public function resolve(string $resolution, int $resolvedByUserId): void
    {
        if ($this->status === 'resolved' || $this->status === 'closed') {
            throw new \DomainException('Issue already resolved or closed');
        }

        if (empty(trim($resolution))) {
            throw new \InvalidArgumentException('Resolution description cannot be empty');
        }

        $this->status = 'resolved';
        $this->resolution = trim($resolution);
        $this->resolvedByUserId = $resolvedByUserId;
        $this->resolvedAt = new DateTimeImmutable();
    }

    /**
     * Marcar como em investigação
     */
    public function markAsInvestigating(): void
    {
        if ($this->status !== 'open') {
            throw new \DomainException('Can only investigate open issues');
        }

        $this->status = 'investigating';
    }

    /**
     * Fechar issue (após resolução)
     */
    public function close(): void
    {
        if ($this->status !== 'resolved') {
            throw new \DomainException('Can only close resolved issues');
        }

        $this->status = 'closed';
    }

    /**
     * Adicionar evidência (foto/vídeo)
     */
    public function addEvidence(string $url): void
    {
        if (empty(trim($url))) {
            throw new \InvalidArgumentException('Evidence URL cannot be empty');
        }

        $this->evidenceUrls[] = trim($url);
    }

    // Getters
    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getReportedBy(): string
    {
        return $this->reportedBy;
    }

    public function getReportedByUserId(): int
    {
        return $this->reportedByUserId;
    }

    public function getReportedAt(): DateTimeImmutable
    {
        return $this->reportedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function getResolvedByUserId(): ?int
    {
        return $this->resolvedByUserId;
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function getEvidenceUrls(): array
    {
        return $this->evidenceUrls;
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved' || $this->status === 'closed';
    }

    /**
     * Converter para array (persistência)
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'description' => $this->description,
            'reported_by' => $this->reportedBy,
            'reported_by_user_id' => $this->reportedByUserId,
            'reported_at' => $this->reportedAt->format('Y-m-d H:i:s'),
            'status' => $this->status,
            'resolution' => $this->resolution,
            'resolved_by_user_id' => $this->resolvedByUserId,
            'resolved_at' => $this->resolvedAt?->format('Y-m-d H:i:s'),
            'evidence_urls' => json_encode($this->evidenceUrls),
        ];
    }

    /**
     * Get human-readable type label
     */
    public function getTypeLabel(): string
    {
        return match($this->type) {
            'quality' => 'Problema de Qualidade',
            'damage' => 'Dano Causado',
            'missing_items' => 'Itens Faltando',
            'access' => 'Problema de Acesso',
            'equipment' => 'Problema com Equipamento',
            'other' => 'Outro',
            default => ucfirst($this->type),
        };
    }

    /**
     * Get status badge color
     */
    public function getStatusColor(): string
    {
        return match($this->status) {
            'open' => 'red',
            'investigating' => 'orange',
            'resolved' => 'green',
            'closed' => 'gray',
            default => 'blue',
        };
    }
}
