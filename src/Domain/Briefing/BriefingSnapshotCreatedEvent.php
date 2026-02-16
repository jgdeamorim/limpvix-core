<?php
/**
 * BriefingSnapshotCreatedEvent - Domain Event
 *
 * Disparado quando snapshot imutável do Briefing é criado (após lock).
 *
 * IMPORTANTE:
 * - Evento CRÍTICO para rastreabilidade jurídica
 * - Deve ser registrado no ledger ANTES de qualquer outro processamento
 * - Contém hash do snapshot para auditoria dupla (snapshot DB + ledger)
 *
 * LISTENERS ESPERADOS:
 * - LedgerEventRecorder (registra no ledger)
 * - AllocationEngineListener (dispara alocação usando snapshot)
 * - AuditLogListener (registra em audit trail)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.3.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class BriefingSnapshotCreatedEvent
{
    /**
     * UUID do Briefing
     *
     * @var string
     */
    private $briefingUuid;

    /**
     * Snapshot criado
     *
     * @var BriefingSnapshot
     */
    private $snapshot;

    /**
     * Timestamp do evento
     *
     * @var \DateTimeImmutable
     */
    private $occurredAt;

    /**
     * Construtor
     *
     * @param string $briefingUuid
     * @param BriefingSnapshot $snapshot
     */
    public function __construct(string $briefingUuid, BriefingSnapshot $snapshot)
    {
        $this->briefingUuid = $briefingUuid;
        $this->snapshot = $snapshot;
        $this->occurredAt = new \DateTimeImmutable();
    }

    /**
     * Obter UUID do Briefing
     *
     * @return string
     */
    public function getBriefingUuid(): string
    {
        return $this->briefingUuid;
    }

    /**
     * Obter snapshot criado
     *
     * @return BriefingSnapshot
     */
    public function getSnapshot(): BriefingSnapshot
    {
        return $this->snapshot;
    }

    /**
     * Obter timestamp do evento
     *
     * @return \DateTimeImmutable
     */
    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Converter para array (para ledger, logs, webhooks)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'event_type' => 'briefing_snapshot_created',
            'briefing_uuid' => $this->briefingUuid,
            'snapshot_version' => $this->snapshot->getVersion(),
            'snapshot_hash' => $this->snapshot->getSnapshotHash(),
            'snapshot_at' => $this->snapshot->getSnapshotAt()->format('Y-m-d\TH:i:sP'),
            'estimated_duration_minutes' => $this->snapshot->getMetric('estimated_duration_minutes', 0),
            'total_price' => $this->snapshot->getMetric('pricing_breakdown')['total_price'] ?? 0.0,
            'requires_multiple_professionals' => $this->snapshot->getMetric('requires_multiple_professionals', false),
            'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:sP'),
        ];
    }
}
