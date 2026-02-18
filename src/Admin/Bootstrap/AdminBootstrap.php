<?php
/**
 * AdminBootstrap - Inicializacao do Modulo Admin
 *
 * Fase 2: Refactored from 7,122 lines to ~500 lines.
 * Settings tabs extracted to LimpVix\Admin\Settings\Tabs\* classes.
 */

namespace LimpVix\Admin\Bootstrap;

use LimpVix\Admin\Capabilities\FinanceCapabilities;
use LimpVix\Admin\Controllers\OrdersListController;
use LimpVix\Admin\Controllers\OrderDetailController;
use LimpVix\Admin\Controllers\AdminActionsController;
use LimpVix\Admin\Controllers\SyncValidatorController;
use LimpVix\Admin\Settings\MercadoPagoSettings;
use LimpVix\Admin\Settings\MercadoPagoDetector;
use LimpVix\Admin\Settings\GoogleBusinessSettings;
use LimpVix\Admin\Settings\NVoipSettings;
use LimpVix\Admin\Settings\FirebaseSettings;
use LimpVix\Admin\Settings\EfiBankSettings;
use LimpVix\Infrastructure\Admin\Pages\PayoutsPage;
use LimpVix\Infrastructure\Admin\Pages\MessageTemplatesAdminPage;
use LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage;
use LimpVix\Infrastructure\Admin\Pages\PackageManagementPage;
use LimpVix\Infrastructure\Admin\Pages\ServiceCatalogPage;
use LimpVix\Infrastructure\Admin\Pages\ContractManagementPage;
use LimpVix\Infrastructure\Admin\Pages\CustomersManagementPage;
use LimpVix\Infrastructure\Admin\Pages\ProfessionalManagementPage;
use LimpVix\Infrastructure\Admin\Pages\ScheduleManagementPage;
use LimpVix\Infrastructure\Admin\Pages\LimpVixUsersPage;
use LimpVix\Admin\Settings\Tabs\SettingsTabInterface;
use LimpVix\Admin\Settings\Tabs\GeralTab;
use LimpVix\Admin\Settings\Tabs\ConexoesTab;
use LimpVix\Admin\Settings\Tabs\ComunicacaoTab;
use LimpVix\Admin\Settings\Tabs\BriefingTab;
use LimpVix\Admin\Settings\Tabs\ProfissionaisTab;
use LimpVix\Admin\Settings\Tabs\TemplatesTab;
use LimpVix\Admin\Settings\Tabs\FluxosTab;
use LimpVix\Admin\Settings\Tabs\PagamentosTab;
use LimpVix\Admin\Settings\Tabs\CronTab;
use LimpVix\Admin\Settings\Tabs\DependenciasTab;
use LimpVix\Admin\Settings\Tabs\RiskTab;
use LimpVix\Admin\Settings\Tabs\FeedbackManagementTab;
use LimpVix\Admin\Settings\Tabs\EquipeTab;

defined("ABSPATH") || exit;

class AdminBootstrap
{
    private const MENU_SLUG = "limpvix-finance";
    private $capabilities;
    private $controllers = [];

    /** @var SettingsTabInterface[] slug => tab instance */
    private array $settingsTabs = [];

    public function __construct()
    {
        $this->capabilities = new FinanceCapabilities();
        $this->registerSettingsTabs();
    }

    private function registerSettingsTabs(): void
    {
        $tabs = [
            new GeralTab(),
            new ConexoesTab(),
            new ComunicacaoTab(),
            new BriefingTab(),
            new ProfissionaisTab(),
            new TemplatesTab(),
            new FluxosTab(),
            new PagamentosTab(),
            new CronTab(),
            new DependenciasTab(),
            new RiskTab(),
            new FeedbackManagementTab(),
            new EquipeTab(),
        ];

        foreach ($tabs as $tab) {
            $this->settingsTabs[$tab->getSlug()] = $tab;
        }
    }

    public function boot(): void
    {
        add_action("admin_init", [$this, "registerCapabilities"]);
        add_action("admin_menu", [$this, "registerMenu"]);
        add_action("admin_enqueue_scripts", [$this, "registerAssets"]);
        add_action("admin_head", [$this, "renderMenuSeparatorCss"]);
        add_action("admin_post_limpvix_update_flows", [$this, "handleUpdateFlows"]);

        MercadoPagoSettings::registerHooks();
        EfiBankSettings::registerHooks();
        GoogleBusinessSettings::registerHooks();
        NVoipSettings::registerHooks();
        FirebaseSettings::registerHooks();
        \LimpVix\Admin\Settings\TwilioSettings::registerHooks();
        \LimpVix\Admin\Settings\PPIDSettings::register();
        MercadoPagoDetector::registerSyncHooks();

        $this->initializeControllers();

        // Registrar AJAX handlers do AdminActionsController
        $adminActionsController = new AdminActionsController();
        $adminActionsController->registerAjaxHandlers();

        // Registrar AJAX handler para Manual Payouts (GAP C)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Ajax\\ManualPayoutAjaxHandler')) {
            $manualPayoutAjax = new \LimpVix\Infrastructure\Admin\Ajax\ManualPayoutAjaxHandler();
            $manualPayoutAjax->register();
        }

