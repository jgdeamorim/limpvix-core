<?php
/**
 * CreateMercadoPagoPayoutsTable - Migration para Payouts Mercado Pago
 *
 * RESPONSABILIDADE:
 * - Criar tabela wp_limpvix_mp_payouts
 * - Garantir idempotência absoluta
 * - Auditoria de todas as execuções
 *
 * PRINCÍPIOS:
 * - Idempotência via PK (payout_id)
 * - Append-only (nunca UPDATE)
 * - Auditoria completa
 *
 * ESTRUTURA:
 * - payout_id: PK (UUID único)
 * - order_uuid: FK para orders
 * - mp_transfer_id: ID da transferência no MP (se sucesso)
 * - amount: Valor transferido
 * - receiver_id: MP User ID do receptor
 * - status: SUCCESS | FAILED
 * - error_code: Código de erro (se FAILED)
 * - error_message: Mensagem de erro (se FAILED)
 * - http_status_code: HTTP status da resposta MP
 * - created_at: Timestamp da execução
 * - raw_response: JSON completo da resposta MP
 *
 * ÍNDICES:
 * - order_uuid (buscar payouts de uma order)
 * - mp_transfer_id (buscar por ID do MP)
 * - status (filtrar sucesso/falha)
 * - created_at (ordem cronológica)
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Infrastructure\Database\Migrations
 */

namespace LimpVix\Infrastructure\Database\Migrations;



defined('ABSPATH') || exit;

class CreateMercadoPagoPayoutsTable
{
    /**
     * Nome da tabela
     */
    private const TABLE_NAME = 'limpvix_mp_payouts';

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
            payout_id VARCHAR(36) NOT NULL,
            order_uuid VARCHAR(36) NOT NULL,
            mp_transfer_id VARCHAR(50) NULL,
            amount DECIMAL(10,2) NOT NULL,
            receiver_id VARCHAR(50) NOT NULL,
            status ENUM('SUCCESS','FAILED') NOT NULL,
            error_code VARCHAR(50) NULL,
            error_message TEXT NULL,
            http_status_code INT NULL,
            created_at DATETIME NOT NULL,
            raw_response LONGTEXT NULL,
            PRIMARY KEY (payout_id),
            INDEX idx_order_uuid (order_uuid),
            INDEX idx_mp_transfer_id (mp_transfer_id),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
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
     * ⚠️ CRÍTICO: Esta operação é DESTRUTIVA
     * Payouts são registros financeiros
     * NUNCA executar em produção
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
                "BLOQUEIO DE SEGURANÇA: Tabela {$table_name} contém {$count} registros financeiros. " .
                "Rollback de payouts é PROIBIDO. " .
                "Se realmente necessário, use DROP TABLE manualmente com autorização."
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
        return 'create_mercadopago_payouts_table';
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
