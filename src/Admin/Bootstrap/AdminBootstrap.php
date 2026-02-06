<?php
/**
 * AdminBootstrap - Inicialização do Módulo Admin
 * VERSÃO CORRIGIDA - SEM DUPLICAÇÃO DE MENUS
 */

namespace LimpVix\Admin\Bootstrap;

use LimpVix\Admin\Capabilities\FinanceCapabilities;
use LimpVix\Admin\Controllers\OrdersListController;
use LimpVix\Admin\Controllers\OrderDetailController;
use LimpVix\Admin\Controllers\AdminActionsController;
use LimpVix\Admin\Settings\MercadoPagoSettings;
use LimpVix\Admin\Settings\MercadoPagoDetector;
use LimpVix\Admin\Settings\GoogleBusinessSettings;
use LimpVix\Admin\Settings\TestVendorsManager;
use LimpVix\Infrastructure\Admin\Pages\PayoutsPage;
use LimpVix\Infrastructure\Admin\Pages\CommunicationCenterPage;
use LimpVix\Infrastructure\Admin\Pages\MessageFlowsAdminPage;
use LimpVix\Infrastructure\Admin\Pages\MessageTemplatesAdminPage;
use LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage;

defined("ABSPATH") || exit;

class AdminBootstrap
{
    private const MENU_SLUG = "limpvix-finance";
    private $capabilities;
    private $controllers = [];

    public function __construct()
    {
        $this->capabilities = new FinanceCapabilities();
    }

    public function boot(): void
    {
        add_action("admin_init", [$this, "registerCapabilities"]);
        add_action("admin_menu", [$this, "registerMenu"]);
        add_action("admin_enqueue_scripts", [$this, "registerAssets"]);

        MercadoPagoSettings::registerHooks();
        GoogleBusinessSettings::registerHooks();
        // TestVendorsManager::registerHooks(); // DESABILITADO;
        MercadoPagoDetector::registerSyncHooks();

        $this->initializeControllers();

        // Registrar páginas de payouts
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\PayoutsPage')) {
            PayoutsPage::register();
        }