        // Registrar paginas de payouts
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\PayoutsPage')) {
            PayoutsPage::register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage')) {
            MessageTemplatesAdminPage::register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            FeedbackManagementPage::register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\PackageManagementPage')) {
            $packagePage = new PackageManagementPage();
            $packagePage->register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ServiceCatalogPage')) {
            $serviceCatalogPage = new ServiceCatalogPage();
            $serviceCatalogPage->register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ContractManagementPage')) {
            $contractPage = new ContractManagementPage();
            $contractPage->register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\CustomersManagementPage')) {
            $customersPage = new CustomersManagementPage();
            $customersPage->register();
        }
    }

    public function registerCapabilities(): void
    {
        if (get_transient("limpvix_finance_caps_registered")) {
            return;
        }
        $this->capabilities->register();
        set_transient("limpvix_finance_caps_registered", true, WEEK_IN_SECONDS);
    }

    public function registerMenu(): void
    {
        if (!FinanceCapabilities::canView()) {
            return;
        }

        // Menu principal
        add_menu_page(
            "LimpVix",
            "LimpVix",
            "limpvix_finance_view",
            self::MENU_SLUG,
            [$this, "renderDashboardPage"],
            "dashicons-chart-line",
            30
        );

        // ── Dashboard ─────────────────────────────────────────────────────
        add_submenu_page(
            self::MENU_SLUG,
            "Dashboard LimpVix",
            "Dashboard",
            "limpvix_finance_view",
            self::MENU_SLUG,
            [$this, "renderDashboardPage"]
        );

        // ── OPERACIONAL ───────────────────────────────────────────────────
        add_submenu_page(
            self::MENU_SLUG,
            "Gerenciar Briefings",
            "Briefings",
            "manage_options",
            "limpvix-briefings",
            [$this, "renderBriefingsPage"]
        );

        add_submenu_page(
            self::MENU_SLUG,
            "Contratos Recorrentes",
            "Contratos",
            "manage_options",
            "limpvix-contracts",
            function () { (new ContractManagementPage())->render(); }
        );

        add_submenu_page(
            self::MENU_SLUG,
            "Agendamentos",
            "Agendamentos",
            "manage_options",
            "limpvix-schedules",
            function () { (new ScheduleManagementPage())->render(); }
        );

        add_submenu_page(
            self::MENU_SLUG,
            "Execucoes",
            "Execucoes",
            "manage_options",
            "limpvix-executions",
            function () {
                (new \LimpVix\Infrastructure\Admin\Pages\ExecutionManagementPage())->render();
            }
        );

        // ── PESSOAS ───────────────────────────────────────────────────────
        add_submenu_page(
            self::MENU_SLUG,
            "Profissionais",
            "Profissionais",
            "manage_options",
            "limpvix-professionals",
            function () { (new ProfessionalManagementPage())->render(); }
        );

        add_submenu_page(
            self::MENU_SLUG,
            "Clientes",
            "Clientes",
            "manage_options",
            "limpvix-customers",
            function () { (new CustomersManagementPage())->render(); }
        );

        // ── FINANCEIRO ────────────────────────────────────────────────────
        add_submenu_page(
            self::MENU_SLUG,
            "Orders Financeiras",
            "Orders",
            "limpvix_finance_view",
            "limpvix-orders",
            [$this, "renderOrdersPage"]
        );

        add_submenu_page(
            self::MENU_SLUG,
            "Relatorio Financeiro",
            "Relatorio",
            "limpvix_finance_view",
            "limpvix-financial-report",
            [$this, "renderFinancialReportPage"]
        );

        // ── CATALOGO ──────────────────────────────────────────────────────
        add_submenu_page(
            self::MENU_SLUG,
            "Catalogo de Servicos",
            "Servicos",
            "manage_options",
            "limpvix-services",
            function () { (new ServiceCatalogPage())->render(); }
        );

        add_submenu_page(
            self::MENU_SLUG,
            "Gerenciar Pacotes",
            "Pacotes",
            "manage_options",
            "limpvix-packages",
            function () { (new PackageManagementPage())->render(); }
        );

        // ── QUALIDADE ─────────────────────────────────────────────────────
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            add_submenu_page(
                self::MENU_SLUG,
                "Feedback & Qualidade",
                "Feedback",
                "limpvix_view_feedback",
                "limpvix-feedback",
                [$this, 'renderFeedbackManagementPage']
            );
        }

        add_submenu_page(
            self::MENU_SLUG,
            "Revisao de Documentos",
            "Documentos KYC",
            "manage_options",
            "limpvix-document-review",
            [$this, "renderDocumentReviewPage"]
        );

        // ── SISTEMA ───────────────────────────────────────────────────────
        add_submenu_page(
            self::MENU_SLUG,
            "Sync Validator",
            "Sync Validator",
            "limpvix_finance_view",
            "limpvix-sync-validator",
            [$this, "renderSyncValidatorPage"]
        );

        add_submenu_page(
            self::MENU_SLUG,
            "Configuracoes LimpVix",
            "Configuracoes",
            "limpvix_finance_manage",
            "limpvix-settings",
            [$this, "renderSettingsPage"]
        );

        // ── Hidden pages (accessed via URL) ───────────────────────────────
        add_submenu_page(
            null,
            "Detalhes da Order",
            "Detalhes da Order",
            "limpvix_finance_view",
            "limpvix-order-detail",
            [$this, "renderOrderDetailPage"]
        );

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\LimpVixUsersPage')) {
            add_submenu_page(
                null,
                "Equipe LimpVix",
                "Equipe LimpVix",
                "limpvix_manage_users",
                "limpvix-users",
                [$this, 'renderUsersPage']
            );
        }
    }

    public function registerAssets(string $hook): void
    {
        if (strpos($hook, 'limpvix') === false) {
            return;
        }

        wp_enqueue_style(
            "limpvix-admin-modern",
            plugins_url("assets/css/limpvix-admin-modern.css", dirname(dirname(__DIR__))),
            [],
            "1.1.0"
        );

        wp_enqueue_style(
            "limpvix-admin",
            plugins_url("assets/css/limpvix-admin.css", dirname(dirname(__DIR__))),
            [],
            "1.0.0"
        );

        wp_enqueue_script(
            "limpvix-templates",
            plugins_url("assets/js/message-templates.js", dirname(dirname(__DIR__))),
            ["jquery"],
            "1.0.0",
            true
        );

        wp_localize_script("limpvix-templates", "limpvixAdmin", [
            "ajaxUrl" => admin_url("admin-ajax.php"),
            "nonce" => wp_create_nonce("limpvix_admin_actions"),
            "capabilities" => [
                "canView" => FinanceCapabilities::canView(),
                "canManage" => FinanceCapabilities::canManage(),
                "canPayout" => FinanceCapabilities::canPayout()
            ]
        ]);
    }

    // ─── Settings Page (Tab Registry) ─────────────────────────────────────────

    public function renderSettingsPage(): void
    {
        if (!FinanceCapabilities::canManage()) {
            wp_die("Acesso negado");
        }

        $activeTabSlug = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'geral';
        $activeTab = $this->settingsTabs[$activeTabSlug] ?? $this->settingsTabs['geral'];

        // Handle save before HTML output (allows redirects)
        $activeTab->handleSave();

        ?>
        <div class="wrap limpvix-admin">
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-admin-settings"></span>
                        Configuracoes LimpVix
                    </h1>
                    <p class="limpvix-page-subtitle">Gerenciar configuracoes gerais do sistema</p>
                </div>
            </div>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Configuracoes salvas com sucesso!</p>
                </div>
            <?php endif; ?>

            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <?php foreach ($this->settingsTabs as $tab): ?>
                    <?php
                    if ($tab->getSlug() === 'feedback-management' &&
                        !current_user_can('limpvix_view_feedback') && !current_user_can('manage_options')) {
                        continue;
                    }
                    if ($tab->getSlug() === 'limpvix-users' &&
                        !current_user_can('limpvix_manage_users') && !current_user_can('manage_options')) {
                        continue;
                    }
                    ?>
                    <a href="?page=limpvix-settings&tab=<?php echo esc_attr($tab->getSlug()); ?>"
                       class="nav-tab <?php echo $activeTabSlug === $tab->getSlug() ? 'nav-tab-active' : ''; ?>">
                        <?php echo $tab->getIcon(); ?> <?php echo esc_html($tab->getLabel()); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php $activeTab->render(); ?>
        </div>
        <?php
    }

    // ─── Flows admin_post handler (delegates to FluxosTab) ────────────────────

    public function handleUpdateFlows(): void
    {
        $fluxosTab = new FluxosTab();
        $fluxosTab->handleUpdateFlows();
    }

    // ─── Standalone Page Renderers ────────────────────────────────────────────

    public function renderDashboardPage(): void
    {
        $controller = new \LimpVix\Admin\Controllers\DashboardController();
        $controller->render();
    }

    public function renderOrdersPage(): void
    {
        $controller = new OrdersListController();
        $controller->render();
    }

    public function renderOrderDetailPage(): void
    {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }

        $orderUuid = isset($_GET['uuid']) ? sanitize_text_field($_GET['uuid']) : '';

        if (empty($orderUuid)) {
            wp_die('UUID da order nao fornecido', 'UUID nao fornecido', ['back_link' => true]);
        }

        $controller = new OrderDetailController();
        $controller->render($orderUuid);
    }

    public function renderSyncValidatorPage(): void
    {
        $controller = new SyncValidatorController();
        $controller->render();
    }

    public function renderFinancialReportPage(): void
    {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }

        $controller = new \LimpVix\Admin\Controllers\FinancialReportController();
        $controller->render();
    }

    public function renderBriefingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\BriefingManagementPage')) {
            $briefingRepository = new \LimpVix\Infrastructure\Persistence\WpBriefingRepository();
            $page = new \LimpVix\Infrastructure\Admin\Pages\BriefingManagementPage($briefingRepository);
            $page->render();
        }
    }

    public function renderFeedbackManagementPage(): void
    {
        if (!current_user_can('limpvix_view_feedback') && !current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage();
            $page->render();
        }
    }

    public function renderUsersPage(): void
    {
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=limpvix-users'));
        exit;
    }

    public function renderDocumentReviewPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\DocumentReviewPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\DocumentReviewPage();
            $page->render();
        } else {
            echo '<div class="wrap"><h1>Erro</h1><p>Classe DocumentReviewPage nao encontrada</p></div>';
        }
    }

    // ─── Deprecated Redirects (backward compat) ──────────────────────────────

    /** @deprecated Payouts movido para limpvix-professionals&tab=payouts */
    public function renderPayoutsPage(): void
    {
        wp_redirect(admin_url('admin.php?page=limpvix-professionals&tab=payouts'));
        exit;
    }

    /** @deprecated Movido para aba Comunicacao em Configuracoes */
    public function renderCommunicationCenterPage(): void
    {
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=comunicacao'));
        exit;
    }

    /** @deprecated Movido para aba Fluxos em Configuracoes */
    public function renderMessageFlowsPage(): void
    {
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=fluxos'));
        exit;
    }

    /** @deprecated Movido para aba Templates em Configuracoes */
    public function renderMessageTemplatesPage(): void
    {
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=templates'));
        exit;
    }

    // ─── Internal ─────────────────────────────────────────────────────────────

    private function initializeControllers(): void
    {
        $this->controllers["orders_list"] = null;
        $this->controllers["order_detail"] = null;
        $this->controllers["admin_actions"] = null;
    }

    public function getController(string $name)
    {
        if (!isset($this->controllers[$name])) {
            return null;
        }

        if ($this->controllers[$name] === null) {
            $this->controllers[$name] = $this->instantiateController($name);
        }

        return $this->controllers[$name];
    }

    private function instantiateController(string $name)
    {
        switch ($name) {
            case "orders_list":
                return new OrdersListController();
            case "order_detail":
                return new OrderDetailController();
            case "admin_actions":
                return new AdminActionsController();
            default:
                return null;
        }
    }

    public function renderMenuSeparatorCss(): void
    {
        ?>
        <style>
            /* LimpVix menu group separators */
            #adminmenu .wp-submenu a[href*="page=limpvix-briefings"],
            #adminmenu .wp-submenu a[href*="page=limpvix-professionals"],
            #adminmenu .wp-submenu a[href*="page=limpvix-orders"],
            #adminmenu .wp-submenu a[href*="page=limpvix-services"],
            #adminmenu .wp-submenu a[href*="page=limpvix-feedback"],
            #adminmenu .wp-submenu a[href*="page=limpvix-sync-validator"] {
                border-top: 1px solid rgba(255,255,255,0.08) !important;
                margin-top: 4px !important;
                padding-top: 8px !important;
            }
        </style>
        <?php
    }

    public function deactivate(): void
    {
        $this->capabilities->unregister();
        delete_transient("limpvix_finance_caps_registered");
    }
}
