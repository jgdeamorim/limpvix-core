<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Professional;

use LimpVix\Domain\Professional\ProfessionalDocument;
use LimpVix\Domain\Professional\ProfessionalDocumentRepositoryInterface;
use LimpVix\Domain\Professional\ValueObjects\DocumentStatus;

/**
 * List Documents Use Case
 *
 * Lista documentos de profissionais com filtros e paginação
 */
final class ListDocuments
{
    public function __construct(
        private ProfessionalDocumentRepositoryInterface $documentRepository
    ) {
    }

    /**
     * List documents for a professional
     *
     * @param int $professionalId
     * @param int $limit
     * @param int $offset
     * @return array ['documents' => ProfessionalDocument[], 'total' => int]
     */
    public function forProfessional(
        int $professionalId,
        int $limit = 50,
        int $offset = 0
    ): array {
        $documents = $this->documentRepository->findByProfessionalId(
            $professionalId,
            $limit,
            $offset
        );

        $total = $this->documentRepository->countByProfessionalId($professionalId);

        return [
            'documents' => $documents,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * List pending documents (for admin review)
     *
     * @param int $limit
     * @param int $offset
     * @return array ['documents' => ProfessionalDocument[], 'total' => int]
     */
    public function pending(int $limit = 50, int $offset = 0): array
    {
        $documents = $this->documentRepository->findByStatus(
            DocumentStatus::pending(),
            $limit,
            $offset
        );

        $total = $this->documentRepository->countByStatus(
            DocumentStatus::pending()
        );

        return [
            'documents' => $documents,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * List expired certificates
     *
     * @param int $limit
     * @param int $offset
     * @return array ['documents' => ProfessionalDocument[], 'total' => int]
     */
    public function expired(int $limit = 50, int $offset = 0): array
    {
        $documents = $this->documentRepository->findExpired($limit, $offset);

        // Count by finding all and filtering
        $allExpired = $this->documentRepository->findExpired(10000, 0);
        $total = count($allExpired);

        return [
            'documents' => $documents,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * List certificates expiring soon
     *
     * @param int $withinDays
     * @param int $limit
     * @param int $offset
     * @return array ['documents' => ProfessionalDocument[], 'total' => int]
     */
    public function expiringSoon(
        int $withinDays = 30,
        int $limit = 50,
        int $offset = 0
    ): array {
        $documents = $this->documentRepository->findExpiringSoon(
            $withinDays,
            $limit,
            $offset
        );

        // Count by finding all and filtering
        $allExpiring = $this->documentRepository->findExpiringSoon($withinDays, 10000, 0);
        $total = count($allExpiring);

        return [
            'documents' => $documents,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'within_days' => $withinDays,
        ];
    }

    /**
     * Get KYC status for professional
     *
     * @param int $professionalId
     * @return array ['completion_percentage' => float, 'documents' => array]
     */
    public function getKycStatus(int $professionalId): array
    {
        $completionPercentage = $this->documentRepository->getKycCompletionPercentage(
            $professionalId
        );

        $allDocuments = $this->documentRepository->findByProfessionalId(
            $professionalId,
            100,
            0
        );

        // Group by status
        $byStatus = [
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'expired' => 0,
        ];

        foreach ($allDocuments as $doc) {
            $status = $doc->getStatus()->getValue();
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        return [
            'completion_percentage' => $completionPercentage,
            'total_documents' => count($allDocuments),
            'by_status' => $byStatus,
            'documents' => $allDocuments,
        ];
    }
}
