<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Admin\Ajax;

use LimpVix\Application\UseCases\Professional\ReviewDocument;
use LimpVix\Infrastructure\Persistence\WpProfessionalDocumentRepository;

/**
 * AJAX Handler for Document Review
 *
 * Handles AJAX requests for approving/rejecting documents
 */
final class DocumentReviewAjaxHandler
{
    private ReviewDocument $reviewDocument;

    public function __construct()
    {
        $repository = new WpProfessionalDocumentRepository();
        $this->reviewDocument = new ReviewDocument($repository);
    }

    /**
     * Register AJAX handlers
     */
    public function register(): void
    {
        add_action('wp_ajax_limpvix_approve_document', [$this, 'handleApprove']);
        add_action('wp_ajax_limpvix_reject_document', [$this, 'handleReject']);
    }

    /**
     * Handle approve document AJAX request
     */
    public function handleApprove(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'limpvix_document_review')) {
            wp_send_json_error([
                'message' => 'Nonce verification failed',
            ], 403);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Access denied',
            ], 403);
        }

        // Get document ID
        $documentId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;

        if (!$documentId) {
            wp_send_json_error([
                'message' => 'Document ID is required',
            ], 400);
        }

        try {
            $reviewerId = get_current_user_id();
            $document = $this->reviewDocument->approve($documentId, $reviewerId);

            wp_send_json_success([
                'message' => 'Document approved successfully',
                'document' => [
                    'id' => $document->getId(),
                    'status' => $document->getStatus()->getValue(),
                ],
            ]);

        } catch (\InvalidArgumentException $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], 404);

        } catch (\DomainException $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Internal server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle reject document AJAX request
     */
    public function handleReject(): void
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'limpvix_document_review')) {
            wp_send_json_error([
                'message' => 'Nonce verification failed',
            ], 403);
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Access denied',
            ], 403);
        }

        // Get document ID
        $documentId = isset($_POST['document_id']) ? (int) $_POST['document_id'] : 0;

        if (!$documentId) {
            wp_send_json_error([
                'message' => 'Document ID is required',
            ], 400);
        }

        // Get rejection reason
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

        if (empty(trim($reason))) {
            wp_send_json_error([
                'message' => 'Rejection reason is required',
            ], 400);
        }

        try {
            $reviewerId = get_current_user_id();
            $document = $this->reviewDocument->reject($documentId, $reviewerId, $reason);

            wp_send_json_success([
                'message' => 'Document rejected successfully',
                'document' => [
                    'id' => $document->getId(),
                    'status' => $document->getStatus()->getValue(),
                    'rejection_reason' => $document->getRejectionReason(),
                ],
            ]);

        } catch (\InvalidArgumentException $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], 404);

        } catch (\DomainException $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Internal server error: ' . $e->getMessage(),
            ], 500);
        }
    }
}
