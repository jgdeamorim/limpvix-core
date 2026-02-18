<?php
/**
 * CreateOrdersTable - Migration para wp_limpvix_orders
 *
 * RESPONSABILIDADE:
 * - Criar tabela wp_limpvix_orders usando dbDelta
 * - Versionar schema via wp_options
 * - Execução idempotente (N execuções = mesmo resultado)
 *
 * PRINCÍPIOS:
 * - dbDelta exige sintaxe EXATA (espaços, tipos, etc)
 * - NUNCA usar foreign keys (problemas com MyISAM/InnoDB)
 * - Indexes separados de CREATE TABLE
 * - Schema version para evitar re-execuções desnecessárias
 *
 * PASSO 4.1.2 - Persistência Controlada
 *
 * @package LimpVix\Core\Migrations
 */

namespace LimpVix\Core\Migrations;

defined('ABSPATH') || exit;

class CreateOrdersTable
{
    /**
     * Versão do schema desta migration
     *
     * Incrementar este valor quando houver alterações no schema
     *
     * @var string
     */
    private const SCHEMA_VERSION = '1.0.0';

    /**
     * Option name para versão do schema
     *
     * @var string
     */
    private const VERSION_OPTION = 'limpvix_orders_schema_version';

    /**
     * Executar migration
     *
     * Idempotente: pode executar N vezes sem efeito colateral
     *
     * @return bool Sucesso
     */
    public function up(): bool
    {
        // Verificar se migration já foi executada
        $currentVersion = get_option(self::VERSION_OPTION, '0.0.0');

        if (version_compare($currentVersion, self::SCHEMA_VERSION, '>=')) {
            // Já está na versão correta ou superior
            return true;
        }

        global $wpdb;

        // AJUSTE C: Nome da tabela via helper centralizado
        $tableName = \LimpVix\Core\TableNames::orders();

        // Charset e collation do WordPress
        $charsetCollate = $wpdb->get_charset_collate();

        // SQL para dbDelta
        // ⚠️ IMPORTANTE: dbDelta é EXIGENTE com sintaxe
        // - Exatamente 2 espaços após PRIMARY KEY
        // - Espaço antes e depois de parênteses
        // - Tipos em UPPERCASE
        // - Sem vírgula após última coluna
        $sql = "CREATE TABLE $tableName (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid CHAR(36) NOT NULL,
  appointment_id BIGINT(20) UNSIGNED DEFAULT NULL,
  customer_id BIGINT(20) UNSIGNED NOT NULL,
  service_id BIGINT(20) UNSIGNED NOT NULL,
  professional_id BIGINT(20) UNSIGNED DEFAULT NULL,
  status VARCHAR(50) NOT NULL,
  scheduled_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  metadata LONGTEXT DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY appointment_id (appointment_id)
) $charsetCollate;";

        // Require dbDelta
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Executar migration
        dbDelta($sql);

        // Atualizar versão do schema
        update_option(self::VERSION_OPTION, self::SCHEMA_VERSION, false);

        return true;
    }

    /**
     * Reverter migration (drop table)
     *
     * ⚠️ CUIDADO: Operação destrutiva
     *
     * @return bool Sucesso
     */
    public function down(): bool
    {
        $tableName = \LimpVix\Core\TableNames::orders();

        global $wpdb;

        // Drop table
        $wpdb->query("DROP TABLE IF EXISTS $tableName");

        // Remover versão do schema
        delete_option(self::VERSION_OPTION);

        return true;
    }

    /**
     * Verifica se tabela existe
     *
     * @return bool
     */
    public function exists(): bool
    {
        $tableName = \LimpVix\Core\TableNames::orders();

        global $wpdb;

        $result = $wpdb->get_var("SHOW TABLES LIKE '$tableName'");

        return $result === $tableName;
    }

    /**
     * Retorna versão atual do schema
     *
     * @return string
     */
    public function getCurrentVersion(): string
    {
        return get_option(self::VERSION_OPTION, '0.0.0');
    }

    /**
     * Retorna versão alvo do schema
     *
     * @return string
     */
    public function getTargetVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Verifica se precisa executar migration
     *
     * @return bool
     */
    public function needsMigration(): bool
    {
        $currentVersion = $this->getCurrentVersion();
        return version_compare($currentVersion, self::SCHEMA_VERSION, '<');
    }
}
