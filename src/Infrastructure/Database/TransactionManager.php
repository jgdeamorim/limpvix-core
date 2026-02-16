<?php
/**
 * TransactionManager - Database Transaction Abstraction Layer
 *
 * Provides atomic transaction management for WordPress database operations.
 *
 * RESPONSABILIDADES:
 * - Iniciar transações de banco de dados
 * - Commit de transações bem-sucedidas
 * - Rollback de transações com falha
 * - Suporte a nested transactions via SAVEPOINTs
 * - Logging de ciclo de vida de transações (WP_DEBUG)
 *
 * FEATURES:
 * - Nested transaction support (SAVEPOINT/RELEASE/ROLLBACK TO)
 * - Transaction level tracking
 * - Automatic logging quando WP_DEBUG ativo
 * - Exception safety (LogicException se mal usado)
 *
 * USO:
 * ```php
 * $tm = $GLOBALS['limpvix_transaction_manager'];
 *
 * $tm->beginTransaction();
 * try {
 *     // Multiple DB operations
 *     $wpdb->insert(...);
 *     $repository->save(...);
 *
 *     $tm->commit();
 * } catch (\Exception $e) {
 *     $tm->rollback();
 *     throw $e;
 * }
 * ```
 *
 * NESTED TRANSACTIONS:
 * ```php
 * $tm->beginTransaction(); // START TRANSACTION
 * try {
 *     $repo1->save(...);
 *
 *     $tm->beginTransaction(); // SAVEPOINT savepoint_1
 *     try {
 *         $repo2->save(...);
 *         $tm->commit(); // RELEASE SAVEPOINT savepoint_1
 *     } catch (\Exception $e) {
 *         $tm->rollback(); // ROLLBACK TO SAVEPOINT savepoint_1
 *         throw $e;
 *     }
 *
 *     $tm->commit(); // COMMIT
 * } catch (\Exception $e) {
 *     $tm->rollback(); // ROLLBACK
 *     throw $e;
 * }
 * ```
 *
 * @package LimpVix\Infrastructure\Database
 * @since 0.7.0
 * @author Claude Code + LimpVix Development Team
 */

namespace LimpVix\Infrastructure\Database;

defined('ABSPATH') || exit;

final class TransactionManager
{
    private \wpdb $wpdb;
    private int $transactionLevel = 0;

    /**
     * Constructor
     *
     * @param \wpdb $wpdb WordPress database abstraction
     */
    public function __construct(\wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    /**
     * Inicia uma transação de banco de dados
     *
     * Se já existe uma transação ativa, cria um SAVEPOINT para nested transaction.
     *
     * @return void
     * @throws \RuntimeException Se falhar ao iniciar transação
     */
    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            // Primeira transação: START TRANSACTION
            $result = $this->wpdb->query('START TRANSACTION');

            if ($result === false) {
                throw new \RuntimeException('Failed to start transaction: ' . $this->wpdb->last_error);
            }

            $this->log('Transaction started');
        } else {
            // Nested transaction: use SAVEPOINT
            $savepoint = $this->getSavepointName($this->transactionLevel);
            $result = $this->wpdb->query("SAVEPOINT {$savepoint}");

            if ($result === false) {
                throw new \RuntimeException("Failed to create savepoint {$savepoint}: " . $this->wpdb->last_error);
            }

            $this->log("Savepoint created: {$savepoint}");
        }

        $this->transactionLevel++;
    }

    /**
     * Faz commit da transação atual
     *
     * Se é uma nested transaction, faz RELEASE SAVEPOINT.
     * Se é a transação raiz, faz COMMIT.
     *
     * @return void
     * @throws \LogicException Se não há transação ativa
     * @throws \RuntimeException Se falhar ao fazer commit
     */
    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new \LogicException('No active transaction to commit');
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            // Root transaction: COMMIT
            $result = $this->wpdb->query('COMMIT');

            if ($result === false) {
                throw new \RuntimeException('Failed to commit transaction: ' . $this->wpdb->last_error);
            }

            $this->log('Transaction committed');
        } else {
            // Nested transaction: RELEASE SAVEPOINT
            $savepoint = $this->getSavepointName($this->transactionLevel);
            $result = $this->wpdb->query("RELEASE SAVEPOINT {$savepoint}");

            if ($result === false) {
                throw new \RuntimeException("Failed to release savepoint {$savepoint}: " . $this->wpdb->last_error);
            }

            $this->log("Savepoint released: {$savepoint}");
        }
    }

    /**
     * Faz rollback da transação atual
     *
     * Se é uma nested transaction, faz ROLLBACK TO SAVEPOINT.
     * Se é a transação raiz, faz ROLLBACK.
     *
     * @return void
     * @throws \LogicException Se não há transação ativa
     */
    public function rollback(): void
    {
        if ($this->transactionLevel === 0) {
            throw new \LogicException('No active transaction to rollback');
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            // Root transaction: ROLLBACK
            $result = $this->wpdb->query('ROLLBACK');

            if ($result === false) {
                // Log error mas não lança exception (já estamos em error handling)
                $this->log('Failed to rollback transaction: ' . $this->wpdb->last_error, 'error');
            } else {
                $this->log('Transaction rolled back');
            }
        } else {
            // Nested transaction: ROLLBACK TO SAVEPOINT
            $savepoint = $this->getSavepointName($this->transactionLevel);
            $result = $this->wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}");

            if ($result === false) {
                $this->log("Failed to rollback to savepoint {$savepoint}: " . $this->wpdb->last_error, 'error');
            } else {
                $this->log("Rolled back to savepoint: {$savepoint}");
            }
        }
    }

    /**
     * Verifica se há uma transação ativa
     *
     * @return bool True se há transação ativa, false caso contrário
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Retorna o nível de transação atual
     *
     * 0 = nenhuma transação
     * 1 = transação raiz
     * 2+ = nested transactions (savepoints)
     *
     * @return int Nível de transação
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Executa um callable dentro de uma transação
     *
     * Helper method para simplificar uso de transações.
     *
     * @param callable $callback Função a executar dentro da transação
     * @return mixed Retorno do callback
     * @throws \Exception Se o callback lançar exception (após rollback)
     */
    public function transaction(callable $callback)
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Gera nome do savepoint baseado no nível
     *
     * @param int $level Nível da transação
     * @return string Nome do savepoint (ex: sp_limpvix_1)
     */
    private function getSavepointName(int $level): string
    {
        return 'sp_limpvix_' . $level;
    }

    /**
     * Log de operações de transação
     *
     * Apenas loga se WP_DEBUG estiver ativo.
     *
     * @param string $message Mensagem de log
     * @param string $level Nível de log (info, error)
     * @return void
     */
    private function log(string $message, string $level = 'info'): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $prefix = $level === 'error' ? '❌' : '✅';
            error_log(sprintf(
                '[TransactionManager] %s [Level: %d] %s',
                $prefix,
                $this->transactionLevel,
                $message
            ));
        }
    }
}
