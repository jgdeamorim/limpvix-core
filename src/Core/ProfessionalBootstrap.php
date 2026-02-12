<?php
namespace LimpVix\Core;

use LimpVix\Infrastructure\Admin\Pages\ProfessionalManagementPage;

defined('ABSPATH') || exit;

/**
 * Professional Module Bootstrap
 *
 * Registra páginas administrativas e hooks do módulo Profissionais
 */
class ProfessionalBootstrap
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerAdminPages']);
    }

    public static function registerAdminPages(): void
    {
        // Register Professional Management Page (with KYC tab integrated)
        $professionalPage = new ProfessionalManagementPage();
        $professionalPage->register();
    }
}
