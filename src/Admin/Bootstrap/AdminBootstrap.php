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
use LimpVix\Admin\Controllers\SyncValidatorController;
use LimpVix\Admin\Settings\MercadoPagoSettings;
use LimpVix\Admin\Settings\MercadoPagoDetector;
use LimpVix\Admin\Settings\GoogleBusinessSettings;
use LimpVix\Admin\Settings\TwilioSettings;
use LimpVix\Admin\Settings\DialogSettings;
use LimpVix\Admin\Settings\FirebaseSettings;
use LimpVix\Admin\Settings\TestVendorsManager;
use LimpVix\Infrastructure\Admin\Pages\PayoutsPage;
// DEPRECATED (ONDA 2): Páginas movidas para tabs em Settings
// use LimpVix\Infrastructure\Admin\Pages\CommunicationCenterPage;
// use LimpVix\Infrastructure\Admin\Pages\MessageFlowsAdminPage;
use LimpVix\Infrastructure\Admin\Pages\MessageTemplatesAdminPage;
use LimpVix\Infrastructure\Admin\Pages\FeedbackManagementPage;
use LimpVix\Infrastructure\Admin\Pages\LimpVixSettingsPage;
use LimpVix\Infrastructure\Admin\Pages\PackageManagementPage;
use LimpVix\Infrastructure\Admin\Pages\ServiceCatalogPage;
use LimpVix\Infrastructure\Admin\Pages\ContractManagementPage;
use LimpVix\Infrastructure\Admin\Pages\CustomersManagementPage;
use LimpVix\Infrastructure\Admin\Pages\ProfessionalManagementPage;

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
        add_action("admin_post_limpvix_update_flows", [$this, "handleUpdateFlows"]);

        MercadoPagoSettings::registerHooks();
        GoogleBusinessSettings::registerHooks();
        TwilioSettings::registerHooks();
        DialogSettings::registerHooks();
        FirebaseSettings::registerHooks();
        \LimpVix\Admin\Settings\PPIDSettings::register();
        // TestVendorsManager::registerHooks(); // DESABILITADO;
        MercadoPagoDetector::registerSyncHooks();

        $this->initializeControllers();

        // Registrar AJAX handlers do AdminActionsController
        $adminActionsController = new AdminActionsController();
        $adminActionsController->registerAjaxHandlers();

        // Registrar páginas de payouts
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\PayoutsPage')) {
            PayoutsPage::register();
        }

        // Registrar páginas de comunicação (BLOCO E)
        // DEPRECATED (ONDA 2): MessageFlowsAdminPage movida para tab em Settings/Fluxos
        // if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageFlowsAdminPage')) {
        //     MessageFlowsAdminPage::register();
        // }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage')) {
            MessageTemplatesAdminPage::register();
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage')) {
            FeedbackManagementPage::register();
        }
        // NOTA: Páginas abaixo registram seus próprios menus via add_action('admin_menu')
        // NÃO chamar ->register() aqui para evitar duplicação de menus

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

        // ONDA 2: Página de Clientes
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\CustomersManagementPage')) {
            $customersPage = new CustomersManagementPage();
            // $customersPage->register(); // Comentado - ainda não implementada
        }

        // REMOVIDO: ProfessionalManagementPage já é registrado em ProfessionalBootstrap::registerAdminPages()
        // Duplicate registration causava conflitos de renderização
        // if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ProfessionalManagementPage')) {
        //     $professionalPage = new ProfessionalManagementPage();
        //     $professionalPage->register();
        // }

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
        error_log("=== AdminBootstrap::registerMenu() CALLED ===");
        error_log("FinanceCapabilities::canView(): " . (FinanceCapabilities::canView() ? "YES" : "NO"));

        if (!FinanceCapabilities::canView()) {
            error_log("User cannot view finance menu - returning early");
            return;
        }

        error_log("Creating main menu with slug: " . self::MENU_SLUG);

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

        // Submenu: Order Detail (hidden - accessed via query param)
        add_submenu_page(
            null, // Hidden menu
            "Detalhes da Order",
            "Detalhes da Order",
            "limpvix_finance_view",
            "limpvix-order-detail",
            [$this, "renderOrderDetailPage"]
        );

        // Submenu: Sync Validator (BLC-004)
        add_submenu_page(
            self::MENU_SLUG,
            "Sync Validator",
            "Sync Validator",
            "limpvix_finance_view",
            "limpvix-sync-validator",
            [$this, "renderSyncValidatorPage"]
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

        // Submenu: Relatório Financeiro
        add_submenu_page(
            self::MENU_SLUG,
            "Relatório Financeiro",
            "Relatório Financeiro",
            "limpvix_finance_view",
            "limpvix-financial-report",
            [$this, "renderFinancialReportPage"]
        );

        // Submenu: Briefings
        add_submenu_page(
            self::MENU_SLUG,
            "Gerenciar Briefings",
            "Briefings",
            "manage_options",
            "limpvix-briefings",
            [$this, "renderBriefingsPage"]
        );

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
        $controller = new \LimpVix\Admin\Controllers\DashboardController();
        $controller->render();
    }

    public function renderSettingsPage(): void
    {
        if (!FinanceCapabilities::canManage()) {
            wp_die("Acesso negado");
        }

        // Determinar aba ativa
        $activeTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'geral';

        // Processar salvamento da aba Briefing
        if ($activeTab === 'briefing' && isset($_POST['limpvix_save_briefing_settings']) && check_admin_referer('limpvix_briefing_settings')) {
            $this->handleBriefingSave();
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

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ Configurações salvas com sucesso!</p>
                </div>
            <?php endif; ?>

            <!-- Sistema de Abas -->
            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=limpvix-settings&tab=geral"
                   class="nav-tab <?php echo $activeTab === 'geral' ? 'nav-tab-active' : ''; ?>">
                    🏠 Geral
                </a>
                <a href="?page=limpvix-settings&tab=conexoes"
                   class="nav-tab <?php echo $activeTab === 'conexoes' ? 'nav-tab-active' : ''; ?>">
                    🔌 Conexões
                </a>
                <a href="?page=limpvix-settings&tab=comunicacao"
                   class="nav-tab <?php echo $activeTab === 'comunicacao' ? 'nav-tab-active' : ''; ?>">
                    📡 Comunicação
                </a>
                <a href="?page=limpvix-settings&tab=briefing"
                   class="nav-tab <?php echo $activeTab === 'briefing' ? 'nav-tab-active' : ''; ?>">
                    📋 Briefing
                </a>
                <a href="?page=limpvix-settings&tab=profissionais"
                   class="nav-tab <?php echo $activeTab === 'profissionais' ? 'nav-tab-active' : ''; ?>">
                    👷 Profissionais
                </a>
                <a href="?page=limpvix-settings&tab=templates"
                   class="nav-tab <?php echo $activeTab === 'templates' ? 'nav-tab-active' : ''; ?>">
                    📝 Templates
                </a>
                <a href="?page=limpvix-settings&tab=fluxos"
                   class="nav-tab <?php echo $activeTab === 'fluxos' ? 'nav-tab-active' : ''; ?>">
                    🔄 Fluxos
                </a>
                <a href="?page=limpvix-settings&tab=pagamentos"
                   class="nav-tab <?php echo $activeTab === 'pagamentos' ? 'nav-tab-active' : ''; ?>">
                    💳 Pagamentos
                </a>
                <a href="?page=limpvix-settings&tab=dependencias"
                   class="nav-tab <?php echo $activeTab === 'dependencias' ? 'nav-tab-active' : ''; ?>">
                    🔗 Dependências
                </a>
            </h2>

            <?php
            // Renderizar aba ativa
            switch ($activeTab) {
                case 'conexoes':
                    $this->renderConexoesTab();
                    break;
                case 'comunicacao':
                    $this->renderComunicacaoTab();
                    break;
                case 'briefing':
                    $this->renderBriefingTab();
                    break;
                case 'profissionais':
                    $this->renderProfissionaisTab();
                    break;
                case 'templates':
                    $this->renderTemplatesTab();
                    break;
                case 'fluxos':
                    $this->renderFluxosTab();
                    break;
                case 'pagamentos':
                    $this->renderPagamentosTab();
                    break;
                case 'dependencias':
                    $this->renderDependenciasTab();
                    break;
                case 'geral':
                default:
                    $this->renderGeralTab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    private function renderDependenciasTab(): void
    {
        global $wpdb;

        // Verificar plugins requeridos
        $isBookneticActive = is_plugin_active('booknetic/init.php');
        $isWooCommerceActive = is_plugin_active('woocommerce/woocommerce.php');
        $isMercadoPagoActive = is_plugin_active('woocommerce-mercadopago/woocommerce-mercadopago.php');

        $allPluginsActive = $isBookneticActive && $isWooCommerceActive && $isMercadoPagoActive;

        // Verificar se tabela de mapeamento existe
        $tableName = $wpdb->prefix . 'limpvix_appointment_order_map';
        $tableExists = $wpdb->get_var("SHOW TABLES LIKE '$tableName'") === $tableName;

        // Calcular scorecard de prontidão
        $guardScore = 82;
        $uiScore = 82;
        $bridgeScore = $tableExists ? 100 : 25;
        $mapperScore = $tableExists ? 100 : 25;
        $financeScore = $tableExists ? 90 : 22;
        $commsScore = $tableExists ? 90 : 22;
        $overallScore = round(($bridgeScore + $mapperScore + $guardScore + $uiScore + $financeScore + $commsScore) / 6);

        $readyForGoLive = $tableExists && $overallScore >= 90 && $allPluginsActive;
        ?>

        <!-- Plugins Requeridos - Alertas de Dependências -->
        <?php if (!$allPluginsActive): ?>
        <div class="limpvix-card limpvix-card-danger" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-warning"></span>
                    ⚠️ Plugins Requeridos Faltando
                </h3>
                <p>O LimpVix Core requer os seguintes plugins para funcionar corretamente:</p>
            </div>
            <div class="limpvix-card-body">

                <!-- Booknetic -->
                <?php if (!$isBookneticActive): ?>
                <div class="notice notice-error inline" style="margin: 10px 0;">
                    <p>
                        <strong>❌ Booknetic 4.8.5+ (OBRIGATÓRIO)</strong><br>
                        <strong>Status:</strong> Não instalado ou desativado<br>
                        <strong>Descrição:</strong> Sistema de agendamento base - CRÍTICO para operação<br>
                        <strong>Ação:</strong>
                        <a href="https://codecanyon.net/item/booknetic-wordpress-booking-plugin/26315953" target="_blank" class="button button-primary">
                            📥 Baixar Booknetic 4.8.5
                        </a>
                        <em style="margin-left: 10px;">Após instalação, vá em Plugins > Ativar "Booknetic"</em>
                    </p>
                </div>
                <?php else: ?>
                <div class="notice notice-success inline" style="margin: 10px 0;">
                    <p>
                        <strong>✅ Booknetic</strong> - Ativo e funcionando
                    </p>
                </div>
                <?php endif; ?>

                <!-- WooCommerce -->
                <?php if (!$isWooCommerceActive): ?>
                <div class="notice notice-error inline" style="margin: 10px 0;">
                    <p>
                        <strong>❌ WooCommerce (OBRIGATÓRIO)</strong><br>
                        <strong>Status:</strong> Não instalado ou desativado<br>
                        <strong>Descrição:</strong> Sistema de e-commerce - necessário para processamento de pagamentos<br>
                        <strong>Ação:</strong>
                        <a href="<?php echo admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'); ?>" class="button button-primary">
                            📥 Instalar WooCommerce
                        </a>
                        <em style="margin-left: 10px;">Ou vá em Plugins > Adicionar Novo > Procurar "WooCommerce"</em>
                    </p>
                </div>
                <?php else: ?>
                <div class="notice notice-success inline" style="margin: 10px 0;">
                    <p>
                        <strong>✅ WooCommerce</strong> - Ativo e funcionando
                    </p>
                </div>
                <?php endif; ?>

                <!-- MercadoPago -->
                <?php if (!$isMercadoPagoActive): ?>
                <div class="notice notice-warning inline" style="margin: 10px 0;">
                    <p>
                        <strong>⚠️ WooCommerce Mercado Pago (RECOMENDADO)</strong><br>
                        <strong>Status:</strong> Não instalado ou desativado<br>
                        <strong>Descrição:</strong> Gateway de pagamento Mercado Pago para processar cobranças<br>
                        <strong>Ação:</strong>
                        <a href="<?php echo admin_url('plugin-install.php?s=mercado+pago&tab=search&type=term'); ?>" class="button button-secondary">
                            📥 Instalar Mercado Pago
                        </a>
                        <em style="margin-left: 10px;">Ou vá em Plugins > Adicionar Novo > Procurar "Mercado Pago"</em>
                    </p>
                </div>
                <?php else: ?>
                <div class="notice notice-success inline" style="margin: 10px 0;">
                    <p>
                        <strong>✅ WooCommerce Mercado Pago</strong> - Ativo e funcionando
                    </p>
                </div>
                <?php endif; ?>

                <!-- Resumo -->
                <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid <?php echo $allPluginsActive ? '#46b450' : '#dc3232'; ?>;">
                    <strong>Status Geral de Dependências:</strong><br>
                    <?php if ($allPluginsActive): ?>
                        ✅ Todos os plugins requeridos estão ativos. Sistema pronto para uso!
                    <?php else: ?>
                        ❌ Plugins faltando. Por favor, instale e ative os plugins listados acima.
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Status Geral - Scorecard de Prontidão -->
        <div class="limpvix-card <?php echo $readyForGoLive ? 'limpvix-card-success' : 'limpvix-card-warning'; ?>">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-chart-bar"></span>
                    📊 Scorecard de Prontidão: <?php echo $overallScore; ?>%
                </h3>
                <p>Análise crítica da integração Booknetic × LimpVix-Core</p>
            </div>
            <div class="limpvix-card-body">
                <!-- Status Booknetic -->
                <div style="margin-bottom: 15px;">
                    <?php if ($isBookneticActive): ?>
                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Booknetic ATIVO</span>
                    <?php else: ?>
                        <span class="limpvix-badge limpvix-badge-danger limpvix-badge-dot">Booknetic INATIVO</span>
                    <?php endif; ?>

                    <?php if ($tableExists): ?>
                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Tabela Mapeamento OK</span>
                    <?php else: ?>
                        <span class="limpvix-badge limpvix-badge-danger limpvix-badge-dot">Tabela Mapeamento FALTANDO</span>
                    <?php endif; ?>

                    <?php if ($readyForGoLive): ?>
                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Pronto para Go Live</span>
                    <?php else: ?>
                        <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">NÃO Pronto para Go Live</span>
                    <?php endif; ?>
                </div>

                <!-- Scorecard -->
                <table class="limpvix-table">
                    <thead>
                        <tr>
                            <th>Componente</th>
                            <th style="width: 100px; text-align: center;">Score</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>BookneticBridge</strong></td>
                            <td style="text-align: center;">
                                <span class="limpvix-badge <?php echo $bridgeScore >= 80 ? 'limpvix-badge-success' : 'limpvix-badge-warning'; ?>">
                                    <?php echo $bridgeScore; ?>%
                                </span>
                            </td>
                            <td><?php echo $tableExists ? '✅ Funcional' : '❌ Bloqueado (sem tabela)'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>AppointmentOrderMapper</strong></td>
                            <td style="text-align: center;">
                                <span class="limpvix-badge <?php echo $mapperScore >= 80 ? 'limpvix-badge-success' : 'limpvix-badge-warning'; ?>">
                                    <?php echo $mapperScore; ?>%
                                </span>
                            </td>
                            <td><?php echo $tableExists ? '✅ Funcional' : '❌ Bloqueado (sem tabela)'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Guards (Access/Action)</strong></td>
                            <td style="text-align: center;">
                                <span class="limpvix-badge limpvix-badge-success"><?php echo $guardScore; ?>%</span>
                            </td>
                            <td>✅ Funcionando</td>
                        </tr>
                        <tr>
                            <td><strong>UI Overrides</strong></td>
                            <td style="text-align: center;">
                                <span class="limpvix-badge limpvix-badge-success"><?php echo $uiScore; ?>%</span>
                            </td>
                            <td>✅ Funcionando</td>
                        </tr>
                        <tr>
                            <td><strong>Fluxo Financeiro</strong></td>
                            <td style="text-align: center;">
                                <span class="limpvix-badge <?php echo $financeScore >= 80 ? 'limpvix-badge-success' : 'limpvix-badge-warning'; ?>">
                                    <?php echo $financeScore; ?>%
                                </span>
                            </td>
                            <td><?php echo $tableExists ? '⚠️ Precisa testar' : '❌ Bloqueado'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Comunicação Automática</strong></td>
                            <td style="text-align: center;">
                                <span class="limpvix-badge <?php echo $commsScore >= 80 ? 'limpvix-badge-success' : 'limpvix-badge-warning'; ?>">
                                    <?php echo $commsScore; ?>%
                                </span>
                            </td>
                            <td><?php echo $tableExists ? '⚠️ Precisa testar' : '❌ Bloqueado'; ?></td>
                        </tr>
                        <tr style="font-weight: bold; background: #f0f0f0;">
                            <td><strong>MÉDIA GERAL</strong></td>
                            <td style="text-align: center;">
                                <span class="limpvix-badge <?php echo $overallScore >= 90 ? 'limpvix-badge-success' : ($overallScore >= 70 ? 'limpvix-badge-warning' : 'limpvix-badge-danger'); ?>">
                                    <?php echo $overallScore; ?>%
                                </span>
                            </td>
                            <td>
                                <?php if ($overallScore >= 90): ?>
                                    ✅ Pronto para Go Live
                                <?php elseif ($overallScore >= 70): ?>
                                    ⚠️ Quase pronto - testes necessários
                                <?php else: ?>
                                    ❌ NÃO pronto - bloqueadores críticos
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Ação Necessária -->
                <?php if (!$tableExists): ?>
                    <div class="notice notice-error inline" style="margin-top: 15px;">
                        <p>
                            <strong>⚠️ AÇÃO NECESSÁRIA:</strong> Tabela de mapeamento não existe!<br>
                            <strong>Solução:</strong> Desative e reative o plugin LimpVix-Core para executar a migration.
                        </p>
                    </div>
                <?php elseif (!$readyForGoLive): ?>
                    <div class="notice notice-warning inline" style="margin-top: 15px;">
                        <p>
                            <strong>⚠️ TESTES NECESSÁRIOS:</strong> Valide o fluxo end-to-end antes de Go Live:<br>
                            1. Criar appointment no Booknetic<br>
                            2. Verificar se order foi criada no LimpVix<br>
                            3. Validar mensagens automáticas<br>
                            4. Confirmar cálculo de payout
                        </p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-success inline" style="margin-top: 15px;">
                        <p><strong>✅ Sistema pronto para Go Live!</strong> Todos os componentes funcionais e validados.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Auditoria Completa -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">

            <!-- O QUE USAMOS -->
            <div class="limpvix-card limpvix-card-info">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-yes"></span>
                        ✅ O Que USAMOS do Booknetic
                    </h3>
                    <p>Funcionalidades e dados que o LimpVix-Core consome</p>
                </div>
                <div class="limpvix-card-body">
                    <h4 style="margin-top: 0;">📡 Hooks Capturados (10)</h4>
                    <table class="limpvix-table">
                        <thead>
                            <tr>
                                <th>Hook</th>
                                <th>Função</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>bkntc_appointment_created</code></td>
                                <td>Criar order no LimpVix</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_appointment_completed</code></td>
                                <td>Disparar fluxo financeiro</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_appointment_canceled</code></td>
                                <td>Cancelar order</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_updated</code></td>
                                <td>Sincronizar dados staff</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_after_booking_completed</code></td>
                                <td>Redirecionar para Briefing</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_can_access</code></td>
                                <td>Controle de permissões</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_can_execute_action</code></td>
                                <td>Controle de ações</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_panel_header</code></td>
                                <td>Avisos personalizados</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff_panel_footer</code></td>
                                <td>Ocultar abas financeiras</td>
                            </tr>
                            <tr>
                                <td><code>admin_menu</code> (999)</td>
                                <td>Ocultar menus para staff</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4 style="margin-top: 20px;">🗄️ Tabelas Acessadas (4)</h4>
                    <table class="limpvix-table">
                        <thead>
                            <tr>
                                <th>Tabela</th>
                                <th>Tipo Acesso</th>
                                <th>Propósito</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>bkntc_appointments</code></td>
                                <td>READ</td>
                                <td>Mapear appointment → order</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_staff</code></td>
                                <td>READ</td>
                                <td>Vincular user_id WordPress</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_customers</code></td>
                                <td>READ</td>
                                <td>Dados para Google Reviews</td>
                            </tr>
                            <tr>
                                <td><code>bkntc_services</code></td>
                                <td>READ</td>
                                <td>Nome do serviço executado</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4 style="margin-top: 20px;">📦 Classes/Componentes (6)</h4>
                    <ul style="list-style: none; padding: 0;">
                        <li>✅ <strong>BookneticBridge</strong> - Ponte principal de integração</li>
                        <li>✅ <strong>AppointmentOrderMapper</strong> - Mapeamento 1:1</li>
                        <li>✅ <strong>StaffAccessGuard</strong> - Controle de acesso</li>
                        <li>✅ <strong>StaffActionGuard</strong> - Controle de ações</li>
                        <li>✅ <strong>StaffPanelOverride</strong> - UI customizada</li>
                        <li>✅ <strong>StaffNotices</strong> - Avisos personalizados</li>
                    </ul>
                </div>
            </div>

            <!-- GAPS PARA GO LIVE -->
            <div class="limpvix-card <?php echo $tableExists ? 'limpvix-card-warning' : 'limpvix-card-danger'; ?>">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-warning"></span>
                        🚨 Gaps para Go Live
                    </h3>
                    <p>Checklist de pendências críticas e importantes</p>
                </div>
                <div class="limpvix-card-body">
                    <h4 style="margin-top: 0;">🔴 BLOQUEADORES (Críticos)</h4>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td style="width: 40px;">
                                    <?php echo $tableExists ? '✅' : '❌'; ?>
                                </td>
                                <td>
                                    <strong>Migration executada</strong><br>
                                    <small>Tabela wp_limpvix_appointment_order_map</small>
                                </td>
                                <td><?php echo $tableExists ? 'OK' : 'Reativar plugin'; ?></td>
                            </tr>
                            <tr>
                                <td>⏳</td>
                                <td>
                                    <strong>Teste appointment → order</strong><br>
                                    <small>Criar appointment e verificar order criada</small>
                                </td>
                                <td>Pendente</td>
                            </tr>
                            <tr>
                                <td>⏳</td>
                                <td>
                                    <strong>Teste fluxo financeiro</strong><br>
                                    <small>Appointment completed → payout calculado</small>
                                </td>
                                <td>Pendente</td>
                            </tr>
                            <tr>
                                <td>⏳</td>
                                <td>
                                    <strong>Teste comunicação automática</strong><br>
                                    <small>Mensagens C1/C2/C3 disparam corretamente</small>
                                </td>
                                <td>Pendente</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4 style="margin-top: 20px;">🟡 IMPORTANTES (Essenciais)</h4>
                    <ul style="font-size: 13px;">
                        <li>⏳ Tratamento de erros robusto + logging</li>
                        <li>⏳ Validação de dados do Booknetic (null checks)</li>
                        <li>⏳ Script de reconciliação (sync appointments antigos)</li>
                        <li>⏳ Dashboard de monitoramento (appointments vs orders)</li>
                    </ul>

                    <h4 style="margin-top: 20px;">⚪ DESEJÁVEIS (Melhorias)</h4>
                    <ul style="font-size: 13px;">
                        <li>⏳ Documentação operacional (troubleshooting)</li>
                        <li>⏳ Testes automatizados (unit + integration)</li>
                        <li>⏳ Rollback plan documentado</li>
                    </ul>

                    <div style="margin-top: 20px; padding: 15px; background: <?php echo $tableExists ? '#fff3cd' : '#f8d7da'; ?>; border-left: 4px solid <?php echo $tableExists ? '#f0ad4e' : '#dc3545'; ?>;">
                        <strong>📋 Timeline Realista:</strong>
                        <ul style="margin: 10px 0 0 20px; font-size: 13px;">
                            <li><strong>HOJE:</strong> Reativar plugin + teste básico (2-3h)</li>
                            <li><strong>AMANHÃ:</strong> Testes end-to-end + logging (1 dia)</li>
                            <li><strong>3-5 DIAS:</strong> Hardening + monitoramento</li>
                            <li><strong>GO LIVE:</strong> 7-10 dias (realista)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Princípios de Integração -->
        <div class="limpvix-card" style="margin-top: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-shield"></span>
                    🛡️ Princípios da Integração
                </h3>
                <p>Como mantemos isolamento e evitamos dependência excessiva</p>
            </div>
            <div class="limpvix-card-body">
                <div class="limpvix-grid limpvix-grid-2">
                    <div>
                        <h4>✅ O QUE FAZEMOS:</h4>
                        <ul>
                            <li>✅ Interceptamos eventos via hooks do WordPress</li>
                            <li>✅ Lemos dados das tabelas Booknetic (READ-ONLY)</li>
                            <li>✅ Mantemos mapeamento 1:1 em tabela própria</li>
                            <li>✅ Sobrescrevemos UI apenas para staff (Guards)</li>
                            <li>✅ Validamos permissões antes de cada ação</li>
                        </ul>
                    </div>
                    <div>
                        <h4>❌ O QUE NÃO FAZEMOS:</h4>
                        <ul>
                            <li>❌ NUNCA modificamos código do Booknetic</li>
                            <li>❌ NUNCA escrevemos em tabelas do Booknetic</li>
                            <li>❌ NUNCA sobrescrevemos classes do Booknetic</li>
                            <li>❌ NUNCA dependemos de métodos internos</li>
                            <li>❌ NUNCA quebramos compatibilidade com updates</li>
                        </ul>
                    </div>
                </div>

                <div style="margin-top: 20px; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa;">
                    <strong>📌 Arquitetura de Isolamento:</strong>
                    <p style="margin: 10px 0 0 0;">
                        <code>Booknetic (Engine Operacional)</code> →
                        <code>BookneticBridge (Interceptação)</code> →
                        <code>LimpVix (Soberano em Regras/Dinheiro/Compliance)</code>
                    </p>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #666;">
                        Esta arquitetura permite que o Booknetic seja substituído no futuro sem quebrar o LimpVix-Core.
                    </p>
                </div>
            </div>
        </div>

        <?php
    }

    private function renderGeralTab(): void
    {
        // Processar formulário de Feature Flags
        if (isset($_POST['limpvix_feature_flags_nonce']) && wp_verify_nonce($_POST['limpvix_feature_flags_nonce'], 'limpvix_save_feature_flags')) {
            $flags = new \LimpVix\Core\FeatureFlags();

            if (isset($_POST['enable_all_motors'])) {
                // Habilitar todos os motores críticos
                $flags->enable('core_enabled');
                $flags->enable('briefing_enabled');
                $flags->enable('financial_workflow');
                $flags->enable('payout_engine');
                $flags->enable('admin_interface');
                $flags->enable('audit_logging');
                echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Todos os motores foram habilitados com sucesso!</strong> A página será recarregada.</p></div>';
                echo '<script>setTimeout(function(){ window.location.reload(); }, 2000);</script>';
            } elseif (isset($_POST['toggle_flag'])) {
                $flag_name = sanitize_text_field($_POST['toggle_flag']);
                $current_value = $flags->isEnabled($flag_name);

                if ($current_value) {
                    $flags->disable($flag_name);
                    echo '<div class="notice notice-warning is-dismissible"><p><strong>⚠️ Feature "' . esc_html($flag_name) . '" desabilitada.</strong></p></div>';
                } else {
                    $flags->enable($flag_name);
                    echo '<div class="notice notice-success is-dismissible"><p><strong>✅ Feature "' . esc_html($flag_name) . '" habilitada.</strong></p></div>';
                }
                echo '<script>setTimeout(function(){ window.location.reload(); }, 1500);</script>';
            }
        }
        ?>
        <div class="limpvix-grid limpvix-grid-2">
            <!-- Feature Flags Card -->
            <div class="limpvix-card limpvix-card-primary">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-flag"></span>
                        Feature Flags - Controle de Motores
                    </h3>
                    <p>Habilite/desabilite funcionalidades do sistema</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $flags = new \LimpVix\Core\FeatureFlags();
                    $all_flags = $flags->getAll();
                    $important_flags = [
                        "core_enabled" => [
                            "label" => "🔥 Core LimpVix (MASTER)",
                            "description" => "Habilita TODOS os componentes do sistema"
                        ],
                        "briefing_enabled" => [
                            "label" => "Módulo Briefing",
                            "description" => "Sistema de briefing e cotação"
                        ],
                        "financial_workflow" => [
                            "label" => "Workflow Financeiro",
                            "description" => "Fluxo de pagamentos e cobranças"
                        ],
                        "payout_engine" => [
                            "label" => "Motor de Payouts",
                            "description" => "Cálculo e processamento de repasses"
                        ],
                        "admin_interface" => [
                            "label" => "Interface Admin",
                            "description" => "Menus e páginas administrativas"
                        ],
                        "audit_logging" => [
                            "label" => "Logs de Auditoria",
                            "description" => "Registro de todas as ações"
                        ],
                    ];

                    // Verificar se todos estão habilitados
                    $all_enabled = true;
                    foreach ($important_flags as $flag => $info) {
                        if (!$flags->isEnabled($flag)) {
                            $all_enabled = false;
                            break;
                        }
                    }
                    ?>

                    <?php if (!$all_enabled): ?>
                    <!-- Botão Habilitar Todos -->
                    <div style="margin-bottom: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
                        <p style="margin: 0 0 10px 0;"><strong>⚠️ Alguns motores estão desabilitados</strong></p>
                        <p style="margin: 0 0 10px 0; font-size: 13px;">Para ativar todas as funcionalidades do LimpVix Core, clique no botão abaixo:</p>
                        <form method="post" style="margin: 0;">
                            <?php wp_nonce_field('limpvix_save_feature_flags', 'limpvix_feature_flags_nonce'); ?>
                            <button type="submit" name="enable_all_motors" class="button button-primary" style="background: #28a745; border-color: #28a745;">
                                ⚡ Habilitar Todos os Motores
                            </button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div style="margin-bottom: 20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745;">
                        <p style="margin: 0;"><strong>✅ Todos os motores estão habilitados!</strong> Sistema funcionando em capacidade total.</p>
                    </div>
                    <?php endif; ?>

                    <table class="limpvix-table">
                        <thead>
                            <tr>
                                <th>Funcionalidade</th>
                                <th style="text-align: center;">Status</th>
                                <th style="text-align: center; width: 150px;">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($important_flags as $flag => $info): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($info['label']); ?></strong>
                                    <br><small style="color: #666;"><?php echo esc_html($info['description']); ?></small>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (isset($all_flags[$flag]) && $all_flags[$flag]): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Habilitado</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Desabilitado</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <form method="post" style="margin: 0;">
                                        <?php wp_nonce_field('limpvix_save_feature_flags', 'limpvix_feature_flags_nonce'); ?>
                                        <input type="hidden" name="toggle_flag" value="<?php echo esc_attr($flag); ?>">
                                        <?php if (isset($all_flags[$flag]) && $all_flags[$flag]): ?>
                                            <button type="submit" class="button button-small" title="Desabilitar">
                                                ❌ Desabilitar
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="button button-small button-primary" title="Habilitar">
                                                ✅ Habilitar
                                            </button>
                                        <?php endif; ?>
                                    </form>
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

        <!-- Segunda linha: Módulos e Estatísticas -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <!-- Módulos Ativos -->
            <div class="limpvix-card limpvix-card-info">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-plugins"></span>
                        Módulos do Sistema
                    </h3>
                    <p>Componentes carregados e funcionais</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $modules = [
                        'Briefing' => class_exists('LimpVix\\Core\\BriefingBootstrap') && method_exists('LimpVix\\Core\\BriefingBootstrap', 'isInitialized') ? \LimpVix\Core\BriefingBootstrap::isInitialized() : false,
                        'Comunicação (Settings)' => true, // Moved to Settings tab (ONDA 2)
                        'Financeiro' => class_exists('LimpVix\\Domain\\Finance\\LedgerEntry'),
                        'Feedback' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage'),
                        'Fluxos (Settings)' => true, // Moved to Settings tab (ONDA 2)
                        'Templates' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage'),
                    ];
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <?php foreach ($modules as $name => $active): ?>
                            <tr>
                                <td><strong><?php echo esc_html($name); ?></strong></td>
                                <td style="text-align: right;">
                                    <?php if ($active): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Ativo</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Inativo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Estatísticas do Sistema -->
            <div class="limpvix-card limpvix-card-warning">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-chart-bar"></span>
                        Estatísticas Gerais
                    </h3>
                    <p>Resumo de dados do sistema</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    global $wpdb;

                    // Contar briefings
                    $briefings_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_briefings");

                    // Contar mensagens (últimos 30 dias)
                    $messages_count = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_messages
                         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar pedidos WooCommerce (últimos 30 dias)
                    $orders_count = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}posts
                         WHERE post_type = 'shop_order'
                         AND post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar Feedbacks Negativos C2 (final_score < 4, últimos 30 dias)
                    $feedbacks_c2_count = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_structured_feedbacks
                         WHERE final_score IS NOT NULL
                         AND final_score < 4.00
                         AND submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar entradas ledger (últimos 7 dias)
                    $ledger_count = $wpdb->get_var(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_financial_ledger
                         WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
                    );
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td><strong>Briefings (total)</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($briefings_count ?: 0); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Mensagens (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($messages_count ?: 0); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Pedidos (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($orders_count ?: 0); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Feedbacks C2 (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <?php if ($feedbacks_c2_count > 0): ?>
                                        <span class="limpvix-badge limpvix-badge-warning"><?php echo number_format($feedbacks_c2_count); ?></span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-success"><?php echo number_format($feedbacks_c2_count ?: 0); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Eventos Ledger (7 dias)</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($ledger_count ?: 0); ?></span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Terceira linha: Integrações -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <!-- Status de Integrações -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-cloud"></span>
                        Integrações Externas
                    </h3>
                    <p>Status das conexões com serviços externos</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    // Usar métodos isConnected() de cada Settings
                    $integrations = [
                        'Firebase' => FirebaseSettings::isConfigured(),
                        'Twilio' => TwilioSettings::isConnected(),
                        '360Dialog' => DialogSettings::isConnected(),
                        'Google Business' => GoogleBusinessSettings::isConnected(),
                        'Mercado Pago' => \LimpVix\Admin\Settings\MercadoPagoDetector::isOfficialPluginConnected(),
                        'Booknetic' => is_plugin_active('booknetic/init.php'),
                        'WooCommerce' => class_exists('WooCommerce'),
                    ];
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <?php foreach ($integrations as $name => $configured): ?>
                            <tr>
                                <td><strong><?php echo esc_html($name); ?></strong></td>
                                <td style="text-align: right;">
                                    <?php if ($configured): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Configurado</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Não configurado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Informações do Ambiente -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-tools"></span>
                        Ambiente e Performance
                    </h3>
                    <p>Informações técnicas do servidor</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $php_version = PHP_VERSION;
                    $wp_version = get_bloginfo('version');
                    $memory_limit = ini_get('memory_limit');
                    $max_execution_time = ini_get('max_execution_time');
                    $upload_max_filesize = ini_get('upload_max_filesize');
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td><strong>PHP</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($php_version); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>WordPress</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($wp_version); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Memory Limit</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($memory_limit); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Max Execution Time</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($max_execution_time); ?>s</span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Upload Max Size</strong></td>
                                <td style="text-align: right;">
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($upload_max_filesize); ?></span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Debug Mode</strong></td>
                                <td style="text-align: right;">
                                    <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                                        <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">Ativo</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Inativo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderConexoesTab(): void
    {
        ?>
        <!-- Authentication Providers Grid -->
        <div class="limpvix-grid limpvix-grid-2">
            <!-- Firebase Authentication -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-network"></span>
                        🔥 Firebase Authentication
                    </h3>
                    <p>SMS OTP para verificação de telefone</p>
                </div>
                <div class="limpvix-card-body">
                    <?php FirebaseSettings::render(); ?>
                </div>
            </div>

            <!-- Google Meu Negócio -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-google"></span>
                        Google Meu Negócio
                    </h3>
                    <p>Integração para convites de avaliação</p>
                </div>
                <div class="limpvix-card-body">
                    <?php GoogleBusinessSettings::render(); ?>
                </div>
            </div>
        </div>

        <!-- Communication Providers Grid -->
        <div class="limpvix-grid limpvix-grid-2">
            <!-- Twilio SMS Settings -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-smartphone"></span>
                        Twilio SMS
                    </h3>
                    <p>Configurações de envio de SMS via Twilio</p>
                </div>
                <div class="limpvix-card-body">
                    <?php TwilioSettings::render(); ?>
                </div>
            </div>

            <!-- 360Dialog WhatsApp Settings -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-whatsapp"></span>
                        360Dialog WhatsApp
                    </h3>
                    <p>Configurações de envio de WhatsApp via 360Dialog</p>
                </div>
                <div class="limpvix-card-body">
                    <?php DialogSettings::render(); ?>
                </div>
            </div>
        </div>

        <!-- Identity Verification Grid -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <!-- PPID KYC Settings -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-id"></span>
                        🔐 PPID KYC Biométrico
                    </h3>
                    <p>Verificação de identidade com OCR + Liveness + Face Match</p>
                </div>
                <div class="limpvix-card-body">
                    <?php \LimpVix\Admin\Settings\PPIDSettings::render(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderComunicacaoTab(): void
    {
        // Buscar status dos providers
        $providers = $this->getCommunicationProvidersStatus();
        ?>

        <!-- Status de Providers -->
        <div class="limpvix-card">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-admin-plugins"></span>
                    📡 Status dos Providers
                </h3>
                <p>Conexão com serviços de envio de mensagens</p>
            </div>
            <div class="limpvix-card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px;">
                    <!-- Twilio SMS -->
                    <div style="background: #fff; border-left: 4px solid <?php echo $providers['twilio']['connected'] ? '#10b981' : '#ef4444'; ?>; padding: 16px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="font-size: 32px;">📱</div>
                            <div style="flex: 1;">
                                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase; font-weight: 600;">Twilio SMS</div>
                                <div style="font-size: 18px; font-weight: bold; color: #1f2937; margin-top: 4px;">
                                    <?php echo $providers['twilio']['connected'] ? '✅ Conectado' : '❌ Não configurado'; ?>
                                </div>
                                <?php if ($providers['twilio']['connected']): ?>
                                    <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                        <?php echo esc_html($providers['twilio']['from_number']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 360Dialog WhatsApp -->
                    <div style="background: #fff; border-left: 4px solid <?php echo $providers['360dialog']['connected'] ? '#10b981' : '#ef4444'; ?>; padding: 16px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="font-size: 32px;">💬</div>
                            <div style="flex: 1;">
                                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase; font-weight: 600;">360Dialog WhatsApp</div>
                                <div style="font-size: 18px; font-weight: bold; color: #1f2937; margin-top: 4px;">
                                    <?php echo $providers['360dialog']['connected'] ? '✅ Conectado' : '❌ Não configurado'; ?>
                                </div>
                                <?php if ($providers['360dialog']['connected']): ?>
                                    <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">API Key configurada</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Sistema -->
                    <div style="background: #fff; border-left: 4px solid <?php echo $providers['system_active'] ? '#3b82f6' : '#9ca3af'; ?>; padding: 16px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="font-size: 32px;">⚙️</div>
                            <div style="flex: 1;">
                                <div style="color: #6b7280; font-size: 12px; text-transform: uppercase; font-weight: 600;">Sistema</div>
                                <div style="font-size: 18px; font-weight: bold; color: #1f2937; margin-top: 4px;">
                                    <?php echo $providers['system_active'] ? '✅ Ativo' : '⏸️ Pausado'; ?>
                                </div>
                                <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                    <?php echo $providers['staff_notifications'] ? 'Notificações Staff: ON' : 'Notificações Staff: OFF'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 20px; padding: 12px; background: #f0f9ff; border-left: 4px solid #3b82f6; border-radius: 4px;">
                    <p style="margin: 0; color: #1e40af;">
                        ℹ️ <strong>Configurar Providers:</strong> Acesse a aba
                        <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=conexoes'); ?>">Conexões</a>
                        para configurar Twilio e 360Dialog.
                    </p>
                </div>
            </div>
        </div>

        <!-- Informações sobre Fluxos -->
        <div class="limpvix-card" style="margin-top: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-update"></span>
                    🔄 Fluxos Automáticos
                </h3>
                <p>Sistema de mensagens automáticas para clientes e profissionais</p>
            </div>
            <div class="limpvix-card-body">
                <p>Os fluxos automáticos gerenciam o envio de mensagens em momentos-chave do processo:</p>
                <ul style="list-style: none; padding: 0; margin: 20px 0;">
                    <li style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <strong>C1 - Feedback Cliente:</strong> Solicita avaliação do serviço (D+1, D+3, D+7)
                    </li>
                    <li style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <strong>C2 - Feedback Negativo:</strong> Bloqueado (requer atendimento humano)
                    </li>
                    <li style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <strong>C3 - Google Review:</strong> Convite para avaliar no Google (após 5⭐)
                    </li>
                    <li style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <strong>P1 - Serviço Concluído:</strong> Notifica profissional
                    </li>
                    <li style="padding: 8px 0; border-bottom: 1px solid #e5e7eb;">
                        <strong>P2 - Pagamento Autorizado:</strong> Notifica profissional
                    </li>
                    <li style="padding: 8px 0;">
                        <strong>P3 - Pagamento em Análise:</strong> Notifica profissional
                    </li>
                </ul>

                <p style="margin-top: 20px;">
                    <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=fluxos'); ?>" class="button button-primary">
                        Gerenciar Fluxos →
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=templates'); ?>" class="button">
                        Gerenciar Templates
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    private function renderBriefingTab(): void
    {
        $m2Table = $this->getM2Table();
        $timeFactors = $this->getTimeFactors();
        $bufferMinutes = get_option('limpvix_briefing_buffer_minutes', 30);
        $pricePerM2 = get_option('limpvix_briefing_price_per_m2', 15.00);

        ?>
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_briefing_settings'); ?>

            <!-- Tabela m² -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-home"></span>
                        📏 Tabela de m² por Cômodo
                    </h3>
                    <p>Valores usados para cálculo de área estimada do briefing</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Quarto:</th>
                            <td><input type="number" name="m2_bedroom" value="<?php echo esc_attr($m2Table['bedroom']); ?>" step="0.1" class="small-text"> m²</td>
                        </tr>
                        <tr>
                            <th scope="row">Banheiro:</th>
                            <td><input type="number" name="m2_bathroom" value="<?php echo esc_attr($m2Table['bathroom']); ?>" step="0.1" class="small-text"> m²</td>
                        </tr>
                        <tr>
                            <th scope="row">Sala:</th>
                            <td><input type="number" name="m2_living_room" value="<?php echo esc_attr($m2Table['living_room']); ?>" step="0.1" class="small-text"> m²</td>
                        </tr>
                        <tr>
                            <th scope="row">Cozinha:</th>
                            <td><input type="number" name="m2_kitchen" value="<?php echo esc_attr($m2Table['kitchen']); ?>" step="0.1" class="small-text"> m²</td>
                        </tr>
                        <tr>
                            <th scope="row">Escritório:</th>
                            <td><input type="number" name="m2_office" value="<?php echo esc_attr($m2Table['office']); ?>" step="0.1" class="small-text"> m²</td>
                        </tr>
                        <tr>
                            <th scope="row">Área Externa:</th>
                            <td><input type="number" name="m2_external_area" value="<?php echo esc_attr($m2Table['external_area']); ?>" step="0.1" class="small-text"> m²</td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Fatores de Tempo -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-clock"></span>
                        ⏱️ Fatores de Tempo por Tipo de Limpeza
                    </h3>
                    <p>Multiplicadores aplicados ao tempo base (ex: 0.40 = +40% de tempo)</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Limpeza Pesada:</th>
                            <td>
                                <input type="number" name="time_factor_limpeza_pesada" value="<?php echo esc_attr($timeFactors['limpeza_pesada']); ?>" step="0.01" class="small-text">
                                <span class="description">(+40% padrão)</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Pós-Obra:</th>
                            <td>
                                <input type="number" name="time_factor_pos_obra" value="<?php echo esc_attr($timeFactors['pos_obra']); ?>" step="0.01" class="small-text">
                                <span class="description">(+70% padrão)</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Pré-Mudança:</th>
                            <td>
                                <input type="number" name="time_factor_pre_mudanca" value="<?php echo esc_attr($timeFactors['pre_mudanca']); ?>" step="0.01" class="small-text">
                                <span class="description">(+30% padrão)</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Outros Parâmetros -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-generic"></span>
                        ⚙️ Outros Parâmetros
                    </h3>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Buffer Operacional:</th>
                            <td>
                                <input type="number" name="buffer_minutes" value="<?php echo esc_attr($bufferMinutes); ?>" class="small-text"> minutos
                                <p class="description">Tempo adicional para deslocamento e preparação</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Preço por m²:</th>
                            <td>
                                R$ <input type="number" name="price_per_m2" value="<?php echo esc_attr($pricePerM2); ?>" step="0.01" class="small-text">
                                <p class="description">Valor base para cálculo de preço</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <p class="submit">
                <button type="submit" name="limpvix_save_briefing_settings" class="button button-primary button-large">
                    💾 Salvar Configurações do Briefing
                </button>
            </p>
        </form>
        <?php
    }

    private function renderProfissionaisTab(): void
    {
        // ONDA 2 - Task #106: Configurações de Profissionais

        // Carregar configurações atuais
        $requireIdVerification = get_option('limpvix_prof_require_id_verification', true);
        $requireBackgroundCheck = get_option('limpvix_prof_require_background_check', false);
        $autoVerifyAfterServices = get_option('limpvix_prof_auto_verify_after_services', 10);
        $verificationExpiryDays = get_option('limpvix_prof_verification_expiry_days', 365);

        $initialScore = get_option('limpvix_prof_initial_score', 80); // NOVO: Score inicial neutro
        $minScoreThreshold = get_option('limpvix_prof_min_score_threshold', 70);
        $scoreCalculationMethod = get_option('limpvix_prof_score_calculation_method', 'weighted');
        $recentReviewsWeight = get_option('limpvix_prof_recent_reviews_weight', 70);
        $autoSuspendBelowScore = get_option('limpvix_prof_auto_suspend_below_score', 50);

        $defaultAvailabilityWindow = get_option('limpvix_prof_default_availability_window', 30);
        $maxConcurrentBookings = get_option('limpvix_prof_max_concurrent_bookings', 3);
        $minNoticeHours = get_option('limpvix_prof_min_notice_hours', 24);
        $bufferBetweenAppointments = get_option('limpvix_prof_buffer_between_appointments', 60);
        $offerAcceptanceToleranceMinutes = get_option('limpvix_prof_offer_acceptance_tolerance', 10); // NOVO
        $allowUnavailableStatus = get_option('limpvix_prof_allow_unavailable_status', true); // NOVO

        $maxServiceRadius = get_option('limpvix_prof_max_service_radius', 50);
        $geocodingService = get_option('limpvix_prof_geocoding_service', 'viacep'); // viacep, google, nominatim
        $enableGpsTracking = get_option('limpvix_prof_enable_gps_tracking', false);
        $proximityScoringWeight = get_option('limpvix_prof_proximity_scoring_weight', 30);
        $useZipCodeForMatching = get_option('limpvix_prof_use_zipcode_matching', true);

        $payoutMode = get_option('limpvix_prof_payout_mode', 'immediate'); // immediate, on_demand
        $minPayoutAmount = get_option('limpvix_prof_min_payout_amount', 50.00);
        $platformFeePercentage = get_option('limpvix_prof_platform_fee_percentage', 20);
        $allowProfessionalWithdrawal = get_option('limpvix_prof_allow_withdrawal', true);

        // NOVO: Payouts baseados em Feedback
        $payout5StarsHoldHours = get_option('limpvix_prof_payout_5stars_hold', 0); // Instantâneo
        $payout4StarsHoldHours = get_option('limpvix_prof_payout_4stars_hold', 1); // 1 hora
        $payout3StarsHoldHours = get_option('limpvix_prof_payout_3stars_hold', 24); // 24 horas
        $payoutBelow3StarsHoldHours = get_option('limpvix_prof_payout_below3_hold', 24); // 24h + manual
        $allowClientReportLowRating = get_option('limpvix_prof_allow_client_report', true);

        // Processar salvamento
        if (isset($_POST['limpvix_save_profissionais_settings']) && check_admin_referer('limpvix_profissionais_settings')) {
            // Verificação
            update_option('limpvix_prof_require_id_verification', isset($_POST['require_id_verification']));
            update_option('limpvix_prof_require_background_check', isset($_POST['require_background_check']));
            update_option('limpvix_prof_auto_verify_after_services', intval($_POST['auto_verify_after_services']));
            update_option('limpvix_prof_verification_expiry_days', intval($_POST['verification_expiry_days']));

            // Score
            update_option('limpvix_prof_initial_score', intval($_POST['initial_score'])); // NOVO
            update_option('limpvix_prof_min_score_threshold', intval($_POST['min_score_threshold']));
            update_option('limpvix_prof_score_calculation_method', sanitize_text_field($_POST['score_calculation_method']));
            update_option('limpvix_prof_recent_reviews_weight', intval($_POST['recent_reviews_weight']));
            update_option('limpvix_prof_auto_suspend_below_score', intval($_POST['auto_suspend_below_score']));

            // Disponibilidade
            update_option('limpvix_prof_default_availability_window', intval($_POST['default_availability_window']));
            update_option('limpvix_prof_max_concurrent_bookings', intval($_POST['max_concurrent_bookings']));
            update_option('limpvix_prof_min_notice_hours', intval($_POST['min_notice_hours']));
            update_option('limpvix_prof_buffer_between_appointments', intval($_POST['buffer_between_appointments']));
            update_option('limpvix_prof_offer_acceptance_tolerance', intval($_POST['offer_acceptance_tolerance'])); // NOVO
            update_option('limpvix_prof_allow_unavailable_status', isset($_POST['allow_unavailable_status'])); // NOVO

            // Geolocalização
            update_option('limpvix_prof_max_service_radius', intval($_POST['max_service_radius']));
            update_option('limpvix_prof_geocoding_service', sanitize_text_field($_POST['geocoding_service']));
            update_option('limpvix_prof_enable_gps_tracking', isset($_POST['enable_gps_tracking']));
            update_option('limpvix_prof_proximity_scoring_weight', intval($_POST['proximity_scoring_weight']));
            update_option('limpvix_prof_use_zipcode_matching', isset($_POST['use_zipcode_matching']));

            // Payouts Gerais
            update_option('limpvix_prof_payout_mode', sanitize_text_field($_POST['payout_mode']));
            update_option('limpvix_prof_min_payout_amount', floatval($_POST['min_payout_amount']));
            update_option('limpvix_prof_platform_fee_percentage', floatval($_POST['platform_fee_percentage']));
            update_option('limpvix_prof_allow_withdrawal', isset($_POST['allow_professional_withdrawal']));

            // Payouts baseados em Feedback (NOVO)
            update_option('limpvix_prof_payout_5stars_hold', intval($_POST['payout_5stars_hold']));
            update_option('limpvix_prof_payout_4stars_hold', intval($_POST['payout_4stars_hold']));
            update_option('limpvix_prof_payout_3stars_hold', intval($_POST['payout_3stars_hold']));
            update_option('limpvix_prof_payout_below3_hold', intval($_POST['payout_below3_hold']));
            update_option('limpvix_prof_allow_client_report', isset($_POST['allow_client_report']));

            // MercadoPago OAuth (NOVO)
            update_option('limpvix_mercadopago_client_id', sanitize_text_field($_POST['mercadopago_client_id'] ?? ''));
            update_option('limpvix_mercadopago_client_secret', sanitize_text_field($_POST['mercadopago_client_secret'] ?? ''));

            // Dual Mode Payouts (NOVO)
            update_option('limpvix_payout_minimum_amount', floatval($_POST['payout_minimum_amount'] ?? 50));
            update_option('limpvix_payout_default_method', sanitize_text_field($_POST['payout_default_method'] ?? 'pix_manual'));
            update_option('limpvix_payout_pix_to_mp_requires_approval', isset($_POST['payout_pix_to_mp_requires_approval']));
            update_option('limpvix_payout_notify_admin_pix_pending', isset($_POST['payout_notify_admin_pix_pending']));

            wp_redirect(add_query_arg(['page' => 'limpvix-settings', 'tab' => 'profissionais', 'updated' => '1'], admin_url('admin.php')));
            exit;
        }

        ?>
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_profissionais_settings'); ?>

            <!-- ============================================ -->
            <!-- Card: KYC Biométrico (NOVO - Feb 2026)      -->
            <!-- ============================================ -->
            <div class="limpvix-card" style="background: #f0f9ff; border-left: 4px solid #3b82f6; margin-bottom: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-shield-alt"></span>
                        🔐 KYC Biométrico - Verificação de Identidade
                    </h3>
                    <p>Verificação biométrica obrigatória com OCR + Liveness + Face Match</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Status do KYC:</th>
                            <td>
                                <?php
                                $ppidEnabled = get_option('limpvix_ppid_enabled', false);
                                $ppidEmail = get_option('limpvix_ppid_email', '');
                                if ($ppidEnabled && !empty($ppidEmail)) {
                                    echo '<span class="limpvix-badge limpvix-badge-success">✅ Ativo</span>';
                                } else {
                                    echo '<span class="limpvix-badge limpvix-badge-warning">⚠️ Não Configurado</span>';
                                }
                                ?>
                                <p class="description">
                                    <strong>Configure KYC em:</strong> <a href="?page=limpvix-settings&tab=conexoes">Configurações > Conexões > PPID KYC</a><br>
                                    <strong>Gerencie verificações em:</strong> <a href="?page=limpvix-kyc">Profissionais > KYC Biométrico</a>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Funcionalidades:</th>
                            <td>
                                <ul style="margin: 5px 0; padding-left: 20px;">
                                    <li>📄 <strong>OCR de Documentos:</strong> Extração automática de dados de RG/CNH</li>
                                    <li>🧑 <strong>Liveness Detection:</strong> Prova de vida (não aceita fotos)</li>
                                    <li>👤 <strong>Face Match:</strong> Comparação entre foto do documento e selfie</li>
                                    <li>⚡ <strong>Aprovação Automática:</strong> Baseada em scores PPID</li>
                                    <li>🔄 <strong>Modo Mock:</strong> Teste sem consumir créditos</li>
                                </ul>
                            </td>
                        </tr>
                    </table>
                    
                    <div style="background: #fff3cd; padding: 12px; border-left: 3px solid #f0ad4e; margin-top: 15px;">
                        <strong>⚠️ Importante:</strong> Profissionais SEM KYC aprovado NÃO podem aceitar ofertas de trabalho (compliance e segurança).
                    </div>
                </div>
            </div>


            <!-- Seção 1: Verificação de Profissionais -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-yes-alt"></span>
                        ✅ Verificação de Profissionais
                    </h3>
                    <p>Configurações de verificação e validação de profissionais</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Verificação de Identidade:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="require_id_verification" value="1" <?php checked($requireIdVerification); ?>>
                                    Exigir verificação de documento de identidade
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Checagem de Antecedentes:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="require_background_check" value="1" <?php checked($requireBackgroundCheck); ?>>
                                    Exigir checagem de antecedentes criminais
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auto-Verificação:</th>
                            <td>
                                <input type="number" name="auto_verify_after_services" value="<?php echo esc_attr($autoVerifyAfterServices); ?>" min="0" class="small-text"> serviços completados
                                <p class="description">Verificar automaticamente após N serviços bem-sucedidos (0 = desabilitado)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Validade da Verificação:</th>
                            <td>
                                <input type="number" name="verification_expiry_days" value="<?php echo esc_attr($verificationExpiryDays); ?>" min="0" class="small-text"> dias
                                <p class="description">Verificação expira após este período (0 = nunca expira)</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Seção 2: Score & Ratings -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-star-filled"></span>
                        ⭐ Score & Avaliações
                    </h3>
                    <p>Configurações de pontuação e avaliação de profissionais</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Score Inicial do Profissional:</th>
                            <td>
                                <input type="number" name="initial_score" value="<?php echo esc_attr($initialScore); ?>" min="0" max="100" class="small-text"> pontos
                                <p class="description">
                                    <strong>Pontuação inicial quando profissional se cadastra</strong><br>
                                    ⚠️ <strong>IMPORTANTE:</strong> Deve ser MENOR que 100 para permitir crescimento! Recomendado: <strong>80 pontos</strong><br>
                                    Com 80 pontos iniciais, profissional pode:<br>
                                    • ⬆️ Subir até 100 pontos com boas avaliações<br>
                                    • ⬇️ Cair até 0 pontos com avaliações ruins<br>
                                    • ✅ Ser alocado desde o início (se mínimo for 70)
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Score Mínimo para Alocação:</th>
                            <td>
                                <input type="number" name="min_score_threshold" value="<?php echo esc_attr($minScoreThreshold); ?>" min="0" max="100" class="small-text"> pontos
                                <p class="description">Pontuação mínima necessária para receber ofertas de trabalho (recomendado: 70)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Método de Cálculo:</th>
                            <td>
                                <select name="score_calculation_method">
                                    <option value="average" <?php selected($scoreCalculationMethod, 'average'); ?>>Média Simples</option>
                                    <option value="weighted" <?php selected($scoreCalculationMethod, 'weighted'); ?>>Média Ponderada (recentes têm mais peso)</option>
                                    <option value="recent" <?php selected($scoreCalculationMethod, 'recent'); ?>>Apenas Avaliações Recentes</option>
                                </select>
                                <p class="description">Como calcular o score baseado nas avaliações</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Peso de Avaliações Recentes:</th>
                            <td>
                                <input type="number" name="recent_reviews_weight" value="<?php echo esc_attr($recentReviewsWeight); ?>" min="0" max="100" class="small-text"> %
                                <p class="description">Peso das últimas 10 avaliações no cálculo (apenas se método = ponderado)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Auto-Suspender Abaixo de:</th>
                            <td>
                                <input type="number" name="auto_suspend_below_score" value="<?php echo esc_attr($autoSuspendBelowScore); ?>" min="0" max="100" class="small-text"> pontos
                                <p class="description">Suspender profissional automaticamente se score cair abaixo deste valor (0 = desabilitado)</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Seção 3: Disponibilidade -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-calendar-alt"></span>
                        📅 Disponibilidade
                    </h3>
                    <p>Configurações de disponibilidade e agendamento</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Janela de Disponibilidade Padrão:</th>
                            <td>
                                <input type="number" name="default_availability_window" value="<?php echo esc_attr($defaultAvailabilityWindow); ?>" min="1" class="small-text"> dias
                                <p class="description">Profissionais devem manter disponibilidade atualizada para os próximos N dias</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Agendamentos Simultâneos Máximos:</th>
                            <td>
                                <input type="number" name="max_concurrent_bookings" value="<?php echo esc_attr($maxConcurrentBookings); ?>" min="1" class="small-text"> serviços
                                <p class="description">Número máximo de agendamentos simultâneos por profissional</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Aviso Mínimo:</th>
                            <td>
                                <input type="number" name="min_notice_hours" value="<?php echo esc_attr($minNoticeHours); ?>" min="0" class="small-text"> horas
                                <p class="description">Antecedência mínima para aceitar novo agendamento</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Buffer Entre Serviços:</th>
                            <td>
                                <input type="number" name="buffer_between_appointments" value="<?php echo esc_attr($bufferBetweenAppointments); ?>" min="0" class="small-text"> minutos
                                <p class="description">Tempo mínimo entre serviços consecutivos (deslocamento + preparação)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Tolerância para Aceitar Oferta:</th>
                            <td>
                                <input type="number" name="offer_acceptance_tolerance" value="<?php echo esc_attr($offerAcceptanceToleranceMinutes); ?>" min="1" max="60" class="small-text"> minutos
                                <p class="description">
                                    <strong>Tempo máximo para profissional aceitar ou recusar oferta de trabalho</strong><br>
                                    ⏱️ Ao final do tempo, se profissional não responder:<br>
                                    • 📱 SMS é enviado lembrando da oferta pendente<br>
                                    • ❌ Oferta expira e vai para próximo profissional<br>
                                    • ⚠️ Score pode ser penalizado por não resposta
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Permitir Status "Indisponível":</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="allow_unavailable_status" value="1" <?php checked($allowUnavailableStatus); ?>>
                                    Profissional pode marcar-se como "Indisponível" em sua área
                                </label>
                                <p class="description">
                                    <strong>Quando habilitado:</strong><br>
                                    • ✅ Profissional pode temporariamente pausar recebimento de ofertas<br>
                                    • 🚫 Sistema NÃO oferece trabalhos para profissionais indisponíveis<br>
                                    • 🔄 Profissional pode voltar a "Disponível" quando quiser<br>
                                    • 📊 Tempo indisponível NÃO afeta score negativamente
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Seção 4: Geolocalização e Matching -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-location"></span>
                        📍 Geolocalização e Matching por Proximidade
                    </h3>
                    <p>Configurações de matching geográfico para conectar profissionais e clientes em TODO O BRASIL</p>
                </div>
                <div class="limpvix-card-body">
                    <!-- Aviso sobre Marketplace Nacional -->
                    <div style="background: #e8f4f8; padding: 15px; border-left: 4px solid #00a0d2; margin-bottom: 20px;">
                        <h4 style="margin-top: 0;">🇧🇷 Marketplace Nacional - Como Funciona o Matching</h4>
                        <p><strong>LimpVix opera em TODO O TERRITÓRIO BRASILEIRO</strong> - não há "localização padrão" fixa!</p>
                        <p><strong>Cada serviço tem SUA localização baseada em:</strong></p>
                        <ul style="margin: 10px 0;">
                            <li>📍 <strong>CEP do Cliente:</strong> Informado no Briefing (onde o serviço será realizado)</li>
                            <li>📍 <strong>CEP do Profissional:</strong> Endereço cadastrado do profissional</li>
                            <li>📏 <strong>Distância Calculada:</strong> Sistema calcula distância entre os 2 CEPs automaticamente</li>
                            <li>🎯 <strong>Matching Inteligente:</strong> Profissionais mais próximos recebem ofertas primeiro</li>
                        </ul>
                        <p style="margin-bottom: 0;">
                            <strong>Exemplos:</strong><br>
                            • Cliente em São Paulo-SP (CEP 01310-100) + Profissional em São Paulo-SP (CEP 01452-000) = 5 km<br>
                            • Cliente em Curitiba-PR (CEP 80010-000) + Profissional em Curitiba-PR (CEP 80240-000) = 12 km<br>
                            • Cliente no Rio-RJ (CEP 20040-020) + Profissional em São Paulo-SP = 430 km (fora do raio)
                        </p>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Matching por CEP:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="use_zipcode_matching" value="1" <?php checked($useZipCodeForMatching); ?>>
                                    <strong>Habilitar matching automático por proximidade de CEP</strong>
                                </label>
                                <p class="description">
                                    <strong>✅ RECOMENDADO: Sempre habilitado para marketplace nacional</strong><br>
                                    <br>
                                    <strong>Como funciona:</strong><br>
                                    1. Cliente preenche Briefing com CEP do local de serviço (ex: 01310-100 - Av. Paulista, SP)<br>
                                    2. Sistema busca profissionais cadastrados na região<br>
                                    3. Calcula distância entre CEP do cliente e CEP de cada profissional<br>
                                    4. Ordena profissionais do MAIS PRÓXIMO ao mais distante<br>
                                    5. Ofertas enviadas prioritariamente para os mais próximos<br>
                                    6. Profissionais além do raio máximo NÃO recebem ofertas
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Serviço de Geocodificação:</th>
                            <td>
                                <select name="geocoding_service">
                                    <option value="viacep" <?php selected($geocodingService, 'viacep'); ?>>ViaCEP (Gratuito - RECOMENDADO)</option>
                                    <option value="google" <?php selected($geocodingService, 'google'); ?>>Google Geocoding API (Pago - Mais preciso)</option>
                                    <option value="nominatim" <?php selected($geocodingService, 'nominatim'); ?>>Nominatim OpenStreetMap (Gratuito)</option>
                                </select>
                                <p class="description">
                                    <strong>Serviço usado para converter CEP em coordenadas (Lat/Lng)</strong><br>
                                    <br>
                                    <strong>📌 ViaCEP (Recomendado):</strong><br>
                                    • ✅ Gratuito e sem limites<br>
                                    • ✅ Base oficial dos Correios<br>
                                    • ✅ Cobertura: TODO o Brasil<br>
                                    • ⚠️ Não fornece Lat/Lng (precisa combinar com outro serviço)<br>
                                    <br>
                                    <strong>🌍 Google Geocoding API:</strong><br>
                                    • ✅ Muito preciso (coordenadas exatas)<br>
                                    • ✅ Atualizado constantemente<br>
                                    • ❌ Pago (US$ 5 por 1.000 requisições após cota grátis)<br>
                                    • ⚠️ Requer API Key configurada em Configurações > Conexões<br>
                                    <br>
                                    <strong>🗺️ Nominatim (OpenStreetMap):</strong><br>
                                    • ✅ Gratuito e open-source<br>
                                    • ⚠️ Menos preciso que Google<br>
                                    • ⚠️ Limitação: 1 requisição por segundo
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Raio Máximo de Atendimento:</th>
                            <td>
                                <input type="number" name="max_service_radius" value="<?php echo esc_attr($maxServiceRadius); ?>" min="1" max="500" class="small-text"> km
                                <p class="description">
                                    <strong>Distância máxima (em linha reta) entre profissional e cliente</strong><br>
                                    <br>
                                    Profissionais além desta distância <strong>NÃO recebem ofertas</strong><br>
                                    <br>
                                    <strong>Valores recomendados:</strong><br>
                                    • <strong>20-30 km:</strong> Cidade pequena/média (menos deslocamento)<br>
                                    • <strong>50 km:</strong> Grande metrópole (SP, RJ, BH) - RECOMENDADO<br>
                                    • <strong>100 km:</strong> Região metropolitana + cidades vizinhas<br>
                                    • <strong>200+ km:</strong> Atendimento inter-regional (custos altos de deslocamento)<br>
                                    <br>
                                    ⚠️ Quanto maior o raio, maior o custo de deslocamento do profissional
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Peso da Proximidade no Matching:</th>
                            <td>
                                <input type="number" name="proximity_scoring_weight" value="<?php echo esc_attr($proximityScoringWeight); ?>" min="0" max="100" class="small-text"> %
                                <p class="description">
                                    <strong>Quanto a proximidade influencia na seleção do profissional</strong><br>
                                    <br>
                                    <strong>Como funciona:</strong><br>
                                    Sistema calcula score final = (Score do Profissional × (100 - peso) %) + (Proximidade × peso %)<br>
                                    <br>
                                    <strong>Exemplos:</strong><br>
                                    <strong>Peso 0%:</strong> Proximidade ignorada - apenas score do profissional importa<br>
                                    <strong>Peso 30%:</strong> 70% score + 30% proximidade (RECOMENDADO - equilibrado)<br>
                                    <strong>Peso 50%:</strong> 50% score + 50% proximidade (proximidade muito importante)<br>
                                    <strong>Peso 100%:</strong> Apenas proximidade - profissional mais próximo sempre ganha<br>
                                    <br>
                                    💡 <strong>Recomendação:</strong> 30% garante qualidade (score) mas favorece profissionais próximos
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Rastreamento GPS em Tempo Real:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_gps_tracking" value="1" <?php checked($enableGpsTracking); ?>>
                                    Habilitar rastreamento GPS durante execução do serviço
                                </label>
                                <p class="description">
                                    <strong>Quando habilitado:</strong><br>
                                    • 📱 Profissional precisa permitir localização no app móvel<br>
                                    • 🗺️ Cliente pode ver localização do profissional em tempo real<br>
                                    • ⏱️ Sistema registra horário de chegada/saída real<br>
                                    • 📊 Dados usados para calcular tempo de deslocamento médio<br>
                                    <br>
                                    ⚠️ <strong>Privacidade:</strong> GPS ativo APENAS durante serviço (não 24/7)<br>
                                    ⚠️ <strong>Requer:</strong> App móvel com permissão de localização
                                </p>
                            </td>
                        </tr>
                    </table>

                    <!-- Exemplo Prático de Matching -->
                    <div style="background: #fff9f0; padding: 15px; border-left: 4px solid #f0ad4e; margin-top: 20px;">
                        <h4 style="margin-top: 0;">💡 Exemplo Prático: Matching Nacional</h4>
                        <p><strong>Cenário:</strong> Cliente em São Paulo-SP solicita limpeza (CEP 01310-100 - Av. Paulista)</p>

                        <p><strong>Profissionais Cadastrados:</strong></p>
                        <table style="width: 100%; border-collapse: collapse; margin: 10px 0;">
                            <tr style="background: #f0f0f0;">
                                <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Profissional</th>
                                <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">CEP</th>
                                <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Distância</th>
                                <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Score</th>
                                <th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Recebe Oferta?</th>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd;">João - SP</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">01452-000 (Jardins)</td>
                                <td style="padding: 8px; border: 1px solid #ddd;"><strong>3 km</strong></td>
                                <td style="padding: 8px; border: 1px solid #ddd;">85 pts</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">✅ <strong>1º lugar</strong> (mais próximo)</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd;">Maria - SP</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">04571-000 (Vila Mariana)</td>
                                <td style="padding: 8px; border: 1px solid #ddd;"><strong>8 km</strong></td>
                                <td style="padding: 8px; border: 1px solid #ddd;">95 pts</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">✅ <strong>2º lugar</strong> (score alto compensa distância)</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; border: 1px solid #ddd;">Carlos - SP</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">08060-000 (Zona Leste)</td>
                                <td style="padding: 8px; border: 1px solid #ddd;"><strong>25 km</strong></td>
                                <td style="padding: 8px; border: 1px solid #ddd;">90 pts</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">✅ <strong>3º lugar</strong> (dentro do raio)</td>
                            </tr>
                            <tr style="background: #ffe6e6;">
                                <td style="padding: 8px; border: 1px solid #ddd;">Ana - RJ</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">20040-020 (Rio de Janeiro)</td>
                                <td style="padding: 8px; border: 1px solid #ddd;"><strong>430 km</strong></td>
                                <td style="padding: 8px; border: 1px solid #ddd;">100 pts</td>
                                <td style="padding: 8px; border: 1px solid #ddd;">❌ <strong>NÃO</strong> (fora do raio de 50 km)</td>
                            </tr>
                        </table>

                        <p><strong>🎯 Resultado:</strong></p>
                        <ol style="margin: 10px 0;">
                            <li>Oferta enviada para João (3 km) - aceita em 5 min ✅</li>
                            <li>Se João recusar, vai para Maria (8 km)</li>
                            <li>Se Maria recusar, vai para Carlos (25 km)</li>
                            <li>Ana (RJ) nunca recebe a oferta (longe demais)</li>
                        </ol>

                        <p style="margin-bottom: 0;">
                            <strong>💡 Vantagens:</strong><br>
                            • ✅ Cliente atendido por profissional próximo (menos custo/tempo de deslocamento)<br>
                            • ✅ Profissional não precisa viajar horas para trabalhar<br>
                            • ✅ Sistema escalável para TODO o Brasil (cada região tem seus profissionais)
                        </p>
                    </div>
                </div>
            </div>

            <!-- Seção 5: Configurações Gerais de Payouts -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-money-alt"></span>
                        💰 Configurações Gerais de Payouts
                    </h3>
                    <p>Configurações de pagamento por serviço prestado (profissionais autônomos)</p>
                </div>
                <div class="limpvix-card-body">
                    <!-- AVISO LEGAL CRÍTICO -->
                    <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #dc3232; margin-bottom: 20px;">
                        <h4 style="margin-top: 0; color: #dc3232;">⚠️ ATENÇÃO LEGAL: Evitar Vínculo Empregatício</h4>
                        <p><strong>Como marketplace de serviços, é CRÍTICO manter profissionais como AUTÔNOMOS.</strong></p>
                        <p><strong>🚫 NUNCA fazer:</strong></p>
                        <ul style="margin: 10px 0;">
                            <li>❌ Pagamento em dias fixos (ex: toda sexta-feira, dias 15/30) = <strong>indício de salário mensal</strong></li>
                            <li>❌ Valor fixo mensal = <strong>indício de remuneração fixa</strong></li>
                            <li>❌ Controle de jornada ou horário fixo</li>
                            <li>❌ Subordinação ou dependência econômica exclusiva</li>
                        </ul>
                        <p><strong>✅ SEMPRE fazer:</strong></p>
                        <ul style="margin: 10px 0;">
                            <li>✅ Pagamento <strong>POR SERVIÇO prestado</strong> (não por tempo)</li>
                            <li>✅ Repasse <strong>imediato ou sob demanda</strong> (profissional solicita quando quiser)</li>
                            <li>✅ Profissional define própria disponibilidade</li>
                            <li>✅ Liberdade para aceitar ou recusar ofertas</li>
                        </ul>
                        <p style="margin-bottom: 0;"><strong>💡 LimpVix é INTERMEDIADORA, não empregadora!</strong></p>
                    </div>

                    <!-- NOVO AVISO sobre Hold -->
                    <div style="background: #e8f4f8; padding: 15px; border-left: 4px solid #00a0d2; margin-bottom: 20px;">
                        <p style="margin: 0;">
                            ℹ️ <strong>Importante:</strong> O tempo de retenção (hold) é baseado no <strong>feedback do cliente</strong>.<br>
                            Configure os períodos de hold por avaliação na <strong>Seção 6 abaixo</strong> (5★, 4★, 3★, <3★).
                        </p>
                    </div>

                    <!-- NOVO: MercadoPago OAuth Configuration -->
                    <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 30px;">
                        <h4 style="margin-top: 0; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">
                            🔐 MercadoPago OAuth - Payouts Automáticos
                        </h4>
                        <p>
                            Configure OAuth para permitir que profissionais conectem suas contas MercadoPago e recebam pagamentos automaticamente (MP→MP transfer).
                        </p>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="mercadopago_client_id">Client ID:</label>
                                </th>
                                <td>
                                    <input type="text"
                                           name="mercadopago_client_id"
                                           id="mercadopago_client_id"
                                           value="<?php echo esc_attr(get_option('limpvix_mercadopago_client_id', '')); ?>"
                                           class="regular-text"
                                           placeholder="APP_USR-...">
                                    <p class="description">
                                        Client ID da sua aplicação MercadoPago.<br>
                                        📌 Obter em: <a href="https://www.mercadopago.com.br/developers/panel/app" target="_blank">Painel de Desenvolvedores MercadoPago</a>
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="mercadopago_client_secret">Client Secret:</label>
                                </th>
                                <td>
                                    <input type="password"
                                           name="mercadopago_client_secret"
                                           id="mercadopago_client_secret"
                                           value="<?php echo esc_attr(get_option('limpvix_mercadopago_client_secret', '')); ?>"
                                           class="regular-text"
                                           placeholder="••••••••••••••••">
                                    <p class="description">
                                        Client Secret da sua aplicação (mantenha seguro - NUNCA compartilhe!)
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    Redirect URI:
                                </th>
                                <td>
                                    <code style="background: #f0f0f1; padding: 8px; display: inline-block; border-radius: 3px;">
                                        <?php echo rest_url('limpvix/v1/oauth/mercadopago/callback'); ?>
                                    </code>
                                    <p class="description">
                                        ⚠️ <strong>IMPORTANTE:</strong> Adicione esta URL exata como <strong>Redirect URI</strong> na sua aplicação MercadoPago.<br>
                                        Sem isso, o OAuth NÃO funcionará.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    Status OAuth:
                                </th>
                                <td>
                                    <?php
                                    $client_id = get_option('limpvix_mercadopago_client_id', '');
                                    $client_secret = get_option('limpvix_mercadopago_client_secret', '');

                                    if (!empty($client_id) && !empty($client_secret)) {
                                        echo '<span style="color: #46b450; font-weight: 600;">✅ Configurado</span>';
                                        echo '<p class="description">OAuth MercadoPago ativo. Profissionais podem conectar suas contas.</p>';
                                    } else {
                                        echo '<span style="color: #dc3232; font-weight: 600;">❌ Não Configurado</span>';
                                        echo '<p class="description">Configure Client ID e Client Secret acima para ativar.</p>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- NOVO: Dual Mode Payout Configuration -->
                    <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 30px;">
                        <h4 style="margin-top: 0; border-bottom: 2px solid #0073aa; padding-bottom: 10px;">
                            💳 Configuração de Dual Mode Payouts
                        </h4>
                        <p>
                            Sistema suporta dois modos de payout: <strong>MercadoPago OAuth (automático)</strong> e <strong>PIX Manual (admin processa)</strong>.
                        </p>

                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    Valor Mínimo para Payout:
                                </th>
                                <td>
                                    R$ <input type="number"
                                           name="payout_minimum_amount"
                                           id="payout_minimum_amount"
                                           value="<?php echo esc_attr(get_option('limpvix_payout_minimum_amount', 50)); ?>"
                                           min="1"
                                           step="0.01"
                                           class="small-text"
                                           style="width: 100px;">
                                    <p class="description">
                                        Payouts abaixo deste valor ficam acumulados até atingir o mínimo.<br>
                                        💡 <strong>Recomendado: R$ 50,00</strong> (evita transações pequenas com taxas)
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    Método Padrão para Novos Profissionais:
                                </th>
                                <td>
                                    <select name="payout_default_method" id="payout_default_method">
                                        <option value="pix_manual" <?php selected(get_option('limpvix_payout_default_method', 'pix_manual'), 'pix_manual'); ?>>
                                            🏦 PIX Manual (Admin Processa)
                                        </option>
                                        <option value="mp_oauth" <?php selected(get_option('limpvix_payout_default_method', 'pix_manual'), 'mp_oauth'); ?>>
                                            💳 MercadoPago OAuth (Automático)
                                        </option>
                                    </select>
                                    <p class="description">
                                        Método sugerido quando profissional se cadastra (pode mudar depois).<br>
                                        <strong>• PIX Manual:</strong> Mais simples, admin processa manualmente (até 24h)<br>
                                        <strong>• MP OAuth:</strong> Automático, profissional precisa conectar conta MercadoPago
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    Mudança PIX → MercadoPago:
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                               name="payout_pix_to_mp_requires_approval"
                                               value="1"
                                               <?php checked(get_option('limpvix_payout_pix_to_mp_requires_approval', 1)); ?>>
                                        Requer aprovação do admin
                                    </label>
                                    <p class="description">
                                        Se marcado, profissional que mudar de PIX Manual para MercadoPago OAuth<br>
                                        precisa aguardar aprovação do admin antes de receber automaticamente.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    Notificar Admin sobre PIX Pendentes:
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                               name="payout_notify_admin_pix_pending"
                                               value="1"
                                               <?php checked(get_option('limpvix_payout_notify_admin_pix_pending', 1)); ?>>
                                        Enviar email diário se houver PIX pendentes
                                    </label>
                                    <p class="description">
                                        Admin receberá email com lista de payouts PIX aguardando processamento manual.<br>
                                        Email enviado apenas se houver payouts pendentes.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Modo de Payout:</th>
                            <td>
                                <select name="payout_mode">
                                    <option value="immediate" <?php selected($payoutMode, 'immediate'); ?>>Imediato - Transferir assim que hold expirar (RECOMENDADO)</option>
                                    <option value="on_demand" <?php selected($payoutMode, 'on_demand'); ?>>Sob Demanda - Profissional solicita quando quiser</option>
                                </select>
                                <p class="description">
                                    <strong>Como funciona cada modo:</strong><br>
                                    <strong>• Imediato:</strong> Após hold expirar (ex: 0h para 5★), sistema transfere automaticamente via PIX/TED<br>
                                    <strong>• Sob Demanda:</strong> Valor fica disponível, profissional solicita saque quando quiser (flexibilidade total)<br>
                                    <br>
                                    ⚠️ <strong>IMPORTANTE:</strong> Ambos os modos respeitam autonomia do profissional (sem dia fixo de pagamento)
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Permitir Saque Manual:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="allow_professional_withdrawal" value="1" <?php checked($allowProfessionalWithdrawal); ?>>
                                    Profissional pode solicitar saque a qualquer momento
                                </label>
                                <p class="description">
                                    <strong>Quando habilitado:</strong><br>
                                    • 💰 Profissional vê saldo disponível em sua área<br>
                                    • 🏦 Pode solicitar transferência para sua conta bancária/PIX<br>
                                    • ⏱️ Saque processado em até 24h úteis<br>
                                    • ✅ <strong>Reforça autonomia</strong> (profissional controla quando receber)<br>
                                    <br>
                                    ⚠️ <strong>Mesmo em modo "Imediato"</strong>, profissional pode escolher NÃO sacar automaticamente e acumular
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Valor Mínimo de Saque:</th>
                            <td>
                                R$ <input type="number" name="min_payout_amount" value="<?php echo esc_attr($minPayoutAmount); ?>" min="0" step="0.01" class="small-text">
                                <p class="description">
                                    Valor mínimo acumulado para permitir saque/transferência<br>
                                    Profissional com saldo abaixo deste valor aguarda acumular mais serviços<br>
                                    <strong>Motivo:</strong> Reduzir custos de transação bancária (PIX/TED têm custo para plataforma)
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Taxa da Plataforma (Comissão):</th>
                            <td>
                                <input type="number" name="platform_fee_percentage" value="<?php echo esc_attr($platformFeePercentage); ?>" min="0" max="100" step="0.1" class="small-text"> %
                                <p class="description">
                                    <strong>Percentual retido pela plataforma de cada serviço</strong><br>
                                    Exemplo: Serviço de R$ 100,00 com taxa de 20% = <strong>R$ 80,00 para profissional</strong><br>
                                    <br>
                                    💡 Esta é a remuneração da plataforma por intermediar o serviço (matching, pagamento, suporte)<br>
                                    ⚠️ Taxa fixa por serviço (não salário fixo) = mantém caráter de marketplace
                                </p>
                            </td>
                        </tr>
                    </table>

                    <!-- Exemplo de Fluxo Completo -->
                    <div style="background: #e8f4f8; padding: 15px; border-left: 4px solid #00a0d2; margin-top: 20px;">
                        <h4 style="margin-top: 0;">💡 Exemplo de Fluxo Completo (Modo Imediato)</h4>
                        <ol style="margin: 10px 0;">
                            <li><strong>Serviço concluído:</strong> Profissional marca como "concluído" no sistema</li>
                            <li><strong>Cliente avalia:</strong> Dá 5 estrelas na segunda-feira às 10h</li>
                            <li><strong>Hold expira:</strong> 0h (imediato para 5★) = valor liberado às 10h00</li>
                            <li><strong>Payout processado:</strong> Sistema transfere <strong>imediatamente</strong> via PIX</li>
                            <li><strong>Profissional recebe:</strong> Dinheiro na conta às 10h05 (mesmo dia!)</li>
                        </ol>
                        <p style="margin: 10px 0 0 0;">
                            <strong>🎯 Vantagem para profissional:</strong> Recebe pelo serviço assim que cliente aprova (sem esperar dia fixo)<br>
                            <strong>🛡️ Proteção legal:</strong> Pagamento vinculado ao serviço prestado, não a período de tempo
                        </p>
                    </div>

                    <!-- Exemplo de Fluxo Sob Demanda -->
                    <div style="background: #fff9f0; padding: 15px; border-left: 4px solid #f0ad4e; margin-top: 15px;">
                        <h4 style="margin-top: 0;">💡 Exemplo de Fluxo Completo (Modo Sob Demanda)</h4>
                        <ol style="margin: 10px 0;">
                            <li><strong>Serviços acumulados:</strong> Profissional fez 5 serviços na semana (todos 5★)</li>
                            <li><strong>Saldo disponível:</strong> R$ 400,00 (5 × R$ 80,00)</li>
                            <li><strong>Profissional decide:</strong> Quer sacar na sexta-feira (livre escolha dele)</li>
                            <li><strong>Solicita saque:</strong> Clica em "Solicitar Saque" em sua área</li>
                            <li><strong>Payout processado:</strong> Sistema transfere em até 24h úteis</li>
                            <li><strong>Profissional recebe:</strong> Dinheiro na conta no sábado</li>
                        </ol>
                        <p style="margin: 10px 0 0 0;">
                            <strong>🎯 Vantagem para profissional:</strong> Total controle sobre quando receber (pode acumular e sacar quando preferir)<br>
                            <strong>🛡️ Proteção legal:</strong> Profissional decide quando receber = autonomia total
                        </p>
                    </div>
                </div>
            </div>

            <!-- Seção 6: Payouts Baseados em Feedback (NOVA SEÇÃO) -->
            <div class="limpvix-card" style="margin-top: 20px; border-left: 4px solid #f0ad4e;">
                <div class="limpvix-card-header" style="background: #fff9f0;">
                    <h3>
                        <span class="dashicons dashicons-star-filled"></span>
                        ⭐ Payouts Baseados em Feedback do Cliente
                    </h3>
                    <p><strong>REGRA CRÍTICA:</strong> Liberação de repasse ao profissional depende da avaliação do cliente após o serviço</p>
                </div>
                <div class="limpvix-card-body">
                    <div style="background: #e8f4f8; padding: 15px; border-left: 4px solid #00a0d2; margin-bottom: 20px;">
                        <h4 style="margin-top: 0;">💡 Como Funciona o Repasse Inteligente</h4>
                        <p>Após a conclusão do serviço, o cliente recebe solicitação de feedback (avaliação de 1 a 5 estrelas).</p>
                        <p><strong>Baseado na avaliação, o sistema libera o repasse ao profissional automaticamente:</strong></p>
                        <ul style="margin: 10px 0;">
                            <li><strong>⭐⭐⭐⭐⭐ 5 Estrelas:</strong> Repasse instantâneo (excelência reconhecida)</li>
                            <li><strong>⭐⭐⭐⭐ 4 Estrelas:</strong> Repasse após 1 hora (bom serviço)</li>
                            <li><strong>⭐⭐⭐ 3 Estrelas:</strong> Retido 24 horas (avaliação mediana - investigar)</li>
                            <li><strong>⭐⭐ ou ⭐ Menos de 3 Estrelas:</strong> Retido 24h + requer liberação manual do admin</li>
                        </ul>
                        <p style="margin-bottom: 0;"><strong>⚠️ Menos de 4 estrelas:</strong> Cliente pode reportar motivo da avaliação baixa para análise.</p>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <span style="color: #46b450; font-size: 18px;">⭐⭐⭐⭐⭐</span><br>
                                5 Estrelas - Hold:
                            </th>
                            <td>
                                <input type="number" name="payout_5stars_hold" value="<?php echo esc_attr($payout5StarsHoldHours); ?>" min="0" max="24" class="small-text"> horas
                                <p class="description">
                                    <strong>Recomendado: 0 horas (instantâneo)</strong><br>
                                    Excelente serviço = repasse imediato para motivar profissional
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <span style="color: #5b9dd9; font-size: 18px;">⭐⭐⭐⭐</span><br>
                                4 Estrelas - Hold:
                            </th>
                            <td>
                                <input type="number" name="payout_4stars_hold" value="<?php echo esc_attr($payout4StarsHoldHours); ?>" min="0" max="24" class="small-text"> horas
                                <p class="description">
                                    <strong>Recomendado: 1 hora</strong><br>
                                    Bom serviço = repasse rápido após pequena janela de verificação
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <span style="color: #f0ad4e; font-size: 18px;">⭐⭐⭐</span><br>
                                3 Estrelas - Hold:
                            </th>
                            <td>
                                <input type="number" name="payout_3stars_hold" value="<?php echo esc_attr($payout3StarsHoldHours); ?>" min="1" max="168" class="small-text"> horas
                                <p class="description">
                                    <strong>Recomendado: 24 horas</strong><br>
                                    Serviço mediano = hold para investigar se houve problema<br>
                                    Após 24h, repasse é liberado AUTOMATICAMENTE (se não houver contestação)
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <span style="color: #dc3232; font-size: 18px;">⭐⭐ ou ⭐</span><br>
                                Menos de 3 Estrelas - Hold:
                            </th>
                            <td>
                                <input type="number" name="payout_below3_hold" value="<?php echo esc_attr($payoutBelow3StarsHoldHours); ?>" min="24" max="720" class="small-text"> horas
                                <p class="description">
                                    <strong>Recomendado: 24 horas + liberação manual do admin</strong><br>
                                    ⚠️ <strong>CRÍTICO:</strong> Avaliação muito baixa indica problema sério<br>
                                    • Repasse RETIDO por este período<br>
                                    • Cliente pode reportar motivo (campo texto)<br>
                                    • <strong>Admin deve analisar caso e liberar manualmente</strong><br>
                                    • Se problema confirmado, repasse pode ser estornado/ajustado
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Permitir Cliente Reportar Motivo:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="allow_client_report" value="1" <?php checked($allowClientReportLowRating); ?>>
                                    Cliente pode descrever motivo de avaliações abaixo de 4 estrelas
                                </label>
                                <p class="description">
                                    <strong>Quando habilitado:</strong><br>
                                    • 📝 Cliente vê campo de texto para explicar por que deu avaliação baixa<br>
                                    • 📧 Admin recebe notificação com motivo reportado<br>
                                    • ⚖️ Informação usada para decidir sobre liberação de repasse<br>
                                    • 🛡️ Protege plataforma de maus serviços
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #f0ad4e; margin-top: 20px;">
                        <h4 style="margin-top: 0;">⚠️ Importante: Fluxo Completo de Repasse</h4>
                        <ol style="margin: 10px 0;">
                            <li><strong>Serviço Concluído:</strong> Profissional marca como "concluído" no sistema</li>
                            <li><strong>Solicitação de Feedback:</strong> Cliente recebe mensagem (WhatsApp/SMS) para avaliar</li>
                            <li><strong>Cliente Avalia:</strong> Dá de 1 a 5 estrelas + (opcional) motivo se <4 estrelas</li>
                            <li><strong>Sistema Processa:</strong> Baseado nas regras acima, inicia timer de hold</li>
                            <li><strong>Liberação Automática ou Manual:</strong>
                                <ul>
                                    <li>5★ ou 4★: Liberado automaticamente após timer</li>
                                    <li>3★: Liberado automaticamente após 24h (se sem contestação)</li>
                                    <li>≤2★: <strong>REQUER LIBERAÇÃO MANUAL DO ADMIN</strong></li>
                                </ul>
                            </li>
                            <li><strong>Repasse Executado:</strong> Valor transferido para conta do profissional</li>
                        </ol>
                        <p style="margin-bottom: 0;"><strong>💡 Dica:</strong> Configure notificações em "Configurações > Comunicação > Fluxos" para alertar admin sobre casos <3 estrelas.</p>
                    </div>
                </div>
            </div>

            <p class="submit">
                <button type="submit" name="limpvix_save_profissionais_settings" class="button button-primary button-large">
                    💾 Salvar Configurações de Profissionais
                </button>
            </p>
        </form>
        <?php
    }

    private function renderTemplatesTab(): void
    {
        // Renderizar página completa de Templates diretamente na aba
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

    private function renderFluxosTab(): void
    {
        // Buscar definições de fluxos
        $fluxos = $this->getFluxosDefinition();

        // Buscar configurações atuais
        $enabledFlows = get_option('limpvix_enabled_flows', [
            'c1' => true,
            'c2' => true,
            'c3' => true,
            'p1' => true,
            'p2' => true,
            'p3' => true,
        ]);

        // Configurações de timing do C1 (três tentativas)
        $c1Timing = get_option('limpvix_c1_timing', [
            'attempt1_hours' => 24,
            'attempt2_hours' => 48,
            'attempt3_hours' => 72,
        ]);
        ?>
        <div class="limpvix-card">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-update"></span>
                    🔄 Gerenciar Fluxos Automáticos
                </h3>
                <p>Configure os fluxos de comunicação automática com clientes e equipe</p>
            </div>
            <div class="limpvix-card-body">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('limpvix_update_flows', 'limpvix_flows_nonce'); ?>
                    <input type="hidden" name="action" value="limpvix_update_flows">

                    <!-- Fluxos de Clientes (C1-C3) -->
                    <h3 style="margin-top: 0;">📱 Fluxos de Clientes</h3>
                    <p>Mensagens automáticas enviadas aos clientes durante o ciclo de serviço</p>

                    <table class="wp-list-table widefat fixed striped" style="margin-bottom: 30px;">
                        <thead>
                            <tr>
                                <th style="width: 100px;">Ativo</th>
                                <th style="width: 80px;">Fluxo</th>
                                <th>Descrição</th>
                                <th style="width: 120px;">Canal</th>
                                <th style="width: 150px;">Trigger</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fluxos['client'] as $flowId => $flow): ?>
                            <tr>
                                <td>
                                    <label class="limpvix-toggle">
                                        <input type="checkbox" name="enabled_flows[<?php echo esc_attr($flowId); ?>]"
                                               value="1" <?php checked(!empty($enabledFlows[$flowId])); ?>>
                                        <span class="limpvix-toggle-slider"></span>
                                    </label>
                                </td>
                                <td><strong><?php echo esc_html(strtoupper($flowId)); ?></strong></td>
                                <td>
                                    <strong><?php echo esc_html($flow['name']); ?></strong><br>
                                    <small><?php echo esc_html($flow['description']); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $channelBadges = [
                                        'whatsapp' => '<span class="limpvix-badge limpvix-badge-success">WhatsApp</span>',
                                        'sms' => '<span class="limpvix-badge limpvix-badge-warning">SMS</span>',
                                    ];
                                    echo $channelBadges[$flow['channel']] ?? '';
                                    ?>
                                </td>
                                <td><small><?php echo esc_html($flow['trigger']); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Configuração Especial C1: Três Tentativas -->
                    <div class="limpvix-card" style="margin-bottom: 30px; background: #f0f8ff;">
                        <div class="limpvix-card-header">
                            <h4>⏰ Configuração de Timing - Fluxo C1 (Tentativas de Contato)</h4>
                        </div>
                        <div class="limpvix-card-body">
                            <p>O fluxo C1 realiza até 3 tentativas de contato com o cliente quando um briefing é recebido:</p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">1ª Tentativa (imediata):</th>
                                    <td>
                                        Após <input type="number" name="c1_timing[attempt1_hours]"
                                                   value="<?php echo esc_attr($c1Timing['attempt1_hours']); ?>"
                                                   min="0" max="48" class="small-text"> horas do briefing
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">2ª Tentativa:</th>
                                    <td>
                                        Após <input type="number" name="c1_timing[attempt2_hours]"
                                                   value="<?php echo esc_attr($c1Timing['attempt2_hours']); ?>"
                                                   min="0" max="96" class="small-text"> horas se sem resposta
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">3ª Tentativa (final):</th>
                                    <td>
                                        Após <input type="number" name="c1_timing[attempt3_hours]"
                                                   value="<?php echo esc_attr($c1Timing['attempt3_hours']); ?>"
                                                   min="0" max="168" class="small-text"> horas se sem resposta
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- Fluxos de Equipe (P1-P3) -->
                    <h3>👷 Fluxos de Equipe (Staff)</h3>
                    <p>Mensagens automáticas para profissionais e coordenadores</p>

                    <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                        <thead>
                            <tr>
                                <th style="width: 100px;">Ativo</th>
                                <th style="width: 80px;">Fluxo</th>
                                <th>Descrição</th>
                                <th style="width: 120px;">Canal</th>
                                <th style="width: 150px;">Trigger</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fluxos['staff'] as $flowId => $flow): ?>
                            <tr>
                                <td>
                                    <label class="limpvix-toggle">
                                        <input type="checkbox" name="enabled_flows[<?php echo esc_attr($flowId); ?>]"
                                               value="1" <?php checked(!empty($enabledFlows[$flowId])); ?>>
                                        <span class="limpvix-toggle-slider"></span>
                                    </label>
                                </td>
                                <td><strong><?php echo esc_html(strtoupper($flowId)); ?></strong></td>
                                <td>
                                    <strong><?php echo esc_html($flow['name']); ?></strong><br>
                                    <small><?php echo esc_html($flow['description']); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $channelBadges = [
                                        'whatsapp' => '<span class="limpvix-badge limpvix-badge-success">WhatsApp</span>',
                                        'sms' => '<span class="limpvix-badge limpvix-badge-warning">SMS</span>',
                                    ];
                                    echo $channelBadges[$flow['channel']] ?? '';
                                    ?>
                                </td>
                                <td><small><?php echo esc_html($flow['trigger']); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            💾 Salvar Configurações de Fluxos
                        </button>
                    </p>
                </form>

                <hr style="margin: 30px 0;">

                <!-- Links rápidos -->
                <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa;">
                    <h4 style="margin-top: 0;">🔗 Links Relacionados</h4>
                    <p>
                        <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=templates'); ?>" class="button">
                            📝 Gerenciar Templates de Mensagens
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=comunicacao'); ?>" class="button">
                            📊 Ver Status de Comunicação
                        </a>
                    </p>
                </div>
            </div>
        </div>

        <style>
            .limpvix-toggle {
                position: relative;
                display: inline-block;
                width: 50px;
                height: 24px;
            }
            .limpvix-toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .limpvix-toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .4s;
                border-radius: 24px;
            }
            .limpvix-toggle-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
            }
            .limpvix-toggle input:checked + .limpvix-toggle-slider {
                background-color: #2271b1;
            }
            .limpvix-toggle input:checked + .limpvix-toggle-slider:before {
                transform: translateX(26px);
            }
        </style>
        <?php
    }

    private function renderPagamentosTab(): void
    {
        ?>
        <!-- Mercado Pago Settings -->
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
        <?php
    }

    private function getM2Table(): array
    {
        $defaults = [
            'bedroom' => 12,
            'bathroom' => 4,
            'living_room' => 20,
            'kitchen' => 10,
            'office' => 10,
            'external_area' => 25
        ];

        return get_option('limpvix_briefing_m2_table', $defaults);
    }

    private function getTimeFactors(): array
    {
        $defaults = [
            'limpeza_pesada' => 0.40,
            'pos_obra' => 0.70,
            'pre_mudanca' => 0.30
        ];

        return get_option('limpvix_briefing_time_factors', $defaults);
    }

    private function handleBriefingSave(): void
    {
        // Salvar tabela m²
        $m2Table = [
            'bedroom' => (float) ($_POST['m2_bedroom'] ?? 12),
            'bathroom' => (float) ($_POST['m2_bathroom'] ?? 4),
            'living_room' => (float) ($_POST['m2_living_room'] ?? 20),
            'kitchen' => (float) ($_POST['m2_kitchen'] ?? 10),
            'office' => (float) ($_POST['m2_office'] ?? 10),
            'external_area' => (float) ($_POST['m2_external_area'] ?? 25)
        ];
        update_option('limpvix_briefing_m2_table', $m2Table);

        // Salvar fatores de tempo
        $timeFactors = [
            'limpeza_pesada' => (float) ($_POST['time_factor_limpeza_pesada'] ?? 0.40),
            'pos_obra' => (float) ($_POST['time_factor_pos_obra'] ?? 0.70),
            'pre_mudanca' => (float) ($_POST['time_factor_pre_mudanca'] ?? 0.30)
        ];
        update_option('limpvix_briefing_time_factors', $timeFactors);

        // Salvar buffer
        update_option('limpvix_briefing_buffer_minutes', (int) ($_POST['buffer_minutes'] ?? 30));

        // Salvar preço por m²
        update_option('limpvix_briefing_price_per_m2', (float) ($_POST['price_per_m2'] ?? 15.00));

        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=briefing&updated=1'));
        exit;
    }

    public function renderOrdersPage(): void {
        $controller = new OrdersListController();
        $controller->render();
    }

    public function renderOrderDetailPage(): void {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }

        $orderUuid = isset($_GET['uuid']) ? sanitize_text_field($_GET['uuid']) : '';

        if (empty($orderUuid)) {
            wp_die('UUID da order não fornecido', 'UUID não fornecido', ['back_link' => true]);
        }

        $controller = new OrderDetailController();
        $controller->render($orderUuid);
    }

    public function renderSyncValidatorPage(): void {
        $controller = new SyncValidatorController();
        $controller->render();
    }

    public function renderPayoutsPage(): void {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }

        $page = new PayoutsPage();
        $page->render();
    }

    /**
     * @deprecated Movido para aba Comunicação em Configurações (renderComunicacaoTab)
     * @see renderComunicacaoTab()
     */
    public function renderCommunicationCenterPage(): void {
        // Redirecionar para nova localização
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=comunicacao'));
        exit;
    }

    /**
     * @deprecated Movido para aba Fluxos em Configurações (renderFluxosTab)
     * @see renderFluxosTab()
     */
    public function renderMessageFlowsPage(): void {
        // Redirecionar para nova localização
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=fluxos'));
        exit;
    }

    /**
     * @deprecated Movido para aba Templates em Configurações (renderTemplatesTab)
     * @see renderTemplatesTab()
     */
    public function renderMessageTemplatesPage(): void {
        // Redirecionar para nova localização
        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=templates'));
        exit;
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

    public function renderBriefingsPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die("Acesso negado");
        }

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\BriefingManagementPage')) {
            $briefingRepository = new \LimpVix\Infrastructure\Persistence\WpBriefingRepository();
            $page = new \LimpVix\Infrastructure\Admin\Pages\BriefingManagementPage($briefingRepository);
            $page->render();
        }
    }

    public function renderFinancialReportPage(): void {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }

        $controller = new \LimpVix\Admin\Controllers\FinancialReportController();
        $controller->render();
    }

    public function deactivate(): void {
        $this->capabilities->unregister();
        delete_transient("limpvix_finance_caps_registered");
    }

    /**
     * Get communication providers status
     *
     * @return array Provider status information
     */
    private function getCommunicationProvidersStatus(): array
    {
        return [
            'twilio' => [
                'name' => 'Twilio SMS',
                'channel' => 'sms',
                'enabled' => !empty(get_option('limpvix_twilio_account_sid')),
                'configured' => !empty(get_option('limpvix_twilio_account_sid')) &&
                               !empty(get_option('limpvix_twilio_auth_token')),
                'status' => 'active', // TODO: Check real API connection
            ],
            '360dialog' => [
                'name' => '360Dialog WhatsApp',
                'channel' => 'whatsapp',
                'enabled' => !empty(get_option('limpvix_360dialog_api_key')),
                'configured' => !empty(get_option('limpvix_360dialog_api_key')),
                'status' => 'active', // TODO: Check real API connection
            ],
            'system' => [
                'name' => 'Sistema de Comunicação',
                'channel' => 'system',
                'enabled' => true,
                'configured' => true,
                'status' => 'active',
                'info' => 'Hub central de mensagens e fluxos automáticos',
            ],
        ];
    }

    /**
     * Get flows definition (C1-C3 client flows, P1-P3 staff flows)
     *
     * @return array Flow definitions by category
     */
    private function getFluxosDefinition(): array
    {
        return [
            'client' => [
                'c1' => [
                    'name' => 'C1 - Tentativa de Contato',
                    'description' => 'Até 3 tentativas de contato após receber briefing',
                    'channel' => 'whatsapp',
                    'trigger' => 'Briefing recebido',
                ],
                'c2' => [
                    'name' => 'C2 - Confirmação de Agendamento',
                    'description' => 'Confirmação enviada 24h antes do serviço',
                    'channel' => 'whatsapp',
                    'trigger' => '24h antes',
                ],
                'c3' => [
                    'name' => 'C3 - Feedback Pós-Serviço',
                    'description' => 'Solicitação de feedback após conclusão',
                    'channel' => 'whatsapp',
                    'trigger' => 'Serviço concluído',
                ],
            ],
            'staff' => [
                'p1' => [
                    'name' => 'P1 - Oferta de Serviço',
                    'description' => 'Notificação de nova oferta para profissional',
                    'channel' => 'whatsapp',
                    'trigger' => 'Briefing aceito',
                ],
                'p2' => [
                    'name' => 'P2 - Lembrete Pré-Serviço',
                    'description' => 'Lembrete enviado 2h antes do serviço',
                    'channel' => 'sms',
                    'trigger' => '2h antes',
                ],
                'p3' => [
                    'name' => 'P3 - Alerta de Atraso',
                    'description' => 'Notificação para coordenador se profissional não check-in',
                    'channel' => 'whatsapp',
                    'trigger' => '15min após início',
                ],
            ],
        ];
    }

    /**
     * Handle flows configuration update
     */
    public function handleUpdateFlows(): void
    {
        // Verify nonce
        if (!isset($_POST['limpvix_flows_nonce']) ||
            !wp_verify_nonce($_POST['limpvix_flows_nonce'], 'limpvix_update_flows')) {
            wp_die('Erro de segurança. Por favor, tente novamente.');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Você não tem permissão para realizar esta ação.');
        }

        // Update enabled flows
        $enabledFlows = [];
        if (isset($_POST['enabled_flows']) && is_array($_POST['enabled_flows'])) {
            foreach ($_POST['enabled_flows'] as $flowId => $value) {
                $enabledFlows[sanitize_key($flowId)] = (bool) $value;
            }
        }
        update_option('limpvix_enabled_flows', $enabledFlows);

        // Update C1 timing configuration
        if (isset($_POST['c1_timing']) && is_array($_POST['c1_timing'])) {
            $c1Timing = [
                'attempt1_hours' => (int) ($_POST['c1_timing']['attempt1_hours'] ?? 24),
                'attempt2_hours' => (int) ($_POST['c1_timing']['attempt2_hours'] ?? 48),
                'attempt3_hours' => (int) ($_POST['c1_timing']['attempt3_hours'] ?? 72),
            ];
            update_option('limpvix_c1_timing', $c1Timing);
        }

        // Redirect back with success message
        wp_redirect(add_query_arg([
            'page' => 'limpvix-settings',
            'tab' => 'fluxos',
            'updated' => 'true',
        ], admin_url('admin.php')));
        exit;
    }
}
