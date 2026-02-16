<?php
/**
 * LedgerEntry - Entrada do Ledger Financeiro
 *
 * RESPONSABILIDADE:
 * - Representar um registro imutável no ledger
 * - Armazenar fato passado (Domain Event materializado)
 * - Sem lógica de negócio (apenas dados)
 *
 * PRINCÍPIOS:
 * - Imutável (após criação, nunca muda)
 * - Entity (tem identidade - ledger_uuid)
 * - Fato histórico (representa o que aconteceu)
 * - Auditável
 *
 * DIFERENÇA COM FinancialTransitionEvent:
 * - Event: momento da decisão (efêmero, em memória)
 * - Entry: registro da decisão (persistente, no banco)
 *
 * USO:
 * ```php
 * // Criar a partir de evento
 * $entry = LedgerEntry::fromEvent($event);
 *
 * // Criar manualmente (reconstrução)
 * $entry = new LedgerEntry(
 *     ledgerUuid: '...',
 *     orderUuid: '...',
 *     fromStatus: FinancialStatus::REVIEW(),
 *     toStatus: FinancialStatus::AUTHORIZED(),
 *     reason: 'positive_feedback',
 *     actor: 'system',
 *     actorId: null,
 *     createdAt: new \DateTime(),
 *     payload: [...]
 * );
 * ```
 *
 * PASSO 5.2 - Ledger Imutável
 *
 * @package LimpVix\Domain\Finance
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

class LedgerEntry
{
    /**
     * UUID do registro no ledger (PK)
     *
     * @var string
     */
    private $ledgerUuid;

    /**
     * UUID da Order
     *
     * @var string
     */
    private $orderUuid;

    /**
     * Estado de origem
     *
     * @var FinancialStatus
     */
    private $fromStatus;

    /**
     * Estado de destino
     *
     * @var FinancialStatus
     */
    private $toStatus;

    /**
     * Razão da transição
     *
     * @var string
     */
    private $reason;

    /**
     * Ator da transição
     *
     * @var string (system, admin, customer, etc)
     */
    private $actor;

    /**
     * ID do ator (se aplicável)
     *
     * @var int|null
     */
    private $actorId;

    /**
     * Timestamp da criação (imutável)
     *
     * @var \DateTime
     */
    private $createdAt;

    /**
     * Payload adicional (JSON)
     *
     * @var array
     */
    private $payload;

    /**
     * Construtor
     *
     * @param string $ledgerUuid UUID do registro
     * @param string $orderUuid UUID da Order
     * @param FinancialStatus $fromStatus Estado origem
     * @param FinancialStatus $toStatus Estado destino
     * @param string $reason Razão
     * @param string $actor Ator
     * @param int|null $actorId ID do ator
     * @param \DateTime $createdAt Timestamp
     * @param array $payload Dados adicionais
     */
    public function __construct(
        string $ledgerUuid,
        string $orderUuid,
        FinancialStatus $fromStatus,
        FinancialStatus $toStatus,
        string $reason,
        string $actor,
        ?int $actorId,
        \DateTime $createdAt,
        array $payload = []
    ) {
        $this->ledgerUuid = $ledgerUuid;
        $this->orderUuid = $orderUuid;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->reason = $reason;
        $this->actor = $actor;
        $this->actorId = $actorId;
        $this->createdAt = $createdAt;
        $this->payload = $payload;
    }

    /**
     * Factory: criar a partir de FinancialTransitionEvent
     *
     * @param FinancialTransitionEvent $event
     * @return self
     */
    public static function fromEvent(FinancialTransitionEvent $event): self
    {
        return new self(
            ledgerUuid: self::generateUuid(),
            orderUuid: $event->getOrderUuid(),
            fromStatus: $event->getFrom(),
            toStatus: $event->getTo(),
            reason: $event->getReason(),
            actor: $event->getActor(),
            actorId: $event->getActorId(),
            createdAt: $event->getOccurredAt(),
            payload: []
        );
    }

    /**
     * Factory: reconstruir a partir de dados do banco
     *
     * @param array $data Dados brutos do banco
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromDatabase(array $data): self
    {
        // Validar campos obrigatórios
        $required = ['ledger_uuid', 'order_uuid', 'from_status', 'to_status', 'reason', 'actor', 'created_at'];
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Campo obrigatório ausente: {$field}");
            }
        }

        // Parsear payload JSON
        $payload = [];
        if (isset($data['payload_json']) && !empty($data['payload_json'])) {
            $decoded = json_decode($data['payload_json'], true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        // Parsear created_at
        $createdAt = new \DateTime($data['created_at']);

        return new self(
            ledgerUuid: $data['ledger_uuid'],
            orderUuid: $data['order_uuid'],
            fromStatus: FinancialStatus::fromValue($data['from_status']),
            toStatus: FinancialStatus::fromValue($data['to_status']),
            reason: $data['reason'],
            actor: $data['actor'],
            actorId: isset($data['actor_id']) ? (int) $data['actor_id'] : null,
            createdAt: $createdAt,
            payload: $payload
        );
    }

    /**
     * Gerar UUID v4
     *
     * @return string
     */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versão 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variante RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ========================================
    // GETTERS (SOMENTE LEITURA)
    // ========================================

    /**
     * Obter UUID do ledger
     *
     * @return string
     */
    public function getLedgerUuid(): string
    {
        return $this->ledgerUuid;
    }

    /**
     * Obter UUID da Order
     *
     * @return string
     */
    public function getOrderUuid(): string
    {
        return $this->orderUuid;
    }

    /**
     * Obter estado de origem
     *
     * @return FinancialStatus
     */
    public function getFromStatus(): FinancialStatus
    {
        return $this->fromStatus;
    }

    /**
     * Obter estado de destino
     *
     * @return FinancialStatus
     */
    public function getToStatus(): FinancialStatus
    {
        return $this->toStatus;
    }

    /**
     * Obter razão
     *
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Obter ator
     *
     * @return string
     */
    public function getActor(): string
    {
        return $this->actor;
    }

    /**
     * Obter ID do ator
     *
     * @return int|null
     */
    public function getActorId(): ?int
    {
        return $this->actorId;
    }

    /**
     * Obter timestamp de criação
     *
     * @return \DateTime
     */
    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    /**
     * Obter payload
     *
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    // ========================================
    // SERIALIZAÇÃO
    // ========================================

    /**
     * Converter para array (para persistência)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'ledger_uuid' => $this->ledgerUuid,
            'order_uuid' => $this->orderUuid,
            'from_status' => $this->fromStatus->getValue(),
            'to_status' => $this->toStatus->getValue(),
            'reason' => $this->reason,
            'actor' => $this->actor,
            'actor_id' => $this->actorId,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'payload_json' => !empty($this->payload) ? json_encode($this->payload) : null
        ];
    }

    /**
     * Representação em string
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            '[%s] %s: %s → %s (reason: %s, actor: %s)',
            $this->createdAt->format('Y-m-d H:i:s'),
            $this->orderUuid,
            $this->fromStatus->getValue(),
            $this->toStatus->getValue(),
            $this->reason,
            $this->actor
        );
    }
}
