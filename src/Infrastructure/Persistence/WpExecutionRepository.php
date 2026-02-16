<?php
declare(strict_types=1);

/**
 * WpExecutionRepository - WordPress implementation of ExecutionRepositoryInterface
 * (Sprint 1 - Dia 5)
 *
 * RESPONSABILIDADE:
 * - Persistir Execution Aggregate no WordPress
 * - Hidratar/desidratar Value Objects (JSON)
 * - Garantir atomicidade de save/load
 *
 * PRINCÍPIOS:
 * - SEM lógica de negócio
 * - SEM validações de domínio
 * - Apenas persistência e reconstrução
 * - Mapeamento explícito (sem "mágica")
 *
 * @package LimpVix\Infrastructure\Persistence
 */

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\Evidence;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Domain\Execution\ValueObjects\SlaViolation;

defined('ABSPATH') || exit;

class WpExecutionRepository implements ExecutionRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_executions';
    }

    /**
     * Salvar Execution (insert ou update)
     */
    public function save(Execution $execution): void
    {
        $data = $this->dehydrate($execution);
        
        if ($this->exists($execution->getExecutionUuid())) {
            // UPDATE
            $result = $this->wpdb->update(
                $this->table,
                $data,
                ['execution_uuid' => $execution->getExecutionUuid()],
                $this->getFormat(),
                ['%s']
            );
        } else {
            // INSERT
            $result = $this->wpdb->insert(
                $this->table,
                $data,
                $this->getFormat()
            );
        }

        if ($result === false) {
            throw new \RuntimeException(sprintf(
                'Failed to save Execution [%s]: %s',
                $execution->getExecutionUuid(),
                $this->wpdb->last_error
            ));
        }
    }

    /**
     * Buscar Execution por UUID
     */
    public function findByUuid(string $uuid): ?Execution
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE execution_uuid = %s",
                $uuid
            ),
            ARRAY_A
        );

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Buscar Execution por Order UUID
     */
    public function findByOrderUuid(string $orderUuid): ?Execution
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE order_uuid = %s",
                $orderUuid
            ),
            ARRAY_A
        );

        return $row ? $this->hydrate($row) : null;
    }

    /**
     * Verificar se Execution existe
     */
    public function exists(string $uuid): bool
    {
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE execution_uuid = %s",
                $uuid
            )
        );

        return (int) $count > 0;
    }

    /**
     * Deletar Execution
     */
    public function delete(string $uuid): void
    {
        $result = $this->wpdb->delete(
            $this->table,
            ['execution_uuid' => $uuid],
            ['%s']
        );

        if ($result === false) {
            throw new \RuntimeException(sprintf(
                'Failed to delete Execution [%s]: %s',
                $uuid,
                $this->wpdb->last_error
            ));
        }
    }

    // ========================================
    // REALLOCATION SUPPORT METHODS
    // ========================================

    /**
     * Find active executions for a contract (blocks reallocation)
     *
     * Active = in_progress status (check-in done, but not checked out yet)
     *
     * @param int $contractId Contract ID
     * @return array Array of execution data (not full Execution objects)
     */
    public function findActiveExecutionsForContract(int $contractId): array
    {
        // TODO: Need contract_id column in executions table
        // For now, we'll return empty array as executions don't have direct contract_id
        // In reality, we'd need to join with orders table or add contract_id to executions

        // This is a placeholder implementation
        // Real implementation needs contract_id field in wp_limpvix_executions
        return [];

        /* Future implementation when contract_id is added:
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE contract_id = %d
             AND status = 'in_progress'
             ORDER BY scheduled_start_time ASC",
            $contractId
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A);
        return $results ?? [];
        */
    }

    /**
     * Find pending/scheduled executions for a contract (will be updated on reallocation)
     *
     * Pending = scheduled status (not yet started)
     *
     * @param int $contractId Contract ID
     * @return array Array of execution data
     */
    public function findPendingExecutionsForContract(int $contractId): array
    {
        // TODO: Need contract_id column in executions table
        // For now, we'll return empty array

        return [];

        /* Future implementation:
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE contract_id = %d
             AND status = 'scheduled'
             ORDER BY scheduled_start_time ASC",
            $contractId
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A);
        return $results ?? [];
        */
    }

    /**
     * Update professional for an execution (used in reallocation)
     *
     * @param int $executionId Execution ID (primary key, not UUID)
     * @param int $newProfessionalId New professional ID
     * @return bool Success
     */
    public function updateProfessional(int $executionId, int $newProfessionalId): bool
    {
        $updated = $this->wpdb->update(
            $this->table,
            [
                'professional_id' => $newProfessionalId,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $executionId],
            ['%d', '%s'],
            ['%d']
        );

        return $updated !== false;
    }

    // ========================================
    // HYDRATION / DEHYDRATION
    // ========================================

    /**
     * Desidratar Execution para array (WordPress-friendly)
     */
    private function dehydrate(Execution $execution): array
    {
        return [
            'execution_uuid' => $execution->getExecutionUuid(),
            'order_uuid' => $execution->getOrderUuid(),
            'professional_id' => $execution->getProfessionalId(),
            'status' => $execution->getStatus()->value,
            'scheduled_start_time' => $execution->getScheduledStartTime()?->format('Y-m-d H:i:s'),
            'service_location' => $this->serializeGeoLocation($execution->getServiceLocation()),
            'geofence_radius_meters' => $execution->getGeofenceRadiusMeters(),
            'check_in_at' => $execution->getCheckInAt()?->format('Y-m-d H:i:s'),
            'check_in_geo' => $this->serializeGeoLocation($execution->getCheckInGeo()),
            'check_out_at' => $execution->getCheckOutAt()?->format('Y-m-d H:i:s'),
            'check_out_geo' => $this->serializeGeoLocation($execution->getCheckOutGeo()),
            'evidence' => $this->serializeEvidenceCollection($execution->getEvidence()),
            'sla_violations' => $this->serializeSlaViolations($execution->getSlaViolations()),
            'feedback_window_expires_at' => $execution->getFeedbackWindowExpiresAt()?->format('Y-m-d H:i:s'),
            'updated_at' => current_time('mysql'),
        ];
    }

    /**
     * Hidratar array para Execution (reconstruir Aggregate)
     */
    private function hydrate(array $row): Execution
    {
        return new Execution(
            $row['execution_uuid'],
            $row['order_uuid'],
            (int) $row['professional_id'],
            ExecutionStatusEnum::from($row['status']),
            $row['scheduled_start_time'] ? new \DateTimeImmutable($row['scheduled_start_time']) : null,
            $this->deserializeGeoLocation($row['service_location']),
            (int) $row['geofence_radius_meters'],
            $row['check_in_at'] ? new \DateTimeImmutable($row['check_in_at']) : null,
            $this->deserializeGeoLocation($row['check_in_geo']),
            $row['check_out_at'] ? new \DateTimeImmutable($row['check_out_at']) : null,
            $this->deserializeGeoLocation($row['check_out_geo']),
            $this->deserializeEvidenceCollection($row['evidence']),
            $this->deserializeSlaViolations($row['sla_violations']),
            isset($row['feedback_window_expires_at']) && $row['feedback_window_expires_at']
                ? new \DateTimeImmutable($row['feedback_window_expires_at'])
                : null
        );
    }

    // ========================================
    // VALUE OBJECT SERIALIZATION
    // ========================================

    private function serializeGeoLocation(?GeoLocation $geo): ?string
    {
        if ($geo === null) return null;

        return json_encode([
            'latitude' => $geo->latitude,
            'longitude' => $geo->longitude,
        ]);
    }

    private function deserializeGeoLocation(?string $json): ?GeoLocation
    {
        if (empty($json)) return null;

        $data = json_decode($json, true);
        if (!$data) return null;

        return new GeoLocation(
            $data['latitude'],
            $data['longitude'],
            $data['longitude']
        );
    }

    private function serializeEvidenceCollection(?EvidenceCollection $collection): ?string
    {
        if ($collection === null) return null;

        $items = array_map(function(Evidence $evidence) {
            return [
                'type' => $evidence->type,
                'url' => $evidence->url,
                'timestamp' => $evidence->timestamp->format('Y-m-d H:i:s'),
            ];
        }, $collection->all());

        return json_encode($items);
    }

    private function deserializeEvidenceCollection(?string $json): ?EvidenceCollection
    {
        if (empty($json)) return null;

        $items = json_decode($json, true);
        if (!$items || !is_array($items)) return null;

        $evidences = array_map(function(array $item) {
            return new Evidence(
                $item['type'],
                $item['url'],
                new \DateTimeImmutable($item['timestamp'])
            );
        }, $items);

        return new EvidenceCollection($evidences);
    }

    private function serializeSlaViolations(array $violations): ?string
    {
        if (empty($violations)) return null;

        $items = array_map(function(SlaViolation $violation) {
            return [
                'reason' => $violation->reason,
                'detected_at' => $violation->detectedAt->format('Y-m-d H:i:s'),
                'metadata' => $violation->metadata,
            ];
        }, $violations);

        return json_encode($items);
    }

    private function deserializeSlaViolations(?string $json): array
    {
        if (empty($json)) return [];

        $items = json_decode($json, true);
        if (!$items || !is_array($items)) return [];

        return array_map(function(array $item) {
            return new SlaViolation(
                $item['reason'],
                new \DateTimeImmutable($item['detected_at']),
                $item['metadata'] ?? []
            );
        }, $items);
    }

    /**
     * Retorna formatos wpdb para save
     */
    private function getFormat(): array
    {
        return [
            '%s', // execution_uuid
            '%s', // order_uuid
            '%d', // professional_id
            '%s', // status
            '%s', // scheduled_start_time
            '%s', // service_location
            '%d', // geofence_radius_meters
            '%s', // check_in_at
            '%s', // check_in_geo
            '%s', // check_out_at
            '%s', // check_out_geo
            '%s', // evidence
            '%s', // sla_violations
            '%s', // updated_at
        ];
    }
}
