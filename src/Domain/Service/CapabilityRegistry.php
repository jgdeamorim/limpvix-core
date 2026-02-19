<?php
/**
 * CapabilityRegistry - SSOT para Capabilities
 *
 * RESPONSABILIDADE:
 * - Single Source of Truth para todas as competencias do sistema
 * - Ler capabilities de wp_limpvix_capabilities
 * - Ler junction tables (complexity_capabilities, additional_capabilities)
 * - Cache em memoria (static) para evitar queries repetidas
 *
 * PRINCIPIOS:
 * - Registry Pattern
 * - Lazy loading com cache em memoria
 * - Somente leitura (nao persiste)
 *
 * USO:
 * ```php
 * $allCaps = CapabilityRegistry::all();
 * $cap = CapabilityRegistry::findBySlug('cleaning_basic');
 * $caps = CapabilityRegistry::getForComplexity($complexityId);
 * $caps = CapabilityRegistry::getForAdditional($additionalId);
 * ```
 *
 * @package LimpVix\Domain\Service
 * @since 0.5.0
 */

namespace LimpVix\Domain\Service;

defined('ABSPATH') || exit;

final class CapabilityRegistry
{
    /**
     * @var Capability[]|null Cache of all capabilities (keyed by slug)
     */
    private static $cache = null;

    /**
     * @var array Cache of capabilities per complexity_id
     */
    private static $complexityCache = [];

    /**
     * @var array Cache of capabilities per additional_id
     */
    private static $additionalCache = [];

    /**
     * Get all active capabilities
     *
     * @return Capability[] Keyed by slug
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_capabilities';

        $rows = $wpdb->get_results(
            "SELECT id, slug, display_name, description
             FROM {$table}
             WHERE is_active = 1
             ORDER BY slug ASC",
            ARRAY_A
        );

        self::$cache = [];

        if ($rows) {
            foreach ($rows as $row) {
                $cap = Capability::fromDatabase($row);
                self::$cache[$cap->getSlug()] = $cap;
            }
        }

        return self::$cache;
    }

    /**
     * Find capability by slug
     *
     * @param string $slug
     * @return Capability|null
     */
    public static function findBySlug(string $slug): ?Capability
    {
        $all = self::all();
        return $all[$slug] ?? null;
    }

    /**
     * Find multiple capabilities by slugs
     *
     * @param string[] $slugs
     * @return Capability[]
     */
    public static function findBySlugs(array $slugs): array
    {
        $all = self::all();
        $result = [];

        foreach ($slugs as $slug) {
            if (isset($all[$slug])) {
                $result[] = $all[$slug];
            }
        }

        return $result;
    }

    /**
     * Get capabilities required by a complexity
     *
     * @param int $complexityId
     * @return Capability[]
     */
    public static function getForComplexity(int $complexityId): array
    {
        if (isset(self::$complexityCache[$complexityId])) {
            return self::$complexityCache[$complexityId];
        }

        global $wpdb;
        $junction = $wpdb->prefix . 'limpvix_complexity_capabilities';
        $capTable = $wpdb->prefix . 'limpvix_capabilities';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.slug, c.display_name, c.description
                 FROM {$junction} cc
                 JOIN {$capTable} c ON cc.capability_id = c.id
                 WHERE cc.complexity_id = %d AND c.is_active = 1
                 ORDER BY c.slug ASC",
                $complexityId
            ),
            ARRAY_A
        );

        $capabilities = [];
        if ($rows) {
            foreach ($rows as $row) {
                $capabilities[] = Capability::fromDatabase($row);
            }
        }

        self::$complexityCache[$complexityId] = $capabilities;
        return $capabilities;
    }

    /**
     * Get capabilities required by an additional
     *
     * @param int $additionalId
     * @return Capability[]
     */
    public static function getForAdditional(int $additionalId): array
    {
        if (isset(self::$additionalCache[$additionalId])) {
            return self::$additionalCache[$additionalId];
        }

        global $wpdb;
        $junction = $wpdb->prefix . 'limpvix_additional_capabilities';
        $capTable = $wpdb->prefix . 'limpvix_capabilities';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.slug, c.display_name, c.description
                 FROM {$junction} ac
                 JOIN {$capTable} c ON ac.capability_id = c.id
                 WHERE ac.additional_id = %d AND c.is_active = 1
                 ORDER BY c.slug ASC",
                $additionalId
            ),
            ARRAY_A
        );

        $capabilities = [];
        if ($rows) {
            foreach ($rows as $row) {
                $capabilities[] = Capability::fromDatabase($row);
            }
        }

        self::$additionalCache[$additionalId] = $capabilities;
        return $capabilities;
    }

    /**
     * Compute all required capabilities for a briefing
     *
     * Formula: complexity.capabilities + SUM(additional.capabilities)
     *
     * @param int $complexityId
     * @param int[] $additionalIds
     * @return Capability[] Unique capabilities
     */
    public static function computeRequired(int $complexityId, array $additionalIds = []): array
    {
        $slugs = [];
        $capsBySlug = [];

        // Complexity capabilities
        foreach (self::getForComplexity($complexityId) as $cap) {
            $slugs[$cap->getSlug()] = true;
            $capsBySlug[$cap->getSlug()] = $cap;
        }

        // Additional capabilities
        foreach ($additionalIds as $additionalId) {
            foreach (self::getForAdditional($additionalId) as $cap) {
                $slugs[$cap->getSlug()] = true;
                $capsBySlug[$cap->getSlug()] = $cap;
            }
        }

        return array_values($capsBySlug);
    }

    /**
     * Clear all caches (for testing or after data changes)
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$cache = null;
        self::$complexityCache = [];
        self::$additionalCache = [];
    }
}
