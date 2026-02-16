<?php
/**
 * WpMessageTemplateRepository - Infrastructure
 *
 * RESPONSABILIDADE:
 * - Persistir MessageTemplate em wp_limpvix_message_templates
 * - Hidratar MessageTemplate do banco
 * - Buscar templates por ID e versão
 *
 * @package LimpVix\Infrastructure\Persistence
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Communication\MessageTemplate;

defined('ABSPATH') || exit;

class WpMessageTemplateRepository
{
    private $wpdb;
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_message_templates';
    }

    /**
     * Buscar template ativo mais recente por ID
     *
     * @param string $templateId ID do template (ex: T-BOOKING-01)
     * @return MessageTemplate|null
     */
    public function findLatestActive(string $templateId): ?MessageTemplate
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE template_id = %s
             AND is_active = 1
             ORDER BY version DESC
             LIMIT 1",
            $templateId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Buscar template específico por ID e versão
     *
     * @param string $templateId ID do template
     * @param string $version Versão (ex: 1.0)
     * @return MessageTemplate|null
     */
    public function findByIdAndVersion(string $templateId, string $version): ?MessageTemplate
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE template_id = %s
             AND version = %s
             LIMIT 1",
            $templateId,
            $version
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Listar todos templates de um ID (todas versões)
     *
     * @param string $templateId ID do template
     * @return MessageTemplate[]
     */
    public function findAllVersions(string $templateId): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE template_id = %s
             ORDER BY version DESC",
            $templateId
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * Salvar novo template
     *
     * @param MessageTemplate $template Template para salvar
     * @return bool
     */
    public function save(MessageTemplate $template): bool
    {
        $data = $this->dehydrate($template);

        $result = $this->wpdb->insert(
            $this->table,
            $data,
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        if ($result === false) {
            error_log('[LimpVix] Failed to save message template: ' . $this->wpdb->last_error);
            return false;
        }

        return true;
    }

    /**
     * Desativar template
     *
     * @param string $templateId ID do template
     * @param string $version Versão
     * @return bool
     */
    public function deactivate(string $templateId, string $version): bool
    {
        $result = $this->wpdb->update(
            $this->table,
            ['is_active' => 0],
            ['template_id' => $templateId, 'version' => $version],
            ['%d'],
            ['%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Hidratar MessageTemplate do banco
     *
     * @param array $row Linha do banco
     * @return MessageTemplate
     */
    private function hydrate(array $row): MessageTemplate
    {
        return new MessageTemplate(
            $row['template_id'],
            $row['version'],
            $row['content'],
            json_decode($row['required_variables'], true),
            $row['channel'],
            (bool) $row['is_active'],
            new \DateTimeImmutable($row['created_at'])
        );
    }

    /**
     * Desidratar MessageTemplate para banco
     *
     * @param MessageTemplate $template Template
     * @return array
     */
    private function dehydrate(MessageTemplate $template): array
    {
        return [
            'template_id' => $template->getTemplateId(),
            'version' => $template->getVersion(),
            'content' => $template->getContent(),
            'required_variables' => json_encode($template->getRequiredVariables()),
            'channel' => $template->getChannel(),
            'is_active' => $template->isActive() ? 1 : 0,
            'created_at' => $template->getCreatedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
