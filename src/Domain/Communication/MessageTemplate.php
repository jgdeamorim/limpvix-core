<?php
/**
 * MessageTemplate - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar template versionado de mensagem
 * - Garantir imutabilidade após criação
 * - Validar estrutura (ID, versão, conteúdo)
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Versionamento semântico (v1.0, v1.1, v2.0)
 * - Template ID único (T-BOOKING-01, T-REMINDER-24H)
 *
 * @package LimpVix\Domain\Communication
 * @since 0.3.0
 */

namespace LimpVix\Domain\Communication;

defined('ABSPATH') || exit;

class MessageTemplate
{
    private $templateId;
    private $version;
    private $content;
    private $requiredVariables;
    private $channel;
    private $isActive;
    private $createdAt;

    public function __construct(
        string $templateId,
        string $version,
        string $content,
        array $requiredVariables,
        string $channel,
        bool $isActive = true,
        ?\DateTimeImmutable $createdAt = null
    ) {
        if (!preg_match('/^T-[A-Z0-9-]+$/', $templateId)) {
            throw new \InvalidArgumentException(
                "Template ID inválido: {$templateId}. Formato: T-XXXXX"
            );
        }

        if (!preg_match('/^\d+\.\d+$/', $version)) {
            throw new \InvalidArgumentException(
                "Versão inválida: {$version}. Formato: X.Y"
            );
        }

        if (!in_array($channel, ['whatsapp', 'sms', 'email', 'push'], true)) {
            throw new \InvalidArgumentException("Canal inválido: {$channel}");
        }

        $this->validatePlaceholders($content, $requiredVariables);

        $this->templateId = $templateId;
        $this->version = $version;
        $this->content = $content;
        $this->requiredVariables = $requiredVariables;
        $this->channel = $channel;
        $this->isActive = $isActive;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    private function validatePlaceholders(string $content, array $requiredVariables): void
    {
        preg_match_all('/\{\{([a-z_]+)\}\}/', $content, $matches);
        $placeholdersInContent = $matches[1] ?? [];

        foreach ($requiredVariables as $variable) {
            if (!in_array($variable, $placeholdersInContent, true)) {
                throw new \InvalidArgumentException(
                    "Variável requerida '{$variable}' não encontrada no template"
                );
            }
        }
    }

    public function render(array $variables): string
    {
        foreach ($this->requiredVariables as $requiredVar) {
            if (!isset($variables[$requiredVar])) {
                throw new \InvalidArgumentException(
                    "Variável '{$requiredVar}' não fornecida para {$this->templateId}"
                );
            }
        }

        $rendered = $this->content;
        foreach ($variables as $key => $value) {
            $rendered = str_replace("{{" . $key . "}}", (string) $value, $rendered);
        }

        return $rendered;
    }

    public function getFullIdentifier(): string
    {
        return "{$this->templateId} v{$this->version}";
    }

    public function getTemplateId(): string { return $this->templateId; }
    public function getVersion(): string { return $this->version; }
    public function getContent(): string { return $this->content; }
    public function getRequiredVariables(): array { return $this->requiredVariables; }
    public function getChannel(): string { return $this->channel; }
    public function isActive(): bool { return $this->isActive; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function equals(MessageTemplate $other): bool
    {
        return $this->templateId === $other->templateId
            && $this->version === $other->version;
    }

    public function toArray(): array
    {
        return [
            'template_id' => $this->templateId,
            'version' => $this->version,
            'content' => $this->content,
            'required_variables' => $this->requiredVariables,
            'channel' => $this->channel,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
