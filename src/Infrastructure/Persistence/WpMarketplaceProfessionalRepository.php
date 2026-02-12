<?php
/**
 * WpMarketplaceProfessionalRepository
 *
 * Implementação WordPress do Professional Repository para marketplace
 *
 * @package LimpVix\Infrastructure\Persistence
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Professional\Professional;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;

defined('ABSPATH') || exit;

class WpMarketplaceProfessionalRepository implements ProfessionalRepositoryInterface
{
    private $wpdb;
    private $table;
    private $scoreHistoryTable;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_professionals';
        $this->scoreHistoryTable = $wpdb->prefix . 'limpvix_professional_score_history';
    }

    public function findById(int $id): ?Professional
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id), ARRAY_A);
        return $row ? Professional::reconstitute($row) : null;
    }

    public function findByUserId(int $userId): ?Professional
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE user_id = %d", $userId), ARRAY_A);
        return $row ? Professional::reconstitute($row) : null;
    }

    public function findByCpf(string $cpf): ?Professional
    {
        $cpfClean = preg_replace('/\D/', '', $cpf);
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE cpf = %s", $cpfClean), ARRAY_A);
        return $row ? Professional::reconstitute($row) : null;
    }

    public function findEligibleFor(float $targetLat, float $targetLng, array $requiredSkills, \DateTimeImmutable $serviceDateTime, int $limit = 50, int $offset = 0): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE is_active = 1 AND is_verified = 1 AND is_permanently_banned = 0
                AND (suspended_until IS NULL OR suspended_until < NOW())
                ORDER BY score DESC LIMIT %d OFFSET %d";

        $results = $this->wpdb->get_results($this->wpdb->prepare($sql, $limit, $offset), ARRAY_A);

        $eligible = [];

        foreach ($results as $row) {
            $prof = Professional::reconstitute($row);
            if ($prof->canServeLocation($targetLat, $targetLng) &&
                $prof->hasRequiredSkills($requiredSkills) &&
                $prof->isAvailableAt($serviceDateTime)) {
                $eligible[] = $prof;
            }
        }

        return $eligible;
    }

    public function findByRegion(float $centerLat, float $centerLng, int $radiusKm, int $limit = 100, int $offset = 0): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE is_active = 1 AND latitude IS NOT NULL
             AND (6371 * acos(cos(radians(%f)) * cos(radians(latitude)) * cos(radians(longitude) - radians(%f)) + sin(radians(%f)) * sin(radians(latitude)))) <= %d
             ORDER BY score DESC LIMIT %d OFFSET %d",
            $centerLat, $centerLng, $centerLat, $radiusKm, $limit, $offset
        );

        $results = $this->wpdb->get_results($sql, ARRAY_A);
        return array_map(fn($row) => Professional::reconstitute($row), $results);
    }

    public function findBySkills(array $skills, int $limit = 100, int $offset = 0): array
    {
        $results = [];
        foreach ($skills as $skill) {
            $sql = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE is_active = 1 AND JSON_CONTAINS(skills, %s) LIMIT %d OFFSET %d", json_encode($skill), $limit, $offset);
            $rows = $this->wpdb->get_results($sql, ARRAY_A);
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!isset($results[$id])) $results[$id] = Professional::reconstitute($row);
            }
        }
        return array_values($results);
    }

    public function findActiveAndVerified(int $limit = 100, int $offset = 0): array
    {
        $results = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE is_active = 1 AND is_verified = 1 ORDER BY score DESC LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
        return array_map(fn($row) => Professional::reconstitute($row), $results);
    }

    public function findSuspended(int $limit = 100, int $offset = 0): array
    {
        $results = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE suspended_until IS NOT NULL AND suspended_until > NOW() LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
        return array_map(fn($row) => Professional::reconstitute($row), $results);
    }

    public function findPendingVerification(int $limit = 100, int $offset = 0): array
    {
        $results = $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->table} WHERE is_active = 1 AND is_verified = 0 ORDER BY created_at ASC LIMIT %d OFFSET %d", $limit, $offset), ARRAY_A);
        return array_map(fn($row) => Professional::reconstitute($row), $results);
    }

    public function findByMinScore(float $minScore, int $limit = 100, int $offset = 0): array
    {
        $sql = $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE is_active = 1 AND score >= %f ORDER BY score DESC LIMIT %d OFFSET %d", $minScore, $limit, $offset);
        $results = $this->wpdb->get_results($sql, ARRAY_A);
        return array_map(fn($row) => Professional::reconstitute($row), $results);
    }

    public function save(Professional $professional): void
    {
        $data = [
            'user_id' => $professional->getUserId(),
            'full_name' => $professional->getFullName(),
            'cpf' => $professional->getCpf(),
            'phone' => $professional->getPhone(),
            'email' => $professional->getEmail(),
            'score' => $professional->getScore(),
            'total_services' => $professional->getTotalServices(),
            'completed_services' => $professional->getCompletedServices(),
            'cancelled_services' => $professional->getCancelledServices(),
            'no_show_count' => $professional->getNoShowCount(),
            'acceptance_rate' => $professional->getAcceptanceRate(),
            'on_time_count' => $professional->getOnTimeCount(),
            'late_count' => $professional->getLateCount(),
            'avg_delay_minutes' => $professional->getAvgDelayMinutes(),
            'service_region' => $professional->getServiceRegion()->toJson(),
            'latitude' => $professional->getServiceRegion()->getCenterLatitude(),
            'longitude' => $professional->getServiceRegion()->getCenterLongitude(),
            'service_radius_km' => $professional->getServiceRegion()->getRadiusKm(),
            'skills' => $professional->getSkills()->getSkillsJson(),
            'certifications' => $professional->getSkills()->getCertificationsJson(),
            'physical_limitations' => $professional->getSkills()->getLimitationsJson(),
            'weekly_availability' => $professional->getAvailability()->toJson(),
            'max_daily_hours' => $professional->getMaxDailyHours(),
            'is_active' => $professional->isActive(),
            'is_verified' => $professional->isVerified(),
            'updated_at' => current_time('mysql'),
            // KYC fields
            "kyc_status" => $professional->getKycStatus(),
            "kyc_started_at" => $professional->getKycStartedAt()?->format("Y-m-d H:i:s"),
            "kyc_submitted_at" => $professional->getKycSubmittedAt()?->format("Y-m-d H:i:s"),
            "kyc_approved_at" => $professional->getKycApprovedAt()?->format("Y-m-d H:i:s"),
            "kyc_rejected_at" => $professional->getKycRejectedAt()?->format("Y-m-d H:i:s"),
            "kyc_expires_at" => $professional->getKycExpiresAt()?->format("Y-m-d H:i:s"),
            "kyc_document_url" => $professional->getKycDocumentUrl(),
            "kyc_selfie_url" => $professional->getKycSelfieUrl(),
            "kyc_ocr_data" => $professional->getKycOcrData() ? json_encode($professional->getKycOcrData()) : null,
            "kyc_liveness_data" => $professional->getKycLivenessData() ? json_encode($professional->getKycLivenessData()) : null,
            "kyc_facematch_data" => $professional->getKycFaceMatchData() ? json_encode($professional->getKycFaceMatchData()) : null,
            "kyc_rejection_reason" => $professional->getKycRejectionReason(),
            "kyc_admin_notes" => $professional->getKycAdminNotes(),
            "kyc_approved_by" => $professional->getKycApprovedBy(),
            "kyc_rejected_by" => $professional->getKycRejectedBy(),
            "kyc_document_type" => $professional->getKycDocumentType(),
            "kyc_retry_count" => $professional->getKycRetryCount(),
            "kyc_last_retry_at" => $professional->getKycLastRetryAt()?->format("Y-m-d H:i:s"),
        ];

        if ($professional->getId()) {
            $this->wpdb->update($this->table, $data, ['id' => $professional->getId()]);
        } else {
            $data['created_at'] = current_time('mysql');
            $this->wpdb->insert($this->table, $data);
        }
    }

    public function updateScore(int $professionalId, float $newScore, string $reason, array $details = []): void
    {
        $oldScore = $this->wpdb->get_var($this->wpdb->prepare("SELECT score FROM {$this->table} WHERE id = %d", $professionalId));
        if ($oldScore === null) return;

        $this->wpdb->update($this->table, ['score' => $newScore, 'updated_at' => current_time('mysql')], ['id' => $professionalId]);

        $this->wpdb->insert($this->scoreHistoryTable, [
            'professional_id' => $professionalId,
            'old_score' => $oldScore,
            'new_score' => $newScore,
            'score_change' => $newScore - $oldScore,
            'change_reason' => $reason,
            'related_contract_id' => $details['contract_id'] ?? null,
            'related_execution_id' => $details['execution_id'] ?? null,
            'change_details' => !empty($details) ? wp_json_encode($details) : null,
            'changed_at' => current_time('mysql'),
            'changed_by' => $details['changed_by'] ?? 'system',
        ]);
    }

    public function incrementCompletedServices(int $professionalId): void
    {
        $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table} SET completed_services = completed_services + 1, updated_at = NOW() WHERE id = %d", $professionalId));
    }

    public function incrementCancelledServices(int $professionalId): void
    {
        $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table} SET cancelled_services = cancelled_services + 1, updated_at = NOW() WHERE id = %d", $professionalId));
    }

    public function incrementNoShows(int $professionalId): void
    {
        $this->wpdb->query($this->wpdb->prepare("UPDATE {$this->table} SET no_show_count = no_show_count + 1, updated_at = NOW() WHERE id = %d", $professionalId));
    }

    public function updateLastActivity(int $professionalId): void
    {
        $this->wpdb->update($this->table, ['last_activity_at' => current_time('mysql'), 'updated_at' => current_time('mysql')], ['id' => $professionalId]);
    }

    public function delete(int $professionalId): void
    {
        $this->wpdb->update($this->table, ['is_active' => 0, 'updated_at' => current_time('mysql')], ['id' => $professionalId]);
    }

    public function countActive(): int
    {
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE is_active = 1");
    }

    public function countVerified(): int
    {
        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE is_active = 1 AND is_verified = 1");
    }

    public function getStatistics(): array
    {
        $stats = $this->wpdb->get_row("SELECT COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active, AVG(score) as avg_score FROM {$this->table}", ARRAY_A);
        return ['total' => (int) $stats['total'], 'active' => (int) $stats['active'], 'avg_score' => round((float) $stats['avg_score'], 2)];
    }

    public function getAllocationHistory(int $professionalId, int $limit = 50, int $offset = 0): array
    {
        $table = $this->wpdb->prefix . 'limpvix_professional_allocations_history';
        return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$table} WHERE professional_id = %d ORDER BY allocated_at DESC LIMIT %d OFFSET %d", $professionalId, $limit, $offset), ARRAY_A);
    }

    public function getScoreHistory(int $professionalId, int $limit = 50, int $offset = 0): array
    {
        return $this->wpdb->get_results($this->wpdb->prepare("SELECT * FROM {$this->scoreHistoryTable} WHERE professional_id = %d ORDER BY changed_at DESC LIMIT %d OFFSET %d", $professionalId, $limit, $offset), ARRAY_A);
    }
}
