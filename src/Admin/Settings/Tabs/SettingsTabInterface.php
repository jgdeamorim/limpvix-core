<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

interface SettingsTabInterface
{
    public function getSlug(): string;
    public function getLabel(): string;
    public function getIcon(): string;
    public function handleSave(): void;
    public function render(): void;
}
