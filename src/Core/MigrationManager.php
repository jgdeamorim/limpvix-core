<?php

declare(strict_types=1);

namespace LimpVix\Core;

/**
 * Migration Manager
 *
 * Gerencia execução de migrations do banco de dados:
 * - Executa migrations automaticamente na ativação do plugin
 * - Mantém controle de versão (não roda migration já executada)
 * - Suporta rollback manual
 */
final class MigrationManager
{
    private const OPTION_NAME = 'limpvix_db_version';
    private const MIGRATIONS_DIR = 'database-migrations';

    private \wpdb $wpdb;
    private string $pluginDir;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->pluginDir = dirname(dirname(__DIR__)); // plugin root
    }

    /**
     * Executa todas as migrations pendentes
     *
     * @return array Resultado com success e mensagens
     */
    public function runPendingMigrations(): array
    {
        $currentVersion = $this->getCurrentVersion();
        $migrations = $this->getAvailableMigrations();
        $executed = [];
        $errors = [];

        foreach ($migrations as $migration) {
            // Pular se já foi executada
            if ($migration['version'] <= $currentVersion) {
                continue;
            }

            $result = $this->executeMigration($migration);

            if ($result['success']) {
                $executed[] = $migration['file'];
                $this->updateVersion($migration['version']);
            } else {
                $errors[] = [
                    'file' => $migration['file'],
                    'error' => $result['error'],
                ];

                // Parar na primeira falha
                break;
            }
        }

        return [
            'success' => empty($errors),
            'executed' => $executed,
            'errors' => $errors,
            'current_version' => $this->getCurrentVersion(),
        ];
    }

    /**
     * Executa uma migration específica
     *
     * @param array $migration
     * @return array
     */
    private function executeMigration(array $migration): array
    {
        $filePath = $migration['path'];

        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error' => 'Migration file not found: ' . $filePath,
            ];
        }

        // Ler SQL
        $sql = file_get_contents($filePath);

        if ($sql === false) {
            return [
                'success' => false,
                'error' => 'Failed to read migration file',
            ];
        }

        // Aplicar correções automáticas conhecidas
        $sql = $this->applySqlFixes($sql);

        // Dividir em statements individuais
        $statements = $this->splitSqlStatements($sql);

        // Executar cada statement
        foreach ($statements as $statement) {
            $statement = trim($statement);

            if (empty($statement)) {
                continue;
            }

            // Executar
            $result = $this->wpdb->query($statement);

            if ($result === false) {
                $error = $this->wpdb->last_error;

                // Ignorar erros de idempotência (permite re-executar migrations)
                $idempotentErrors = [
                    'Duplicate column name',
                    'Duplicate entry',
                    'Duplicate key name',
                    'Duplicate foreign key constraint',
                    'Table .* already exists',
                    'Can\'t DROP .* check that column/key exists',
                ];

                $isIdempotent = false;
                foreach ($idempotentErrors as $pattern) {
                    if (preg_match('/' . $pattern . '/i', $error)) {
                        $isIdempotent = true;
                        break;
                    }
                }

                // Se é erro de idempotência, continuar silenciosamente
                if ($isIdempotent) {
                    continue;
                }

                // Erro real - parar execução
                return [
                    'success' => false,
                    'error' => $error,
                    'statement' => substr($statement, 0, 200) . '...',
                ];
            }
        }

        return ['success' => true];
    }

    /**
     * Aplica correções automáticas conhecidas no SQL
     *
     * @param string $sql
     * @return string
     */
    private function applySqlFixes(string $sql): string
    {
        // Fix 1: Trocar VARCHAR(36) por CHAR(36) em colunas UUID
        $sql = str_replace('VARCHAR(36)', 'CHAR(36)', $sql);

        // Fix 2: Adicionar collation correta em CHAR(36)
        $sql = preg_replace(
            '/CHAR\(36\)(?! COLLATE)/',
            'CHAR(36) COLLATE utf8mb4_unicode_520_ci',
            $sql
        );

        // Fix 3: Trocar professional_id BIGINT UNSIGNED por INT
        $sql = preg_replace(
            '/professional_id BIGINT UNSIGNED/',
            'professional_id INT',
            $sql
        );

        // Fix 4: Remover comandos DELIMITER e ajustar triggers
        // Trocar END// por END; (quando há DELIMITER //)
        $sql = str_replace('END//', 'END;', $sql);
        // Trocar END$$ por END; (quando há DELIMITER $$)
        $sql = str_replace('END$$', 'END;', $sql);
        // Remover linhas DELIMITER
        $sql = preg_replace('/^DELIMITER\s+.*/m', '', $sql);

        // Fix 5: Remover comandos USE database; (wpdb já está conectado)
        $sql = preg_replace('/^USE\s+\w+\s*;/m', '', $sql);

        return $sql;
    }

    /**
     * Divide SQL em statements individuais
     *
     * @param string $sql
     * @return array
     */
    private function splitSqlStatements(string $sql): array
    {
        // Remover comentários
        $sql = preg_replace('/--.*$/m', '', $sql);
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // Detectar e processar triggers (que usam múltiplos ;)
        if (preg_match_all('/CREATE\s+TRIGGER\s+.*?END;/is', $sql, $matches)) {
            $statements = [];

            // Primeiro, remover os triggers do SQL
            $sqlWithoutTriggers = preg_replace('/CREATE\s+TRIGGER\s+.*?END;/is', '', $sql);

            // Dividir o SQL sem triggers
            $regularStatements = explode(';', $sqlWithoutTriggers);
            $statements = array_merge($statements, array_filter(array_map('trim', $regularStatements)));

            // Adicionar triggers completos
            foreach ($matches[0] as $trigger) {
                $statements[] = trim($trigger);
            }

            return array_filter($statements);
        }

        // Dividir por ; (SQL normal)
        $statements = explode(';', $sql);

        return array_filter(array_map('trim', $statements));
    }

    /**
     * Busca migrations disponíveis na pasta
     *
     * @return array
     */
    private function getAvailableMigrations(): array
    {
        $migrationsPath = $this->pluginDir . '/' . self::MIGRATIONS_DIR;

        if (!is_dir($migrationsPath)) {
            return [];
        }

        $files = glob($migrationsPath . '/*.sql');

        if (!$files) {
            return [];
        }

        $migrations = [];

        foreach ($files as $file) {
            $filename = basename($file);

            // Extrair versão do nome do arquivo (ex: 001_create_orders.sql)
            if (preg_match('/^(\d+)_/', $filename, $matches)) {
                $version = (int) $matches[1];

                $migrations[] = [
                    'version' => $version,
                    'file' => $filename,
                    'path' => $file,
                ];
            }
        }

        // Ordenar por versão
        usort($migrations, fn($a, $b) => $a['version'] <=> $b['version']);

        return $migrations;
    }

    /**
     * Obtém versão atual do banco
     *
     * @return int
     */
    public function getCurrentVersion(): int
    {
        return (int) get_option(self::OPTION_NAME, 0);
    }

    /**
     * Atualiza versão do banco
     *
     * @param int $version
     */
    private function updateVersion(int $version): void
    {
        update_option(self::OPTION_NAME, $version);
    }

    /**
     * Reseta versão (para forçar re-execução)
     * CUIDADO: Use apenas para desenvolvimento!
     *
     * @param int $version
     */
    public function setVersion(int $version): void
    {
        update_option(self::OPTION_NAME, $version);
    }

    /**
     * Lista todas as migrations com status
     *
     * @return array
     */
    public function listMigrations(): array
    {
        $currentVersion = $this->getCurrentVersion();
        $migrations = $this->getAvailableMigrations();

        return array_map(function ($migration) use ($currentVersion) {
            return [
                'version' => $migration['version'],
                'file' => $migration['file'],
                'executed' => $migration['version'] <= $currentVersion,
            ];
        }, $migrations);
    }

    /**
     * Verifica se todas as tabelas necessárias existem
     *
     * @return array
     */
    public function validateTables(): array
    {
        $requiredTables = [
            'wp_limpvix_orders',
            'wp_limpvix_briefings',
            'wp_limpvix_schedules',
            'wp_limpvix_professional_allocations',
            'wp_limpvix_professional_availability',
            'wp_limpvix_check_ins',
            'wp_limpvix_check_outs',
            'wp_limpvix_scheduling_ledger',
        ];

        $missing = [];

        foreach ($requiredTables as $table) {
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = %s AND table_name = %s",
                    DB_NAME,
                    $table
                )
            );

            if (!$exists) {
                $missing[] = $table;
            }
        }

        return [
            'valid' => empty($missing),
            'missing' => $missing,
        ];
    }
}