        // Registrar páginas de comunicação (BLOCO E)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageFlowsAdminPage')) {
            MessageFlowsAdminPage::register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage')) {
            MessageTemplatesAdminPage::register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            FeedbackManagementPage::register();
        }

        // REMOVIDO: registerCommunicationPages() que causava duplicação
        // Os menus de comunicação são adicionados diretamente no registerMenu()
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

        // Submenu: Dashboard
        add_submenu_page(
            self::MENU_SLUG,
            "Dashboard LimpVix",
            "Dashboard",
            "limpvix_finance_view",
            self::MENU_SLUG,
            [$this, "renderDashboardPage"]
        );

        // Submenu: Orders
        add_submenu_page(
            self::MENU_SLUG,
            "Orders Financeiras",
            "Orders",
            "limpvix_finance_view",
            "limpvix-orders",
            [$this, "renderOrdersPage"]
        );

        // Submenu: Payouts
        add_submenu_page(
            self::MENU_SLUG,
            "Histórico de Payouts",
            "Payouts",
            "limpvix_finance_view",
            "limpvix-payouts",
            [$this, "renderPayoutsPage"]
        );

        // Submenu: Central de Comunicação (Hub)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\CommunicationCenterPage')) {
            add_submenu_page(
                self::MENU_SLUG,
                "Central de Comunicação",
                "Comunicação",
                "manage_options",
                "limpvix-communication-center",
                [$this, 'renderCommunicationCenterPage']
            );
        }

        // Submenu: Gerenciar Fluxos
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageFlowsAdminPage')) {
            add_submenu_page(
                self::MENU_SLUG,
                "Gerenciar Fluxos Automáticos",
                "Fluxos",
                "manage_options",
                "limpvix-message-flows",
                [$this, 'renderMessageFlowsPage']
            );
        }

        // Submenu: Templates (página 3 - a ser implementada)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage')) {
            add_submenu_page(
                self::MENU_SLUG,
                "Templates de Mensagens",
                "Templates",
                "manage_options",
                "limpvix-templates",
                [$this, 'renderMessageTemplatesPage']
            );
        }

        // Submenu: Feedback Negativo (página 4 - a ser implementada)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            add_submenu_page(
                self::MENU_SLUG,
                "Feedback Negativo (C2)",
                "Feedback C2",
                "manage_options",
                "limpvix-feedback-management",
                [$this, 'renderFeedbackManagementPage']
            );
        }

        // Submenu: Configurações
        add_submenu_page(
            self::MENU_SLUG,
            "Configurações LimpVix",
            "Configurações",
            "limpvix_finance_manage",
            "limpvix-settings",
            [$this, "renderSettingsPage"]
        );
    }

    public function registerAssets(string $hook): void
    {
        // Carregar assets em todas as páginas do LimpVix
        if (strpos($hook, 'limpvix') === false) {
            return;
        }

        // CSS Moderno
        wp_enqueue_style(
            "limpvix-admin-modern",
            plugins_url("assets/css/limpvix-admin-modern.css", dirname(dirname(__DIR__))),
            [],
            "1.1.0"
        );

        // CSS Legacy (compatibilidade)
        wp_enqueue_style(
            "limpvix-admin",
            plugins_url("assets/css/limpvix-admin.css", dirname(dirname(__DIR__))),
            [],
            "1.0.0"
        );

        // JS Templates
        wp_enqueue_script(
            "limpvix-templates",
            plugins_url("assets/js/message-templates.js", dirname(dirname(__DIR__))),
            ["jquery"],
            "1.0.0",
            true
        );

        // Localização
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

    public function renderDashboardPage(): void
    {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }
        ?>
        <div class="wrap limpvix-admin">
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-chart-line"></span>
                        Dashboard LimpVix
                    </h1>
                    <p class="limpvix-page-subtitle">Visão geral do sistema de gestão</p>
                </div>
            </div>

            <!-- Grid de Métricas -->
            <div class="limpvix-grid limpvix-grid-3">
                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon limpvix-stat-icon-primary">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">Orders Financeiras</div>
                        <div class="limpvix-stat-value">0</div>
                        <div class="limpvix-stat-change positive">
                            <span class="dashicons dashicons-arrow-up-alt"></span>
                            Em desenvolvimento
                        </div>
                    </div>
                </div>

                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon limpvix-stat-icon-success">
                        <span class="dashicons dashicons-megaphone"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">Mensagens Enviadas</div>
                        <div class="limpvix-stat-value">0</div>
                        <div class="limpvix-stat-change positive">
                            <span class="dashicons dashicons-arrow-up-alt"></span>
                            Sistema ativo
                        </div>
                    </div>
                </div>

                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon limpvix-stat-icon-warning">
                        <span class="dashicons dashicons-admin-users"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">Profissionais Ativos</div>
                        <div class="limpvix-stat-value">0</div>
                        <div class="limpvix-stat-change">
                            <span class="dashicons dashicons-minus"></span>
                            Aguardando dados
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alertas -->
            <div class="limpvix-alert limpvix-alert-info">
                <div class="limpvix-alert-icon">
                    <span class="dashicons dashicons-info"></span>
                </div>
                <div class="limpvix-alert-content">
                    <div class="limpvix-alert-title">Dashboard em Desenvolvimento</div>
                    <p>As métricas e funcionalidades do dashboard serão implementadas nas próximas versões.</p>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderSettingsPage(): void
    {
        if (!FinanceCapabilities::canManage()) {
            wp_die("Acesso negado");
        }
        ?>
        <div class="wrap limpvix-admin">
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-admin-settings"></span>
                        Configurações LimpVix
                    </h1>
                    <p class="limpvix-page-subtitle">Gerenciar configurações gerais do sistema</p>
                </div>
            </div>

            <div class="limpvix-grid limpvix-grid-2">
                <!-- Feature Flags Card -->
                <div class="limpvix-card limpvix-card-primary">
                    <div class="limpvix-card-header">
                        <h3>
                            <span class="dashicons dashicons-flag"></span>
                            Feature Flags
                        </h3>
                        <p>Controle das funcionalidades ativas do sistema</p>
                    </div>
                    <div class="limpvix-card-body">
                        <?php
                        $flags = new \LimpVix\Core\FeatureFlags();
                        $all_flags = $flags->getAll();
                        $important_flags = [
                            "financial_workflow" => "Workflow Financeiro",
                            "payout_engine" => "Motor de Payouts",
                            "admin_interface" => "Interface Admin",
                            "audit_logging" => "Logs de Auditoria",
                        ];
                        ?>
                        <table class="limpvix-table">
                            <thead>
                                <tr>
                                    <th>Funcionalidade</th>
                                    <th style="text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($important_flags as $flag => $label): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($label); ?></strong></td>
                                    <td style="text-align: center;">
                                        <?php if (isset($all_flags[$flag]) && $all_flags[$flag]): ?>
                                            <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Habilitado</span>
                                        <?php else: ?>
                                            <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Desabilitado</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Health Check Card -->
                <div class="limpvix-card limpvix-card-success">
                    <div class="limpvix-card-header">
                        <h3>
                            <span class="dashicons dashicons-heart"></span>
                            Health Check
                        </h3>
                        <p>Status e saúde do sistema</p>
                    </div>
                    <div class="limpvix-card-body">
                        <?php
                        $kernel = \LimpVix\Core\Kernel::getInstance();
                        $health = $kernel->healthCheck();
                        ?>
                        <table class="limpvix-table">
                            <tbody>
                                <tr>
                                    <td><strong>Versão do Plugin</strong></td>
                                    <td>
                                        <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($health["version"]); ?></span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Kernel LimpVix</strong></td>
                                    <td>
                                        <?php if ($health["booted"]): ?>
                                            <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Inicializado</span>
                                        <?php else: ?>
                                            <span class="limpvix-badge limpvix-badge-danger limpvix-badge-dot">Não inicializado</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Integração Booknetic</strong></td>
                                    <td>
                                        <?php if ($health["booknetic_active"]): ?>
                                            <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Ativo</span>
                                        <?php else: ?>
                                            <span class="limpvix-badge limpvix-badge-danger limpvix-badge-dot">Inativo</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Mercado Pago Settings (Full Width) -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-generic"></span>
                        Integrações e Pagamentos
                    </h3>
                    <p>Configurações de integração com Mercado Pago e outros serviços</p>
                </div>
                <div class="limpvix-card-body">
                    <?php MercadoPagoSettings::render(); ?>
                </div>
            </div>

            <!-- Google Business Settings (Full Width) -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-google"></span>
                        Google Meu Negócio
                    </h3>
                    <p>Configurações de integração com Google My Business para convites de avaliação</p>
                </div>
                <div class="limpvix-card-body">
                    <?php GoogleBusinessSettings::render(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderOrdersPage(): void {
        echo "<div class='wrap limpvix-admin'><div class='limpvix-page-header'><div><h1><span class='dashicons dashicons-list-view'></span> Orders Financeiras</h1><p class='limpvix-page-subtitle'>Gerenciar orders e fluxo financeiro</p></div></div><div class='limpvix-alert limpvix-alert-info'><div class='limpvix-alert-icon'><span class='dashicons dashicons-info'></span></div><div class='limpvix-alert-content'><div class='limpvix-alert-title'>Página em Desenvolvimento</div><p>A listagem de orders será implementada em breve.</p></div></div></div>";
    }

    public function renderPayoutsPage(): void {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }

        $page = new PayoutsPage();
        $page->render();
    }

    public function renderCommunicationCenterPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }

        $page = new CommunicationCenterPage();
        $page->render();
    }

    public function renderMessageFlowsPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }

        $page = new MessageFlowsAdminPage();
        $page->render();
    }

    public function renderMessageTemplatesPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\MessageTemplatesAdminPage();
            $page->render();
        }
    }

    public function renderFeedbackManagementPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            $page = new \LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage();
            $page->render();
        }
    }

    public function deactivate(): void {
        $this->capabilities->unregister();
        delete_transient("limpvix_finance_caps_registered");
    }
}
