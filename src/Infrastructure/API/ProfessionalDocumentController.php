<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\UseCases\Professional\UploadDocument;
use LimpVix\Application\UseCases\Professional\UploadDocumentCommand;
use LimpVix\Application\UseCases\Professional\ReviewDocument;
use LimpVix\Application\UseCases\Professional\ListDocuments;
use LimpVix\Domain\Professional\ValueObjects\DocumentType;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Professional Document REST API Controller
 *
 * Endpoints:
 * - POST   /limpvix/v1/professionals/{id}/documents - Upload document
 * - GET    /limpvix/v1/professionals/{id}/documents - List documents
 * - GET    /limpvix/v1/documents/{id} - Get single document
 * - POST   /limpvix/v1/documents/{id}/approve - Approve document (admin)
 * - POST   /limpvix/v1/documents/{id}/reject - Reject document (admin)
 * - GET    /limpvix/v1/documents/pending - List pending documents (admin)
 * - GET    /limpvix/v1/professionals/{id}/kyc-status - Get KYC status
 */
final class ProfessionalDocumentController extends WP_REST_Controller
{
    private UploadDocument $uploadDocument;
    private ReviewDocument $reviewDocument;
    private ListDocuments $listDocuments;

    public function __construct(
        UploadDocument $uploadDocument,
        ReviewDocument $reviewDocument,
        ListDocuments $listDocuments
    ) {
        $this->namespace = 'limpvix/v1';
        $this->rest_base = 'professionals';

        $this->uploadDocument = $uploadDocument;
        $this->reviewDocument = $reviewDocument;
        $this->listDocuments = $listDocuments;
    }

