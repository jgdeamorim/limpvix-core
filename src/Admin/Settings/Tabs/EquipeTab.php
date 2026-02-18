<?php

namespace LimpVix\Admin\Settings\Tabs;

use LimpVix\Infrastructure\Admin\Pages\LimpVixUsersPage;

defined('ABSPATH') || exit;

class EquipeTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'limpvix-users'; }
    public function getLabel(): string { return 'Equipe'; }
    public function getIcon(): string { return '&#x1F465;'; }

    public function handleSave(): void
    {
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\LimpVixUsersPage')) {
            (new LimpVixUsersPage())->handleActions();
        }
    }

    public function render(): void
    {
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\LimpVixUsersPage')) {
            $page = new LimpVixUsersPage();
            $page->renderTabContent();
        }
    }
}
