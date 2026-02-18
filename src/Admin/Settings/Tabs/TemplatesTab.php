<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class TemplatesTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'templates'; }
    public function getLabel(): string { return 'Templates'; }
    public function getIcon(): string { return '&#x1F4DD;'; }

    public function handleSave(): void {}

    public function render(): void
    {
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\MessageTemplatesAdminPage();
            $page->render();
        } else {
            ?>
            <div class="limpvix-card">
                <div class="limpvix-card-body">
                    <div class="notice notice-error">
                        <p><strong>Erro:</strong> Classe MessageTemplatesAdminPage não encontrada.</p>
                    </div>
                </div>
            </div>
            <?php
        }
    }
}