    public function register_routes(): void
    {
        // Upload document
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/documents', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'uploadDocument'],
                'permission_callback' => [$this, 'canUploadDocument'],
                'args' => $this->getUploadDocumentArgs(),
            ],
        ]);

        // List documents for professional
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/documents', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listDocuments'],
                'permission_callback' => [$this, 'canViewDocuments'],
                'args' => [
                    'limit' => [
                        'default' => 50,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // Get KYC status
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/kyc-status', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getKycStatus'],
                'permission_callback' => [$this, 'canViewDocuments'],
            ],
        ]);

        // List pending documents (admin only)
        register_rest_route($this->namespace, '/documents/pending', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listPendingDocuments'],
                'permission_callback' => function() { return current_user_can('manage_options'); },
                'args' => [
                    'limit' => [
                        'default' => 50,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ]);

        // Approve document (admin only)
        register_rest_route($this->namespace, '/documents/(?P<id>\d+)/approve', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'approveDocument'],
                'permission_callback' => function() { return current_user_can('manage_options'); },
            ],
        ]);

        // Reject document (admin only)
        register_rest_route($this->namespace, '/documents/(?P<id>\d+)/reject', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'rejectDocument'],
                'permission_callback' => function() { return current_user_can('manage_options'); },
                'args' => [
                    'reason' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_textarea_field',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Upload document
     */
    public function uploadDocument(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $professionalId = (int) $request['id'];

        // Get file from request
        $files = $request->get_file_params();

        if (empty($files['file'])) {
            return new WP_Error(
                'no_file',
                'No file uploaded',
                ['status' => 400]
            );
        }

        $file = $files['file'];

        // Validate document type
        $documentType = $request->get_param('document_type');
        if (!in_array($documentType, DocumentType::all(), true)) {
            return new WP_Error(
                'invalid_document_type',
                'Invalid document type: ' . $documentType,
                ['status' => 400]
            );
        }

        try {
            $command = new UploadDocumentCommand(
                professionalId: $professionalId,
                documentType: DocumentType::fromString($documentType),
                fileTmpName: $file['tmp_name'],
                fileName: $file['name'],
                mimeType: $file['type'],
                fileSize: $file['size'],
                metadata: $request->get_param('metadata')
            );

            $document = $this->uploadDocument->execute($command);

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->formatDocument($document),
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return new WP_Error(
                'invalid_argument',
                $e->getMessage(),
                ['status' => 400]
            );
        } catch (\RuntimeException $e) {
            return new WP_Error(
                'upload_failed',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * List documents for professional
     */
    public function listDocuments(WP_REST_Request $request): WP_REST_Response
    {
        $professionalId = (int) $request['id'];
        $limit = (int) $request->get_param('limit');
        $offset = (int) $request->get_param('offset');

        $result = $this->listDocuments->forProfessional(
            $professionalId,
            $limit,
            $offset
        );

        return new WP_REST_Response([
            'success' => true,
            'data' => array_map(
                fn($doc) => $this->formatDocument($doc),
                $result['documents']
            ),
            'total' => $result['total'],
            'limit' => $result['limit'],
            'offset' => $result['offset'],
        ]);
    }

    /**
     * Get KYC status
     */
    public function getKycStatus(WP_REST_Request $request): WP_REST_Response
    {
        $professionalId = (int) $request['id'];

        $kycStatus = $this->listDocuments->getKycStatus($professionalId);

        return new WP_REST_Response([
            'success' => true,
            'data' => [
                'completion_percentage' => $kycStatus['completion_percentage'],
                'total_documents' => $kycStatus['total_documents'],
                'by_status' => $kycStatus['by_status'],
            ],
        ]);
    }

    /**
     * List pending documents (admin)
     */
    public function listPendingDocuments(WP_REST_Request $request): WP_REST_Response
    {
        $limit = (int) $request->get_param('limit');
        $offset = (int) $request->get_param('offset');

        $result = $this->listDocuments->pending($limit, $offset);

        return new WP_REST_Response([
            'success' => true,
            'data' => array_map(
                fn($doc) => $this->formatDocument($doc),
                $result['documents']
            ),
            'total' => $result['total'],
            'limit' => $result['limit'],
            'offset' => $result['offset'],
        ]);
    }

    /**
     * Approve document (admin)
     */
    public function approveDocument(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $documentId = (int) $request['id'];
        $reviewerId = get_current_user_id();

        try {
            $document = $this->reviewDocument->approve($documentId, $reviewerId);

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->formatDocument($document),
                'message' => 'Document approved successfully',
            ]);

        } catch (\InvalidArgumentException $e) {
            return new WP_Error(
                'document_not_found',
                $e->getMessage(),
                ['status' => 404]
            );
        } catch (\DomainException $e) {
            return new WP_Error(
                'invalid_operation',
                $e->getMessage(),
                ['status' => 400]
            );
        }
    }

    /**
     * Reject document (admin)
     */
    public function rejectDocument(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $documentId = (int) $request['id'];
        $reviewerId = get_current_user_id();
        $reason = $request->get_param('reason');

        try {
            $document = $this->reviewDocument->reject($documentId, $reviewerId, $reason);

            return new WP_REST_Response([
                'success' => true,
                'data' => $this->formatDocument($document),
                'message' => 'Document rejected successfully',
            ]);

        } catch (\InvalidArgumentException $e) {
            return new WP_Error(
                'document_not_found',
                $e->getMessage(),
                ['status' => 404]
            );
        } catch (\DomainException $e) {
            return new WP_Error(
                'invalid_operation',
                $e->getMessage(),
                ['status' => 400]
            );
        }
    }

    /**
     * Permission callback: Can upload document
     */
    public function canUploadDocument(WP_REST_Request $request): bool
    {
        $professionalId = (int) $request['id'];
        $currentUserId = get_current_user_id();

        // Admin can upload for anyone
        if (current_user_can('manage_options')) {
            return true;
        }

        // Professional can upload for themselves
        // TODO: Check if current user is linked to this professional
        return $currentUserId === $professionalId;
    }

    /**
     * Permission callback: Can view documents
     */
    public function canViewDocuments(WP_REST_Request $request): bool
    {
        return $this->canUploadDocument($request);
    }

    /**
     * Get upload document args
     */
    private function getUploadDocumentArgs(): array
    {
        return [
            'document_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => DocumentType::all(),
            ],
            'metadata' => [
                'type' => 'object',
                'default' => null,
            ],
        ];
    }

    /**
     * Format document for API response
     */
    private function formatDocument($document): array
    {
        return [
            'id' => $document->getId(),
            'professional_id' => $document->getProfessionalId(),
            'document_type' => [
                'value' => $document->getDocumentType()->getValue(),
                'label' => $document->getDocumentType()->getLabel(),
            ],
            'file_url' => wp_get_attachment_url($document->getAttachmentId()),
            'file_size' => $document->getFileSize(),
            'mime_type' => $document->getMimeType(),
            'status' => [
                'value' => $document->getStatus()->getValue(),
                'label' => $document->getStatus()->getLabel(),
                'color' => $document->getStatus()->getColor(),
            ],
            'reviewed_by' => $document->getReviewedBy(),
            'reviewed_at' => $document->getReviewedAt()?->format('c'),
            'rejection_reason' => $document->getRejectionReason(),
            'expires_at' => $document->getExpiresAt()?->format('c'),
            'is_expired' => $document->isExpired(),
            'needs_review' => $document->needsReview(),
            'created_at' => $document->getCreatedAt()->format('c'),
        ];
    }
}
