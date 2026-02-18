<?php

namespace LimpVix\Infrastructure\Admin\Queries;

defined('ABSPATH') || exit;

/**
 * CQRS Read Model for execution list admin view.
 * Direct SQL queries without hydrating domain aggregates.
 */
class ExecutionListQuery
{
    private \wpdb $wpdb;
    private string $tableExec;
    private string $tableContracts;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableExec = $wpdb->prefix . 'limpvix_contract_executions';
        $this->tableContracts = $wpdb->prefix . 'limpvix_contracts';
    }

    /**
     * @param array{status?:string, date_from?:string, date_to?:string, contract_id?:int} $filters
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'e.status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'e.scheduled_date >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'e.scheduled_date <= %s';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['contract_id'])) {
            $where[] = 'e.contract_id = %d';
            $params[] = (int) $filters['contract_id'];
        }

        $whereClause = implode(' AND ', $where);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT e.*, c.client_user_id, c.professional_user_id AS contract_professional_id,
                       c.service_type, c.status AS contract_status
                FROM {$this->tableExec} e
                LEFT JOIN {$this->tableContracts} c ON c.id = e.contract_id
                WHERE {$whereClause}
                ORDER BY e.scheduled_date DESC, e.id DESC
                LIMIT %d OFFSET %d";

        $params[] = $perPage;
        $params[] = $offset;

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, ...$params);
        }

        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function count(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'e.status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'e.scheduled_date >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'e.scheduled_date <= %s';
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['contract_id'])) {
            $where[] = 'e.contract_id = %d';
            $params[] = (int) $filters['contract_id'];
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) FROM {$this->tableExec} e WHERE {$whereClause}";

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, ...$params);
        }

        return (int) $this->wpdb->get_var($sql);
    }

    public function getStatistics(): array
    {
        $today = current_time('Y-m-d');
        $weekFromNow = date('Y-m-d', strtotime('+7 days'));

        $stats = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN status = 'scheduled' AND scheduled_date >= %s THEN 1 ELSE 0 END) AS upcoming,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN status = 'completed' AND executed_date = %s THEN 1 ELSE 0 END) AS completed_today,
                    SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) AS no_shows,
                    SUM(CASE WHEN status = 'scheduled' AND scheduled_date BETWEEN %s AND %s THEN 1 ELSE 0 END) AS next_7_days,
                    COUNT(*) AS total
                FROM {$this->tableExec}",
                $today, $today, $today, $weekFromNow
            ),
            ARRAY_A
        );

        return [
            'upcoming' => (int) ($stats['upcoming'] ?? 0),
            'in_progress' => (int) ($stats['in_progress'] ?? 0),
            'completed_today' => (int) ($stats['completed_today'] ?? 0),
            'no_shows' => (int) ($stats['no_shows'] ?? 0),
            'next_7_days' => (int) ($stats['next_7_days'] ?? 0),
            'total' => (int) ($stats['total'] ?? 0),
        ];
    }
}
