<?php
/**
 * WpBriefingRepository - Implementação WordPress do BriefingRepositoryInterface
 *
 * RESPONSABILIDADE:
 * - Persistir/recuperar Briefings no banco de dados WordPress
 * - Hidratação: DB arrays → Value Objects → Briefing
 * - Desidratação: Briefing → Value Objects → DB arrays
 * - Gerenciar 3 tabelas: briefings, briefing_data, briefing_ledger
 *
 * PADRÕES:
 * - Repository Pattern
 * - Hidratação/Desidratação completa
 * - Transações para integridade
 * - JSON para dados complexos (estrutura, frequência, etc)
 *
 * @package LimpVix\Infrastructure\Persistence
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Domain\Briefing\PropertyType;
use LimpVix\Domain\Briefing\PropertyStructure;
use LimpVix\Domain\Briefing\Frequency;
use LimpVix\Domain\Briefing\EstimatedMetrics;
use LimpVix\Domain\Briefing\BriefingRepositoryInterface;

defined('ABSPATH') || exit;

class WpBriefingRepository implements BriefingRepositoryInterface
{
    /**
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Tabelas
     */
    private $tableBriefings;
    private $tableBriefingData;
    private $tableBriefingLedger;

    /**
     * Construtor
     */
    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableBriefings = $wpdb->prefix . 'limpvix_briefings';
        $this->tableBriefingData = $wpdb->prefix . 'limpvix_briefing_data';
        $this->tableBriefingLedger = $wpdb->prefix . 'limpvix_briefing_ledger';
    }

    /**
     * {@inheritDoc}
     */
    public function findByUuid(string $uuid): ?Briefing
    {
        // 1. Buscar registro principal
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tableBriefings} WHERE uuid = %s",
            $uuid
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if ($row === null) {
            return null;
        }

        // 2. Buscar dados JSON
        $dataRows = $this->findDataByUuid($uuid);

        // 3. Hidratar Briefing
        return $this->hydrate($row, $dataRows);
    }

    /**
     * {@inheritDoc}
     */
    public function findByOrderId(int $orderId): ?Briefing
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tableBriefings} WHERE order_id = %d",
            $orderId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if ($row === null) {
            return null;
        }

        $dataRows = $this->findDataByUuid($row['uuid']);
        return $this->hydrate($row, $dataRows);
    }

    /**
     * {@inheritDoc}
     */
    public function findByUserId(int $userId, int $limit = 10): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tableBriefings} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $userId,
            $limit
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        $briefings = [];

        foreach ($rows as $row) {
            $dataRows = $this->findDataByUuid($row['uuid']);
            $briefings[] = $this->hydrate($row, $dataRows);
        }

        return $briefings;
    }

    /**
     * {@inheritDoc}
     */
    public function findByStatus(BriefingStatus $status, int $limit = 100): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->tableBriefings} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
            $status->getValue(),
            $limit
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        $briefings = [];

        foreach ($rows as $row) {
            $dataRows = $this->findDataByUuid($row['uuid']);
            $briefings[] = $this->hydrate($row, $dataRows);
        }

        return $briefings;
    }

    /**
     * {@inheritDoc}
     */
    public function save(Briefing $briefing): bool
    {
        // Iniciar transação
        $this->wpdb->query('START TRANSACTION');

        try {
            // 1. Verificar se existe
            $exists = $this->exists($briefing->getUuid());

            // 2. Desidratar
            $mainData = $this->dehydrateMain($briefing);
            $jsonData = $this->dehydrateData($briefing);

            // 3. Salvar tabela principal
            if ($exists) {
                $updated = $this->wpdb->update(
                    $this->tableBriefings,
                    $mainData,
                    ['uuid' => $briefing->getUuid()],
                    $this->getMainFormats(),
                    ['%s']
                );

                if ($updated === false) {
                    throw new \RuntimeException("Erro ao atualizar Briefing");
                }
            } else {
                $inserted = $this->wpdb->insert(
                    $this->tableBriefings,
                    $mainData,
                    $this->getMainFormats()
                );

                if ($inserted === false) {
                    throw new \RuntimeException("Erro ao inserir Briefing");
                }
            }

            // 4. Salvar dados JSON
            foreach ($jsonData as $key => $value) {
                $this->saveData($briefing->getUuid(), $key, $value);
            }

            // 5. Commit
            $this->wpdb->query('COMMIT');
            return true;

        } catch (\Exception $e) {
            $this->wpdb->query('ROLLBACK');

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[LimpVix] Erro ao salvar Briefing: ' . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function exists(string $uuid): bool
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableBriefings} WHERE uuid = %s",
            $uuid
        );

        return (int) $this->wpdb->get_var($sql) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $uuid): bool
    {
        // Verificar se está locked
        $briefing = $this->findByUuid($uuid);

        if ($briefing && $briefing->isLocked()) {
            return false; // Não pode deletar locked
        }

        // Soft delete (apenas muda status) ou hard delete?
        // Por enquanto: hard delete
        $deleted = $this->wpdb->delete(
            $this->tableBriefings,
            ['uuid' => $uuid],
            ['%s']
        );

        return $deleted !== false;
    }

    /**
     * {@inheritDoc}
     */
    public function count(?BriefingStatus $status = null): int
    {
        if ($status === null) {
            $sql = "SELECT COUNT(*) FROM {$this->tableBriefings}";
            return (int) $this->wpdb->get_var($sql);
        }

        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableBriefings} WHERE status = %s",
            $status->getValue()
        );

        return (int) $this->wpdb->get_var($sql);
    }

    // ==================== MÉTODOS AUXILIARES ====================

    /**
     * Buscar dados JSON por UUID
     *
     * @param string $uuid
     * @return array ['structure' => {...}, 'frequency' => {...}, etc]
     */
    private function findDataByUuid(string $uuid): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT data_key, data_value FROM {$this->tableBriefingData} WHERE briefing_uuid = %s",
            $uuid
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);
        $data = [];

        foreach ($rows as $row) {
            $data[$row['data_key']] = json_decode($row['data_value'], true);
        }

        return $data;
    }

    /**
     * Salvar dado JSON
     *
     * @param string $uuid
     * @param string $key
     * @param mixed $value
     * @return void
     */
    private function saveData(string $uuid, string $key, $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        $this->wpdb->replace(
            $this->tableBriefingData,
            [
                'briefing_uuid' => $uuid,
                'data_key' => $key,
                'data_value' => $json,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Hidratar: DB → Briefing
     *
     * @param array $mainRow Linha da tabela principal
     * @param array $dataRows Dados JSON por key
     * @return Briefing
     */
    private function hydrate(array $mainRow, array $dataRows): Briefing
    {
        // Status
        $status = BriefingStatus::fromString($mainRow['status']);

        // PropertyType
        $propertyType = PropertyType::fromString($mainRow['property_type']);

        // PropertyStructure (pode ser null)
        $structure = null;
        if (isset($dataRows['structure'])) {
            $structure = PropertyStructure::fromArray($dataRows['structure']);
        }

        // Frequency (pode ser null)
        $frequency = null;
        if (isset($dataRows['frequency'])) {
            $frequency = Frequency::fromArray($dataRows['frequency']);
        }

        // EstimatedMetrics (pode ser null)
        $metrics = null;
        if ($mainRow['estimated_m2'] !== null) {
            $metrics = new EstimatedMetrics(
                (float) $mainRow['estimated_m2'],
                (int) $mainRow['estimated_duration_minutes'],
                (int) $mainRow['buffer_minutes']
            );
        }

        // Construir Briefing
        return new Briefing(
            uuid: $mainRow['uuid'],
            userId: (int) $mainRow['user_id'],
            propertyType: $propertyType,
            status: $status,
            orderId: $mainRow['order_id'] !== null ? (int) $mainRow['order_id'] : null,
            structure: $structure,
            frequency: $frequency,
            metrics: $metrics,
            phoneVerified: (bool) $mainRow['phone_verified'],
            version: $mainRow['version'],
            createdAt: new \DateTimeImmutable($mainRow['created_at']),
            updatedAt: new \DateTimeImmutable($mainRow['updated_at']),
            lockedAt: $mainRow['locked_at'] ? new \DateTimeImmutable($mainRow['locked_at']) : null
        );
    }

    /**
     * Desidratar: Briefing → DB (tabela principal)
     *
     * @param Briefing $briefing
     * @return array
     */
    private function dehydrateMain(Briefing $briefing): array
    {
        $metrics = $briefing->getMetrics();

        return [
            'uuid' => $briefing->getUuid(),
            'order_id' => $briefing->getOrderId(),
            'user_id' => $briefing->getUserId(),
            'status' => $briefing->getStatus()->getValue(),
            'property_type' => $briefing->getPropertyType()->getValue(),
            'estimated_m2' => $metrics ? $metrics->getM2() : null,
            'estimated_duration_minutes' => $metrics ? $metrics->getDurationMinutes() : null,
            'buffer_minutes' => $metrics ? $metrics->getBufferMinutes() : 30,
            'requires_contract' => $briefing->requiresContract(),
            'phone_verified' => $briefing->isPhoneVerified(),
            'version' => $briefing->getVersion(),
            'created_at' => $briefing->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $briefing->getUpdatedAt()->format('Y-m-d H:i:s'),
            'locked_at' => $briefing->getLockedAt() ? $briefing->getLockedAt()->format('Y-m-d H:i:s') : null
        ];
    }

    /**
     * Desidratar: Briefing → DB (dados JSON)
     *
     * @param Briefing $briefing
     * @return array
     */
    private function dehydrateData(Briefing $briefing): array
    {
        $data = [];

        if ($briefing->getStructure() !== null) {
            $data['structure'] = $briefing->getStructure()->toArray();
        }

        if ($briefing->getFrequency() !== null) {
            $data['frequency'] = $briefing->getFrequency()->toArray();
        }

        return $data;
    }

    /**
     * Formatos para wpdb (tabela principal)
     *
     * @return array
     */
    private function getMainFormats(): array
    {
        return [
            '%s', // uuid
            '%d', // order_id
            '%d', // user_id
            '%s', // status
            '%s', // property_type
            '%f', // estimated_m2
            '%d', // estimated_duration_minutes
            '%d', // buffer_minutes
            '%d', // requires_contract
            '%d', // phone_verified
            '%s', // version
            '%s', // created_at
            '%s', // updated_at
            '%s'  // locked_at
        ];
    }
}
