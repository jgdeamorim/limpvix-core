<?php
namespace LimpVix\Application\UseCase\Professional;

use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;
use LimpVix\Infrastructure\KYC\PPIDProviderFactory;

defined('ABSPATH') || exit;

/**
 * Process KYC Verification
 *
 * Orquestra o fluxo completo de verificação biométrica:
 * 1. OCR do documento (RG/CNH)
 * 2. Liveness Detection (prova de vida)
 * 3. Face Match (comparação facial)
 * 4. Decisão automática baseada em scores
 *
 * @package LimpVix\Application\UseCase\Professional
 */
final class ProcessKYC
{
    private ProfessionalRepositoryInterface $professionalRepository;

    // Thresholds para aprovação automática
    private const MIN_OCR_CONFIDENCE = 85.0;
    private const MIN_LIVENESS_SCORE = 80.0;
    private const MIN_FACEMATCH_SCORE = 85.0;

    public function __construct(ProfessionalRepositoryInterface $professionalRepository)
    {
        $this->professionalRepository = $professionalRepository;
    }

    /**
     * Execute KYC verification flow
     *
     * @param int $professionalId Professional ID
     * @param string $documentImageBase64 Base64-encoded document image (RG/CNH)
     * @param string $selfieImageBase64 Base64-encoded selfie image
     * @param string $documentType Document type ('rg' or 'cnh')
     * @return array Result data with status and details
     * @throws \RuntimeException if professional not found or KYC not enabled
     */
    public function execute(
        int $professionalId,
        string $documentImageBase64,
        string $selfieImageBase64,
        string $documentType = 'rg'
    ): array {
        // Verify PPID is enabled
        if (!get_option('limpvix_ppid_enabled', false)) {
            throw new \RuntimeException('PPID KYC is not enabled. Please enable it in Settings > Connections.');
        }

        // Load professional
        $professional = $this->professionalRepository->findById($professionalId);

        if (!$professional) {
            throw new \RuntimeException("Professional #{$professionalId} not found");
        }

        // Check retry limit
        if ($professional->getKycRetryCount() >= 3 && $professional->getKycStatus() === 'rejected') {
            throw new \RuntimeException(
                'Maximum retry attempts (3) exceeded. Please contact admin for manual review.'
            );
        }

        // Get PPID provider (Real or Mock based on settings)
        $ppid = PPIDProviderFactory::create();

        // Mark KYC as started if first attempt
        if ($professional->getKycStatus() === 'not_started') {
            $professional->startKyc();
        }

        // Mark as processing
        $professional->submitKycDocuments(
            $this->saveDocumentImage($documentImageBase64, $professionalId, 'document'),
            $this->saveDocumentImage($selfieImageBase64, $professionalId, 'selfie'),
            $documentType
        );

        // Save initial state
        $this->professionalRepository->save($professional);

        try {
            // STEP 1: OCR - Extract document data
            $ocrResult = $ppid->ocr($documentImageBase64, $this->getMimeTypeFromBase64($documentImageBase64));
            $professional->storeOcrData($ocrResult);

            $ocrConfidence = $ocrResult['confidence'] ?? 0;

            if ($ocrConfidence < self::MIN_OCR_CONFIDENCE) {
                $this->rejectKyc(
                    $professional,
                    sprintf('OCR confidence too low: %.2f%% (required: %.2f%%)', $ocrConfidence, self::MIN_OCR_CONFIDENCE)
                );

                return [
                    'success' => false,
                    'status' => 'rejected',
                    'reason' => 'OCR failed - document image quality too low',
                    'details' => [
                        'ocr_confidence' => $ocrConfidence,
                        'min_required' => self::MIN_OCR_CONFIDENCE,
                    ],
                ];
            }

            // STEP 2: Liveness - Verify selfie is a real person
            $livenessResult = $ppid->liveness($selfieImageBase64);
            $professional->storeLivenessData($livenessResult);

            $livenessScore = $livenessResult['liveness_score'] ?? 0;

            if ($livenessScore < self::MIN_LIVENESS_SCORE) {
                $this->rejectKyc(
                    $professional,
                    sprintf('Liveness score too low: %.2f%% (required: %.2f%%)', $livenessScore, self::MIN_LIVENESS_SCORE)
                );

                return [
                    'success' => false,
                    'status' => 'rejected',
                    'reason' => 'Liveness detection failed - please use a real-time selfie',
                    'details' => [
                        'liveness_score' => $livenessScore,
                        'min_required' => self::MIN_LIVENESS_SCORE,
                    ],
                ];
            }

            // STEP 3: Face Match - Compare document photo with selfie
            $faceMatchResult = $ppid->faceMatch($documentImageBase64, $selfieImageBase64);
            $professional->storeFaceMatchData($faceMatchResult);

            $faceMatchScore = $faceMatchResult['similarity_score'] ?? 0;

            if ($faceMatchScore < self::MIN_FACEMATCH_SCORE) {
                $this->rejectKyc(
                    $professional,
                    sprintf('Face match score too low: %.2f%% (required: %.2f%%)', $faceMatchScore, self::MIN_FACEMATCH_SCORE)
                );

                return [
                    'success' => false,
                    'status' => 'rejected',
                    'reason' => 'Face match failed - document photo does not match selfie',
                    'details' => [
                        'face_match_score' => $faceMatchScore,
                        'min_required' => self::MIN_FACEMATCH_SCORE,
                    ],
                ];
            }

            // ALL CHECKS PASSED - Approve KYC
            $professional->approveKyc(null, 24); // Valid for 24 months
            $this->professionalRepository->save($professional);

            return [
                'success' => true,
                'status' => 'approved',
                'message' => 'KYC verification successful',
                'details' => [
                    'ocr_confidence' => $ocrConfidence,
                    'liveness_score' => $livenessScore,
                    'face_match_score' => $faceMatchScore,
                    'document_data' => $ocrResult['extracted_data'] ?? null,
                    'expires_at' => $professional->getKycExpiresAt()?->format('Y-m-d H:i:s'),
                ],
            ];

        } catch (\Exception $e) {
            // Handle API errors
            $professional->addKycAdminNotes('PPID API Error: ' . $e->getMessage());
            $this->professionalRepository->save($professional);

            throw new \RuntimeException(
                sprintf('KYC verification failed: %s', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Reject KYC and increment retry count
     */
    private function rejectKyc($professional, string $reason): void
    {
        $professional->rejectKyc($reason, null);
        $this->professionalRepository->save($professional);
    }

    /**
     * Save document image to WordPress uploads directory
     *
     * @param string $base64Image Base64-encoded image
     * @param int $professionalId Professional ID
     * @param string $type 'document' or 'selfie'
     * @return string URL of saved image
     */
    private function saveDocumentImage(string $base64Image, int $professionalId, string $type): string
    {
        // Decode base64
        $imageData = base64_decode($base64Image);

        if ($imageData === false) {
            throw new \RuntimeException('Invalid base64 image data');
        }

        // Get mime type and extension
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $imageData);
        finfo_close($finfo);

        $extension = match($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new \RuntimeException("Unsupported image type: {$mimeType}"),
        };

        // Generate unique filename
        $filename = sprintf(
            'kyc-%d-%s-%s.%s',
            $professionalId,
            $type,
            wp_generate_password(8, false),
            $extension
        );

        // WordPress uploads directory
        $upload = wp_upload_dir();
        $uploadPath = $upload['basedir'] . '/kyc-documents';

        // Create directory if not exists
        if (!file_exists($uploadPath)) {
            wp_mkdir_p($uploadPath);

            // Add .htaccess to protect directory
            file_put_contents(
                $uploadPath . '/.htaccess',
                "# Protect KYC documents\nDeny from all\n"
            );
        }

        // Save file
        $filePath = $uploadPath . '/' . $filename;

        if (file_put_contents($filePath, $imageData) === false) {
            throw new \RuntimeException('Failed to save document image');
        }

        // Return URL (will be served through WordPress auth check)
        return $upload['baseurl'] . '/kyc-documents/' . $filename;
    }

    /**
     * Get MIME type from base64 string
     *
     * @param string $base64 Base64-encoded image with or without data URI prefix
     * @return string|null MIME type or null
     */
    private function getMimeTypeFromBase64(string $base64): ?string
    {
        // Check if base64 has data URI prefix
        if (preg_match('/^data:([^;]+);base64,/', $base64, $matches)) {
            return $matches[1];
        }

        // Decode and detect
        $imageData = base64_decode($base64);

        if ($imageData === false) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_buffer($finfo, $imageData);
        finfo_close($finfo);

        return $mimeType ?: null;
    }
}
