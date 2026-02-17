<?php

declare(strict_types=1);

namespace LimpVix\Domain\Professional;

use LimpVix\Domain\Professional\ValueObjects\DocumentType;
use LimpVix\Domain\Professional\ValueObjects\DocumentStatus;

/**
 * Professional Document Repository Interface
 *
 * Define contract para persistência de documentos de profissionais
 */
interface ProfessionalDocumentRepositoryInterface
{
    /**
     * Save document (create or update)
     *
     * @param ProfessionalDocument $document
     * @return ProfessionalDocument Document with ID populated
     */
    public function save(ProfessionalDocument $document): ProfessionalDocument;

    /**
     * Find document by ID
     *
     * @param int $id
     * @return ProfessionalDocument|null
     */
    public function findById(int $id): ?ProfessionalDocument;

    /**
     * Find all documents for a professional
     *
     * @param int $professionalId
     * @param int $limit
     * @param int $offset
     * @return ProfessionalDocument[]
     */
    public function findByProfessionalId(
        int $professionalId,
        int $limit = 100,
        int $offset = 0
    ): array;

    /**
     * Find documents by professional ID and type
     *
     * @param int $professionalId
     * @param DocumentType $documentType
     * @return ProfessionalDocument[]
     */
    public function findByProfessionalIdAndType(
        int $professionalId,
        DocumentType $documentType
    ): array;

    /**
     * Find documents by status
     *
     * @param DocumentStatus $status
     * @param int $limit
     * @param int $offset
     * @return ProfessionalDocument[]
     */
    public function findByStatus(
        DocumentStatus $status,
        int $limit = 100,
        int $offset = 0
    ): array;

    /**
     * Find expired documents
     *
     * @param int $limit
     * @param int $offset
     * @return ProfessionalDocument[]
     */
    public function findExpired(int $limit = 100, int $offset = 0): array;

    /**
     * Find documents expiring soon (within days)
     *
     * @param int $withinDays Number of days to check ahead
     * @param int $limit
     * @param int $offset
     * @return ProfessionalDocument[]
     */
    public function findExpiringSoon(
        int $withinDays = 30,
        int $limit = 100,
        int $offset = 0
    ): array;

    /**
     * Count documents by professional ID
     *
     * @param int $professionalId
     * @return int
     */
    public function countByProfessionalId(int $professionalId): int;

    /**
     * Count documents by status
     *
     * @param DocumentStatus $status
     * @return int
     */
    public function countByStatus(DocumentStatus $status): int;

    /**
     * Delete document by ID
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool;

    /**
     * Check if professional has approved document of type
     *
     * @param int $professionalId
     * @param DocumentType $documentType
     * @return bool
     */
    public function hasApprovedDocument(
        int $professionalId,
        DocumentType $documentType
    ): bool;

    /**
     * Get professional's KYC completion percentage
     *
     * @param int $professionalId
     * @return float Percentage (0-100)
     */
    public function getKycCompletionPercentage(int $professionalId): float;
}
