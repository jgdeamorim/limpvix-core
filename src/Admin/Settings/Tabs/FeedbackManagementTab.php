<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class FeedbackManagementTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'feedback-management'; }
    public function getLabel(): string { return 'Feedback C2'; }
    public function getIcon(): string { return '&#x26A0;'; }

    public function handleSave(): void {}

    public function render(): void
    {
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage();
            $page->renderTabContent();
        }
    }
}
