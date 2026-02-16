<?php
/**
 * TableNames - Helper centralizado para nomes de tabelas
 *
 * RESPONSABILIDADE:
 * - Retornar nomes completos de tabelas (com prefixo)
 * - Centralizar lógica de nomeação
 * - Facilitar testes e multisite
 *
 * PRINCÍPIO:
 * - DRY: Don't Repeat Yourself
 * - Single Source of Truth para nomes de tabelas
 * - Evita string hardcoded espalhada no código
 * - Facilita refatoração futura
 *
 * USO:
 * ```php
 * $tableName = TableNames::orders(); // wp_limpvix_orders
 * ```
 *
 * @package LimpVix\Core
 */

namespace LimpVix\Core;

defined('ABSPATH') || exit;

class TableNames
{
    /**
     * Retorna nome completo da tabela de Orders
     *
     * @return string Nome completo com prefixo (ex: wp_limpvix_orders)
     */
    public static function orders(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'limpvix_orders';
    }

    /**
     * Retorna nome completo da tabela de Audit Log (futuro)
     *
     * @return string Nome completo com prefixo (ex: wp_limpvix_audit_log)
     */
    public static function auditLog(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'limpvix_audit_log';
    }

    /**
     * Retorna prefixo das tabelas LimpVix
     *
     * @return string Prefixo completo (ex: wp_limpvix_)
     */
    public static function prefix(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'limpvix_';
    }
}
