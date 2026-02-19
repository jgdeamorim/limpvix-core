<?php

declare(strict_types=1);

namespace LimpVix\Application\UseCases\Professional;

use LimpVix\Domain\Professional\ProfessionalDocument;
use LimpVix\Domain\Professional\ProfessionalDocumentRepositoryInterface;
use LimpVix\Domain\Professional\ValueObjects\DocumentType;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use DateTimeImmutable;

/**
 * Upload Document Use Case
 *
 * Permite profissional fazer upload de documentos para verificação KYC
 */
final class UploadDocument
{
    // Allowed file types
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'application/pdf',
    ];

    // Max file size: 5MB
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(
        private ProfessionalDocumentRepositoryInterface $documentRepository,
        private ProfessionalRepositoryInterface $professionalRepository
    ) {
    }

    /**
     * Execute upload
     *
     * @param UploadDocumentCommand $command
     * @return ProfessionalDocument
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function execute(UploadDocumentCommand $command): ProfessionalDocument
    {
        // Validate professional exists
        $professional = $this->professionalRepository->findById($command->professionalId);
        if (!$professional) {
            throw new \InvalidArgumentException(
                "Professional #{$command->professionalId} not found"
            );
        }

        // Validate file
        $this->validateFile($command);

        // Upload to WordPress media library
        $uploadResult = $this->uploadToMediaLibrary($command);

        if (is_wp_error($uploadResult)) {
            throw new \RuntimeException(
                "File upload failed: " . $uploadResult->get_error_message()
            );
        }

        // Create attachment
        $attachmentId = $this->createAttachment($uploadResult, $command);

        // Determine expiry date for certificates
        $expiresAt = $this->calculateExpiryDate($command->documentType);

        // Create document entity
        $document = ProfessionalDocument::create(
            professionalId: $command->professionalId,
            documentType: $command->documentType,
            filePath: $uploadResult['file'],
            attachmentId: $attachmentId,
            mimeType: $uploadResult['type'],
            fileSize: filesize($uploadResult['file']),
            originalFilename: $command->fileName,
            expiresAt: $expiresAt,
            metadata: $command->metadata
        );

        // Save to database
        $savedDocument = $this->documentRepository->save($document);

        // Dispatch event DocumentUploaded for notifications
        if (function_exists('do_action')) {
            do_action('limpvix_document_uploaded', $savedDocument->getId(), $command->professionalId);
        }

        return $savedDocument;
    }

    /**
     * Validate uploaded file
     */
    private function validateFile(UploadDocumentCommand $command): void
    {
        // Check file size
        if ($command->fileSize > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(
                sprintf(
                    'File too large. Maximum size: %s MB',
                    self::MAX_FILE_SIZE / 1024 / 1024
                )
            );
        }

        // Check MIME type
        if (!in_array($command->mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid file type: %s. Allowed types: %s',
                    $command->mimeType,
                    implode(', ', self::ALLOWED_MIME_TYPES)
                )
            );
        }

        // Additional security checks
        $this->validateFileContent($command->fileTmpName, $command->mimeType);
    }

    /**
     * Validate file content matches MIME type
     */
    private function validateFileContent(string $tmpFile, string $declaredMimeType): void
    {
        // Use finfo to detect actual file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMimeType = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);

        if ($actualMimeType !== $declaredMimeType) {
            throw new \InvalidArgumentException(
                "File content does not match declared type. Possible security risk."
            );
        }
    }

    /**
     * Upload file to WordPress media library
     */
    private function uploadToMediaLibrary(UploadDocumentCommand $command): array|\WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Prepare file array for wp_handle_upload
        $file = [
            'name' => $command->fileName,
            'type' => $command->mimeType,
            'tmp_name' => $command->fileTmpName,
            'error' => 0,
            'size' => $command->fileSize,
        ];

        // Upload file
        $uploadOverrides = [
            'test_form' => false,
            'test_type' => true,
        ];

        return wp_handle_upload($file, $uploadOverrides);
    }

    /**
     * Create WordPress attachment post
     */
    private function createAttachment(array $uploadResult, UploadDocumentCommand $command): int
    {
        $attachmentData = [
            'post_mime_type' => $uploadResult['type'],
            'post_title' => sanitize_file_name(
                pathinfo($command->fileName, PATHINFO_FILENAME)
            ),
            'post_content' => '',
            'post_status' => 'inherit',
            'post_author' => $command->professionalId,
        ];

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachmentId = wp_insert_attachment(
            $attachmentData,
            $uploadResult['file']
        );

        // Generate metadata for images
        if (str_starts_with($uploadResult['type'], 'image/')) {
            $attachmentMetadata = wp_generate_attachment_metadata(
                $attachmentId,
                $uploadResult['file']
            );
            wp_update_attachment_metadata($attachmentId, $attachmentMetadata);
        }

        // Add custom meta for LimpVix
        update_post_meta($attachmentId, '_limpvix_professional_id', $command->professionalId);
        update_post_meta($attachmentId, '_limpvix_document_type', $command->documentType->getValue());

        return $attachmentId;
    }

    /**
     * Calculate expiry date for certificates
     */
    private function calculateExpiryDate(DocumentType $documentType): ?DateTimeImmutable
    {
        if (!$documentType->requiresExpiry()) {
            return null;
        }

        // Default certificate validity: 2 years
        $validityYears = match ($documentType->getValue()) {
            DocumentType::CERTIFICATE_NR35 => 2,
            DocumentType::CERTIFICATE_NR10 => 2,
            DocumentType::CERTIFICATE_NR06 => 1,
            default => 2,
        };

        return (new DateTimeImmutable())->modify("+{$validityYears} years");
    }
}

/**
 * Upload Document Command
 */
final class UploadDocumentCommand
{
    public function __construct(
        public readonly int $professionalId,
        public readonly DocumentType $documentType,
        public readonly string $fileTmpName,
        public readonly string $fileName,
        public readonly string $mimeType,
        public readonly int $fileSize,
        public readonly ?array $metadata = null
    ) {
    }
}
