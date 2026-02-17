<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Verification\ConsentRecord;
use LimpVix\Domain\Verification\ProfessionalVerification;
use LimpVix\Domain\Verification\ProfessionalVerificationRepositoryInterface;

defined('ABSPATH') || exit;

/**
 * WpProfessionalVerificationRepository — Implementação WordPress do pipeline de verificação
 *
 * Persiste o estado das 4 camadas (OTP, KYC, Background, Risk Engine) em:
 * - wp_limpvix_professional_verification (tabela principal)
 * - wp_limpvix_consent_records (registros LGPD — imutáveis)
 *
 * Criadas pela migration 026_create_professional_verification.sql
 */
final class WpProfessionalVerificationRepository implements ProfessionalVerificationRepositoryInterface
{
    private \wpdb $wpdb;
    private string $tableVerification;
    private string $tableConsent;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb              = $wpdb;
        $this->tableVerification = $wpdb->prefix . 'limpvix_professional_verification';
        $this->tableConsent      = $wpdb->prefix . 'limpvix_consent_records';
    }

    // ─── ProfessionalVerification ────────────────────────────────────────────

    public function save(ProfessionalVerification $verification): void
    {
        $data = $verification->toArray();

        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->tableVerification} WHERE id = %s",
                $data['id']
            )
        );

        if ($exists) {
            $this->wpdb->update(
                $this->tableVerification,
                $this->mapToDb($data),
                ['id' => $data['id']],
                $this->dbFormats($data),
                ['%s']
            );
        } else {
            $this->wpdb->insert(
                $this->tableVerification,
                $this->mapToDb($data),
                $this->dbFormats($data)
            );
        }
    }

    public function findByUserId(int $userId): ?ProfessionalVerification
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableVerification} WHERE user_id = %d ORDER BY created_at DESC LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        return $row ? ProfessionalVerification::reconstitute($row) : null;
    }

    public function findById(string $id): ?ProfessionalVerification
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableVerification} WHERE id = %s",
                $id
            ),
            ARRAY_A
        );

        return $row ? ProfessionalVerification::reconstitute($row) : null;
    }

    /**
     * Retorna verificações cujo background check expirou e status é ACTIVE ou ACTIVE_MONITORED
     *
     * @return ProfessionalVerification[]
     */
    public function findExpiredBackgrounds(): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableVerification}
                 WHERE background_expires_at < %s
                   AND final_status IN ('ACTIVE', 'ACTIVE_MONITORED')
                 ORDER BY background_expires_at ASC",
                (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            ),
            ARRAY_A
        );

        return array_map(
            fn(array $row) => ProfessionalVerification::reconstitute($row),
            $rows
        );
    }

    // ─── ConsentRecord (LGPD — imutável, sem UPDATE/DELETE) ──────────────────

    public function saveConsent(ConsentRecord $consent): void
    {
        $data = $consent->toArray();

        // Verificar idempotência: mesmo user + tipo + versão = não duplicar
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id FROM {$this->tableConsent}
                 WHERE user_id = %d AND consent_type = %s AND consent_version = %s",
                $data['user_id'],
                $data['consent_type'],
                $data['consent_version']
            )
        );

        if ($exists) {
            return; // Consentimento desta versão já registrado — idempotente
        }

        $this->wpdb->insert(
            $this->tableConsent,
            [
                'id'              => $data['id'],
                'user_id'         => $data['user_id'],
                'consent_type'    => $data['consent_type'],
                'consent_version' => $data['consent_version'],
                'accepted_at'     => $data['accepted_at'],
                'ip_address'      => $data['ip_address'],
                'hash_snapshot'   => $data['hash_snapshot'],
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function hasValidConsent(int $userId, string $consentType, string $minVersion): bool
    {
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->tableConsent}
                 WHERE user_id = %d
                   AND consent_type = %s
                   AND consent_version >= %s",
                $userId,
                $consentType,
                $minVersion
            )
        );

        return (int) $count > 0;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Mapeia toArray() do aggregate para colunas do banco,
     * excluindo campos calculados que o DB gerencia (updated_at via ON UPDATE)
     */
    private function mapToDb(array $data): array
    {
        return [
            'id'                     => $data['id'],
            'user_id'                => $data['user_id'],
            'otp_verified'           => $data['otp_verified'],
            'kyc_status'             => $data['kyc_status'],
            'kyc_result_json'        => $data['kyc_result_json'],
            'kyc_provider'           => $data['kyc_result_json']
                ? json_decode($data['kyc_result_json'], true)['provider'] ?? null
                : null,
            'kyc_checked_at'         => $data['kyc_result_json']
                ? json_decode($data['kyc_result_json'], true)['checkedAt'] ?? null
                : null,
            'background_status'      => $data['background_status'],
            'background_result_json' => $data['background_result_json'],
            'background_provider'    => $data['background_result_json']
                ? json_decode($data['background_result_json'], true)['provider'] ?? null
                : null,
            'background_checked_at'  => $data['background_result_json']
                ? json_decode($data['background_result_json'], true)['checkedAt'] ?? null
                : null,
            'background_expires_at'  => $data['background_expires_at'],
            'risk_level'             => $data['risk_level'],
            'final_status'           => $data['final_status'],
        ];
    }

    /**
     * Retorna os formatos wpdb (%s/%d) para cada campo mapeado
     */
    private function dbFormats(array $data): array
    {
        return [
            '%s', // id
            '%d', // user_id
            '%d', // otp_verified
            '%s', // kyc_status
            '%s', // kyc_result_json
            '%s', // kyc_provider
            '%s', // kyc_checked_at
            '%s', // background_status
            '%s', // background_result_json
            '%s', // background_provider
            '%s', // background_checked_at
            '%s', // background_expires_at
            '%s', // risk_level
            '%s', // final_status
        ];
    }
}
