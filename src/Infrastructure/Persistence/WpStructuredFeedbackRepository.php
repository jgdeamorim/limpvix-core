<?php
/**
 * WpStructuredFeedbackRepository - Infrastructure
 *
 * RESPONSABILIDADE:
 * - Persistir StructuredFeedback em wp_limpvix_structured_feedbacks
 * - Hidratar StructuredFeedback do banco
 * - Buscar feedbacks por order, customer, status
 *
 * @package LimpVix\Infrastructure\Persistence
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Feedback\StructuredFeedback;
use LimpVix\Domain\Feedback\FeedbackChecklist;
use LimpVix\Domain\Feedback\FeedbackCriteria;
use LimpVix\Domain\Feedback\FeedbackPhotos;

defined('ABSPATH') || exit;

class WpStructuredFeedbackRepository
{
    private $wpdb;
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_structured_feedbacks';
    }

    /**
     * Salvar feedback
     *
     * @param StructuredFeedback $feedback Feedback
     * @return bool
     */
    public function save(StructuredFeedback $feedback): bool
    {
        $data = $this->dehydrate($feedback);

        // Verificar se já existe
        $exists = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE uuid = %s",
            $feedback->getUuid()
        ));

        if ($exists > 0) {
            // Update
            $result = $this->wpdb->update(
                $this->table,
                $data,
                ['uuid' => $feedback->getUuid()],
                null, // Formato automático
                ['%s']
            );
        } else {
            // Insert
            $result = $this->wpdb->insert(
                $this->table,
                $data
            );
        }

        if ($result === false) {
            error_log('[LimpVix] Failed to save structured feedback: ' . $this->wpdb->last_error);
            return false;
        }

        return true;
    }

    /**
     * Buscar feedback por UUID
     *
     * @param string $uuid UUID
     * @return StructuredFeedback|null
     */
    public function findByUuid(string $uuid): ?StructuredFeedback
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE uuid = %s LIMIT 1",
            $uuid
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Buscar feedback por order UUID
     *
     * @param string $orderUuid UUID da order
     * @return StructuredFeedback|null
     */
    public function findByOrderUuid(string $orderUuid): ?StructuredFeedback
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE order_uuid = %s LIMIT 1",
            $orderUuid
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Buscar feedbacks por cliente
     *
     * @param int $customerId ID do cliente
     * @param int $limit Limite
     * @return StructuredFeedback[]
     */
    public function findByCustomer(int $customerId, int $limit = 50): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE customer_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $customerId,
            $limit
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Buscar feedbacks por status
     *
     * @param string $status Status
     * @param int $limit Limite
     * @return StructuredFeedback[]
     */
    public function findByStatus(string $status, int $limit = 50): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = %s
             ORDER BY created_at DESC
             LIMIT %d",
            $status,
            $limit
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Hidratar StructuredFeedback do banco
     *
     * @param array $row Linha do banco
     * @return StructuredFeedback
     */
    private function hydrate(array $row): StructuredFeedback
    {
        // Decodificar JSON
        $checklistData = json_decode($row['checklist_data'], true);
        $photosData = json_decode($row['photos'], true);

        // Recriar StructuredFeedback via reflection (não há método de hidratação pública)
        $feedback = StructuredFeedback::createDraft(
            $row['uuid'],
            $row['order_uuid'],
            (int) $row['customer_id'],
            $row['service_category']
        );

        // Usar reflection para definir valores privados
        $reflection = new \ReflectionClass($feedback);

        // Reconstruir checklist
        $checklist = $this->hydrateChecklist($checklistData, $row['service_category']);
        $checklistProp = $reflection->getProperty('checklist');
        $checklistProp->setAccessible(true);
        $checklistProp->setValue($feedback, $checklist);

        // Reconstruir photos
        if (!empty($photosData)) {
            $photos = new FeedbackPhotos($photosData);
            $photosProp = $reflection->getProperty('photos');
            $photosProp->setAccessible(true);
            $photosProp->setValue($feedback, $photos);
        }

        // General comment
        if (!empty($row['general_comment'])) {
            $commentProp = $reflection->getProperty('generalComment');
            $commentProp->setAccessible(true);
            $commentProp->setValue($feedback, $row['general_comment']);
        }

        // Status
        $statusProp = $reflection->getProperty('status');
        $statusProp->setAccessible(true);
        $statusProp->setValue($feedback, $row['status']);

        // Timestamps
        $createdAtProp = $reflection->getProperty('createdAt');
        $createdAtProp->setAccessible(true);
        $createdAtProp->setValue($feedback, new \DateTimeImmutable($row['created_at']));

        if (!empty($row['submitted_at'])) {
            $submittedAtProp = $reflection->getProperty('submittedAt');
            $submittedAtProp->setAccessible(true);
            $submittedAtProp->setValue($feedback, new \DateTimeImmutable($row['submitted_at']));
        }

        return $feedback;
    }

    /**
     * Hidratar checklist
     *
     * @param array $checklistData Dados do checklist
     * @param string $category Categoria
     * @return FeedbackChecklist
     */
    private function hydrateChecklist(array $checklistData, string $category): FeedbackChecklist
    {
        $criteria = [];

        foreach ($checklistData['criteria'] ?? [] as $criterionData) {
            $criteria[] = new FeedbackCriteria(
                $criterionData['criteria_id'],
                $criterionData['label'],
                $criterionData['score'],
                $criterionData['observation'] ?? null
            );
        }

        return new FeedbackChecklist($criteria, $category);
    }

    /**
     * Desidratar StructuredFeedback para banco
     *
     * @param StructuredFeedback $feedback Feedback
     * @return array
     */
    private function dehydrate(StructuredFeedback $feedback): array
    {
        $data = [
            'uuid' => $feedback->getUuid(),
            'order_uuid' => $feedback->getOrderUuid(),
            'customer_id' => $feedback->getCustomerId(),
            'service_category' => $feedback->getServiceCategory(),
            'checklist_data' => $feedback->getChecklist()->toJson(),
            'photos' => $feedback->getPhotos() ? $feedback->getPhotos()->toJson() : '[]',
            'general_comment' => $feedback->getGeneralComment(),
            'final_score' => $feedback->getFinalScore(),
            'status' => $feedback->getStatus(),
            'created_at' => $feedback->getCreatedAt()->format('Y-m-d H:i:s'),
            'submitted_at' => $feedback->getSubmittedAt()?->format('Y-m-d H:i:s'),
        ];

        return $data;
    }
}
