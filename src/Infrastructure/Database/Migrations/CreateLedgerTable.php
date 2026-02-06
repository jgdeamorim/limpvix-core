<?php
/**
 * CreateLedgerTable - Migration para Ledger Financeiro Imutável
 *
 * RESPONSABILIDADE:
 * - Criar tabela wp_limpvix_ledger (append-only)
 * - Índices para auditoria e reconstrução de estado
 * - Garantir integridade referencial
 *
 * PRINCÍPIOS:
 * - Append-only (nunca UPDATE, nunca DELETE)
 * - Auditoria completa
 * - Reconstrução de estado possível
 * - Compliance financeiro
 *
 * ESTRUTURA:
 * - ledger_uuid: PK (UUID v4)
 * - order_uuid: FK para orders
 * - from_status: Estado origem
 * - to_status: Estado destino
 * - reason: Motivo da transição
 * - actor: Quem executou (system, admin, customer)
 * - actor_id: ID do ator (user_id, se aplicável)
 * - created_at: Timestamp imutável
 * - payload_json: Contexto completo (JSON)
 *
 * ÍNDICES:
 * - order_uuid (reconstruir histórico de uma order)
 * - created_at (ordem cronológica)
 * - to_status (queries de auditoria)
 *
 * PASSO 5.2 - Ledger Imutável
 *
 * @package LimpVix\Infrastructure\Database\Migrations
 */

namespace LimpVix\Infrastructure\Database\Migrations;



defined('ABSPATH') || exit;

class CreateLedgerTable
{
    /**
     * Nome da tabela
     */
    private const TABLE_NAME = 'limpvix_ledger';

    /**
     * Executar migration
     *
     * @return void
     */
    public function up(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        // Verificar se tabela já existe
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name) {
            return;
        }

        $sql = "CREATE TABLE {$table_name} (
            ledger_uuid VARCHAR(36) NOT NULL,
            order_uuid VARCHAR(36) NOT NULL,
            from_status VARCHAR(20) NOT NULL,
            to_status VARCHAR(20) NOT NULL,
            reason VARCHAR(100) NOT NULL,
            actor VARCHAR(50) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            payload_json TEXT NULL,
            PRIMARY KEY (ledger_uuid),
            INDEX idx_order_uuid (order_uuid),
            INDEX idx_created_at (created_at),
            INDEX idx_to_status (to_status),
            INDEX idx_composite (order_uuid, created_at)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Verificar se criação foi bem-sucedida
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            throw new \RuntimeException("Falha ao criar tabela {$table_name}");
        }
    }

    /**
     * Reverter migration
     *
     * ⚠️ IMPORTANTE: Esta operação é DESTRUTIVA
     * Deve ser usada apenas em desenvolvimento/teste
     * NUNCA em produção com dados financeiros reais
     *
     * @return void
     */
    public function down(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Proteção: verificar se há registros
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");

        if ($count > 0) {
            throw new \RuntimeException(
                "BLOQUEIO DE SEGURANÇA: Tabela {$table_name} contém {$count} registros. " .
                "Rollback de ledger com dados é PROIBIDO. " .
                "Se realmente necessário, use DROP TABLE manualmente."
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    }

    /**
     * Obter nome da migration
     *
     * @return string
     */
    public function getName(): string
    {
        return 'create_ledger_table';
    }

    /**
     * Obter versão da migration
     *
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }
}
