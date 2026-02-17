<?php

/**
 * CheckInPerformed - Evento de domínio disparado quando profissional realiza check-in
 *
 * Evento disparado imediatamente após um profissional confirmar sua chegada ao local
 * de serviço. Carrega os dados necessários para notificar o cliente e registrar o evento.
 *
 * GAP #3: Client Check-in Notification
 * - Dispara notificação automática ao cliente
 * - Canal primário: WhatsApp → SMS → Email (fallback)
 *
 * @package LimpVix\Domain\Execution\Events
 * @since 1.0.0 (GAP #3 Implementation)
 */

namespace LimpVix\Domain\Execution\Events;

defined('ABSPATH') || exit;

final class CheckInPerformed
{
    private string $executionUuid;
    private int $orderId;
    private int $professionalId;
    private \DateTimeImmutable $occurredAt;
    private ?float $latitude;
    private ?float $longitude;

    public function __construct(
        string $executionUuid,
        int $orderId,
        int $professionalId,
        ?float $latitude = null,
        ?float $longitude = null
    ) {
        $this->executionUuid  = $executionUuid;
        $this->orderId        = $orderId;
        $this->professionalId = $professionalId;
        $this->latitude       = $latitude;
        $this->longitude      = $longitude;
        $this->occurredAt     = new \DateTimeImmutable();
    }

    public function getExecutionUuid(): string
    {
        return $this->executionUuid;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event'           => 'execution.check_in_performed',
            'execution_uuid'  => $this->executionUuid,
            'order_id'        => $this->orderId,
            'professional_id' => $this->professionalId,
            'latitude'        => $this->latitude,
            'longitude'       => $this->longitude,
            'occurred_at'     => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
