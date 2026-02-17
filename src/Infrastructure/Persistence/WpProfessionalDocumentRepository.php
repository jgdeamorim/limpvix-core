<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Professional\ProfessionalDocument;
use LimpVix\Domain\Professional\ProfessionalDocumentRepositoryInterface;
use LimpVix\Domain\Professional\ValueObjects\DocumentType;
use LimpVix\Domain\Professional\ValueObjects\DocumentStatus;

/**
 * WordPress Professional Document Repository
 *
 * Implementation using WordPress wpdb
 */
final class WpProfessionalDocumentRepository implements ProfessionalDocumentRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_professional_documents';
    }

    public function save(ProfessionalDocument $document): ProfessionalDocument
    {
        $data = $this->toDatabase($document);

        if ($document->getId() === null) {
            // Insert
            $result = $this->wpdb->insert(
                $this->table,
                $data,
                $this->getFormats()
            );

            if ($result === false) {
                throw new \RuntimeException(
                    "Failed to save document: " . $this->wpdb->last_error
                );
            }

            // Reconstitute with new ID
            $data['id'] = $this->wpdb->insert_id;
            return ProfessionalDocument::reconstitute($data);
        }

        // Update
        $result = $this->wpdb->update(
            $this->table,
            $data,
            ['id' => $document->getId()],
            $this->getFormats(),
            ['%d']
        );

        if ($result === false) {
            throw new \RuntimeException(
                "Failed to update document: " . $this->wpdb->last_error
            );
        }

        return $document;
    }

    public function findById(int $id): ?ProfessionalDocument
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return $row ? ProfessionalDocument::reconstitute($row) : null;
    }

    public function findByProfessionalId(
        int $professionalId,
        int $limit = 100,
        int $offset = 0
    ): array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE professional_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $professionalId,
            $limit,
            $offset
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(
            fn($row) => ProfessionalDocument::reconstitute($row),
            $rows
        );
    }

    public function findByProfessionalIdAndType(
        int $professionalId,
        DocumentType $documentType
    ): array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE professional_id = %d
             AND document_type = %s
             ORDER BY created_at DESC",
            $professionalId,
            $documentType->getValue()
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(
            fn($row) => ProfessionalDocument::reconstitute($row),
            $rows
        );
    }

    public function findByStatus(
        DocumentStatus $status,
        int $limit = 100,
        int $offset = 0
    ): array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = %s
             ORDER BY created_at ASC
             LIMIT %d OFFSET %d",
            $status->getValue(),
            $limit,
            $offset
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(
            fn($row) => ProfessionalDocument::reconstitute($row),
            $rows
        );
    }

    public function findExpired(int $limit = 100, int $offset = 0): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE (status = 'expired'
                OR (expires_at IS NOT NULL AND expires_at < NOW()))
             ORDER BY expires_at ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(
            fn($row) => ProfessionalDocument::reconstitute($row),
            $rows
        );
    }

    public function findExpiringSoon(
        int $withinDays = 30,
        int $limit = 100,
        int $offset = 0
    ): array {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE expires_at IS NOT NULL
             AND expires_at > NOW()
             AND expires_at <= DATE_ADD(NOW(), INTERVAL %d DAY)
             AND status = 'approved'
             ORDER BY expires_at ASC
             LIMIT %d OFFSET %d",
            $withinDays,
            $limit,
            $offset
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map(
            fn($row) => ProfessionalDocument::reconstitute($row),
            $rows
        );
    }

    public function countByProfessionalId(int $professionalId): int
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE professional_id = %d",
            $professionalId
        );

        return (int) $this->wpdb->get_var($sql);
    }

    public function countByStatus(DocumentStatus $status): int
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
            $status->getValue()
        );

        return (int) $this->wpdb->get_var($sql);
    }

    public function delete(int $id): bool
    {
        $result = $this->wpdb->delete(
            $this->table,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    public function hasApprovedDocument(
        int $professionalId,
        DocumentType $documentType
    ): bool {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE professional_id = %d
             AND document_type = %s
             AND status = 'approved'
             LIMIT 1",
            $professionalId,
            $documentType->getValue()
        );

        return (int) $this->wpdb->get_var($sql) > 0;
    }

    public function getKycCompletionPercentage(int $professionalId): float
    {
        // Required documents for KYC
        $requiredTypes = [
            DocumentType::CPF_FRONT,
            DocumentType::RG_FRONT,
            DocumentType::SELFIE,
            DocumentType::PROOF_OF_ADDRESS,
        ];

        $approved = 0;
        $total = count($requiredTypes);

        foreach ($requiredTypes as $type) {
            if ($this->hasApprovedDocument($professionalId, DocumentType::fromString($type))) {
                $approved++;
            }
        }

        return ($approved / $total) * 100;
    }

    /**
     * Convert document to database array
     */
    private function toDatabase(ProfessionalDocument $document): array
    {
        $data = $document->toArray();

        // Remove id for inserts
        if ($document->getId() === null) {
            unset($data['id']);
        }

        return $data;
    }

    /**
     * Get column formats for wpdb
     */
    private function getFormats(): array
    {
        return [
            '%d', // professional_id
            '%s', // document_type
            '%s', // file_path
            '%d', // attachment_id
            '%s', // mime_type
            '%d', // file_size
            '%s', // original_filename
            '%s', // status
            '%d', // reviewed_by
            '%s', // reviewed_at
            '%s', // rejection_reason
            '%s', // expires_at
            '%s', // metadata
            '%s', // created_at
            '%s', // updated_at
        ];
    }
}
