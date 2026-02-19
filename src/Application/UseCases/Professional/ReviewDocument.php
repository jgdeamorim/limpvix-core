<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Professional;

use LimpVix\Domain\Professional\ProfessionalDocument;
use LimpVix\Domain\Professional\ProfessionalDocumentRepositoryInterface;

/**
 * Review Document Use Case
 *
 * Permite admin aprovar ou rejeitar documentos enviados por profissionais
 */
final class ReviewDocument
{
    public function __construct(
        private ProfessionalDocumentRepositoryInterface $documentRepository
    ) {
    }

    /**
     * Approve document
     *
     * @param int $documentId
     * @param int $reviewerId User ID do admin que está aprovando
     * @return ProfessionalDocument
     * @throws \InvalidArgumentException
     * @throws \DomainException
     */
    public function approve(int $documentId, int $reviewerId): ProfessionalDocument
    {
        $document = $this->documentRepository->findById($documentId);

        if (!$document) {
            throw new \InvalidArgumentException(
                "Document #{$documentId} not found"
            );
        }

        // Approve via domain logic
        $document->approve($reviewerId);

        // Save
        $savedDocument = $this->documentRepository->save($document);

        // Dispatch event DocumentApproved for notifications
        if (function_exists('do_action')) {
            do_action('limpvix_document_approved', $documentId, $document->getProfessionalId(), $reviewerId);
        }

        return $savedDocument;
    }

    /**
     * Reject document
     *
     * @param int $documentId
     * @param int $reviewerId User ID do admin que está rejeitando
     * @param string $reason Motivo da rejeição
     * @return ProfessionalDocument
     * @throws \InvalidArgumentException
     * @throws \DomainException
     */
    public function reject(
        int $documentId,
        int $reviewerId,
        string $reason
    ): ProfessionalDocument {
        $document = $this->documentRepository->findById($documentId);

        if (!$document) {
            throw new \InvalidArgumentException(
                "Document #{$documentId} not found"
            );
        }

        // Reject via domain logic
        $document->reject($reviewerId, $reason);

        // Save
        $savedDocument = $this->documentRepository->save($document);

        // Dispatch event DocumentRejected for notifications
        if (function_exists('do_action')) {
            do_action('limpvix_document_rejected', $documentId, $document->getProfessionalId(), $reviewerId, $reason);
        }

        return $savedDocument;
    }

    /**
     * Batch approve multiple documents
     *
     * @param int[] $documentIds
     * @param int $reviewerId
     * @return array ['approved' => int, 'failed' => int, 'errors' => array]
     */
    public function batchApprove(array $documentIds, int $reviewerId): array
    {
        $approved = 0;
        $failed = 0;
        $errors = [];

        foreach ($documentIds as $documentId) {
            try {
                $this->approve($documentId, $reviewerId);
                $approved++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'document_id' => $documentId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'approved' => $approved,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}
