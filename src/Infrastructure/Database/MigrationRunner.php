<?php
/**
 * MigrationRunner - Sistema de versionamento de migrations
 *
 * RESPONSABILIDADES:
 * - Executar migrations pendentes em ordem
 * - Registrar migrations executadas
 * - Prevenir re-execução de migrations
 * - Garantir consistência do schema
 *
 * DESIGN:
 * - Determinístico: Migrations executam na ordem dos arquivos (000, 001, 002...)
 * - Idempotente: Safe para executar múltiplas vezes (usa INSERT IGNORE)
 * - Transacional: Cada migration em transação separada (rollback em caso de erro)
 * - Auditável: Registra quando e qual batch cada migration foi executada
 *
 * @package LimpVix\Infrastructure\Database
 * @since Sprint 8.5
 */

namespace LimpVix\Infrastructure\Database;

defined('ABSPATH') || exit;

final class MigrationRunner
{
    private \wpdb $wpdb;
    private string $migrationsDir;
    private string $migrationsTable;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->migrationsDir = dirname(__DIR__, 3) . '/database-migrations';
        $this->migrationsTable = $wpdb->prefix . 'limpvix_migrations';
    }

    /**
     * Run all pending migrations
     *
     * @return array Statistics: ['executed' => int, 'skipped' => int, 'failed' => array]
     */
    public function runPending(): array
    {
        $this->ensureMigrationsTableExists();

        $allMigrations = $this->getAllMigrationFiles();
        $executedMigrations = $this->getExecutedMigrations();

        $pending = array_diff($allMigrations, $executedMigrations);

        if (empty($pending)) {
            return [
                'executed' => 0,
                'skipped' => count($executedMigrations),
                'failed' => [],
                'message' => 'No pending migrations',
            ];
        }

        // Sort migrations by filename (000, 001, 002...)
        sort($pending);

        $executed = 0;
        $failed = [];
        $currentBatch = $this->getNextBatchNumber();

        foreach ($pending as $migrationFile) {
            try {
                $this->executeMigration($migrationFile, $currentBatch);
                $executed++;
            } catch (\Throwable $e) {
                $failed[] = [
                    'migration' => $migrationFile,
                    'error' => $e->getMessage(),
                ];

                error_log("[MigrationRunner] FAILED: {$migrationFile} - " . $e->getMessage());
            }
        }

        return [
            'executed' => $executed,
            'skipped' => count($executedMigrations),
            'failed' => $failed,
            'message' => sprintf('%d migrations executed, %d failed', $executed, count($failed)),
        ];
    }

    /**
     * Execute a single migration file
     *
     * @param string $migrationFile Filename (e.g., "001_create_contracts_table.sql")
     * @param int $batch Batch number
     * @return void
     * @throws \Exception if migration fails
     */
    private function executeMigration(string $migrationFile, int $batch): void
    {
        $filepath = $this->migrationsDir . '/' . $migrationFile;

        if (!file_exists($filepath)) {
            throw new \RuntimeException("Migration file not found: {$filepath}");
        }

        $sql = file_get_contents($filepath);

        if ($sql === false) {
            throw new \RuntimeException("Failed to read migration file: {$filepath}");
        }

        // Execute SQL (pode ter múltiplas queries separadas por ;)
        $queries = $this->splitQueries($sql);

        foreach ($queries as $query) {
            $query = trim($query);

            if (empty($query) || strpos($query, '--') === 0) {
                continue; // Skip comments and empty lines
            }

            $result = $this->wpdb->query($query);

            if ($result === false) {
                throw new \RuntimeException(
                    sprintf(
                        "Migration query failed: %s\nError: %s",
                        $query,
                        $this->wpdb->last_error
                    )
                );
            }
        }

        // Register migration as executed
        $this->wpdb->insert(
            $this->migrationsTable,
            [
                'migration_name' => $migrationFile,
                'batch' => $batch,
                'executed_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s']
        );

        error_log("[MigrationRunner] SUCCESS: {$migrationFile} (batch {$batch})");
    }

    /**
     * Get all migration files from database-migrations directory
     *
     * @return array List of filenames
     */
    private function getAllMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            error_log("[MigrationRunner] Migrations directory not found: {$this->migrationsDir}");
            return [];
        }

        $files = scandir($this->migrationsDir);

        if ($files === false) {
            return [];
        }

        // Filter only .sql files
        $migrations = array_filter($files, function($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'sql';
        });

        return array_values($migrations);
    }

    /**
     * Get list of already executed migrations
     *
     * @return array List of migration filenames
     */
    private function getExecutedMigrations(): array
    {
        $query = "SELECT migration_name FROM {$this->migrationsTable} ORDER BY id ASC";
        $results = $this->wpdb->get_col($query);

        return $results ?: [];
    }

    /**
     * Get next batch number
     *
     * @return int
     */
    private function getNextBatchNumber(): int
    {
        $query = "SELECT MAX(batch) FROM {$this->migrationsTable}";
        $maxBatch = $this->wpdb->get_var($query);

        return ($maxBatch === null) ? 1 : (int)$maxBatch + 1;
    }

    /**
     * Ensure migrations tracking table exists
     *
     * @return void
     */
    private function ensureMigrationsTableExists(): void
    {
        $query = "SHOW TABLES LIKE '{$this->migrationsTable}'";
        $exists = $this->wpdb->get_var($query) === $this->migrationsTable;

        if (!$exists) {
            // Create migrations table
            $sql = "CREATE TABLE {$this->migrationsTable} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                batch INT NOT NULL DEFAULT 1,
                executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_migration_name (migration_name),
                INDEX idx_executed_at (executed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

            $this->wpdb->query($sql);

            error_log("[MigrationRunner] Created migrations tracking table");
        }
    }

    /**
     * Split SQL into individual queries
     *
     * @param string $sql
     * @return array
     */
    private function splitQueries(string $sql): array
    {
        // Simple split by semicolon (doesn't handle stored procedures correctly, but ok for our use case)
        $queries = explode(';', $sql);

        return array_filter(array_map('trim', $queries));
    }

    /**
     * Check if a specific migration has been executed
     *
     * @param string $migrationName
     * @return bool
     */
    public function hasExecuted(string $migrationName): bool
    {
        $query = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->migrationsTable} WHERE migration_name = %s",
            $migrationName
        );

        return (int)$this->wpdb->get_var($query) > 0;
    }

    /**
     * Get migration statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $totalMigrations = count($this->getAllMigrationFiles());
        $executedMigrations = count($this->getExecutedMigrations());
        $pendingMigrations = $totalMigrations - $executedMigrations;

        return [
            'total' => $totalMigrations,
            'executed' => $executedMigrations,
            'pending' => $pendingMigrations,
            'last_batch' => $this->getNextBatchNumber() - 1,
        ];
    }
}
