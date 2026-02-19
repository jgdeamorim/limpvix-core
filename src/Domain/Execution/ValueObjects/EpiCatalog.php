<?php
declare(strict_types=1);

/**
 * EpiCatalog - Collection of EPI Requirements
 *
 * Loads from database and provides validation of EPI compliance
 * per service type.
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 * @since P1.5
 */

namespace LimpVix\Domain\Execution\ValueObjects;

defined('ABSPATH') || exit;

final class EpiCatalog
{
    /** @var EpiRequirement[] */
    private array $items;

    /**
     * @param EpiRequirement[] $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Load catalog from database
     */
    public static function fromDatabase(): self
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_epi_catalog';

        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
        if (!$tableExists) {
            return new self();
        }

        $rows = $wpdb->get_results(
            "SELECT slug, name, description, required_for_types, is_mandatory
             FROM {$table}
             WHERE active = 1
             ORDER BY sort_order ASC, name ASC",
            ARRAY_A
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = EpiRequirement::fromArray($row);
        }

        return new self($items);
    }

    /**
     * Get all EPIs required for a specific service type
     *
     * @param string $serviceType e.g. 'pos_obra', 'limpeza_pesada'
     * @return EpiRequirement[]
     */
    public function getRequiredFor(string $serviceType): array
    {
        return array_filter(
            $this->items,
            fn(EpiRequirement $epi) => $epi->isRequiredForServiceType($serviceType)
        );
    }

    /**
     * Validate provided EPIs against requirements for a service type
     *
     * @param string[] $providedEpiSlugs Slugs of EPIs the professional has
     * @param string $serviceType Service type being performed
     * @return string[] Missing EPI slugs
     */
    public function validate(array $providedEpiSlugs, string $serviceType): array
    {
        $required = $this->getRequiredFor($serviceType);
        $missing = [];

        foreach ($required as $epi) {
            if (!in_array($epi->getSlug(), $providedEpiSlugs, true)) {
                $missing[] = $epi->getSlug();
            }
        }

        return $missing;
    }

    /**
     * @return EpiRequirement[]
     */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function toArray(): array
    {
        return array_map(fn(EpiRequirement $epi) => $epi->toArray(), $this->items);
    }
}
