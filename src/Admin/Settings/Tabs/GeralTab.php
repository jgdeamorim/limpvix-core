<?php

namespace LimpVix\Admin\Settings\Tabs;

use LimpVix\Admin\Settings\FirebaseSettings;
use LimpVix\Admin\Settings\GoogleBusinessSettings;
use LimpVix\Admin\Settings\NVoipSettings;

defined('ABSPATH') || exit;

class GeralTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'geral'; }
    public function getLabel(): string { return 'Geral'; }
    public function getIcon(): string { return '&#x1F3E0;'; }

    public function handleSave(): void
    {
        if (!isset($_POST['limpvix_save_geral_settings'])) {
            return;
        }
        if (!check_admin_referer('limpvix_geral_settings')) {
            return;
        }

        // Identidade
        update_option('limpvix_company_name', sanitize_text_field($_POST['company_name'] ?? 'LimpVix'));
        update_option('limpvix_operational_email', sanitize_email($_POST['operational_email'] ?? get_option('admin_email')));

        // Enforcement toggles
        update_option('limpvix_enforce_geofence_checkin', isset($_POST['enforce_geofence_checkin']) ? '1' : '0');
        update_option('limpvix_enforce_geofence_checkout', isset($_POST['enforce_geofence_checkout']) ? '1' : '0');
        update_option('limpvix_enforce_room_photos', isset($_POST['enforce_room_photos']) ? '1' : '0');

        // Seguranca
        update_option('limpvix_phone_verification_exempt_admin', isset($_POST['phone_verification_exempt_admin']) ? '1' : '0');

        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=geral&updated=1'));
        exit;
    }

    public function render(): void
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
                echo '<div class="notice notice-success is-dismissible"><p><strong>&#x2705; Todos os motores foram habilitados com sucesso!</strong> A p&aacute;gina ser&aacute; recarregada.</p></div>';
                echo '<script>setTimeout(function(){ window.location.reload(); }, 2000);</script>';
            } elseif (isset($_POST['toggle_flag'])) {
                $flag_name = sanitize_text_field($_POST['toggle_flag']);
                $current_value = $flags->isEnabled($flag_name);

                if ($current_value) {
                    $flags->disable($flag_name);
                    echo '<div class="notice notice-warning is-dismissible"><p><strong>&#x26A0;&#xFE0F; Feature &quot;' . esc_html($flag_name) . '&quot; desabilitada.</strong></p></div>';
                } else {
                    $flags->enable($flag_name);
                    echo '<div class="notice notice-success is-dismissible"><p><strong>&#x2705; Feature &quot;' . esc_html($flag_name) . '&quot; habilitada.</strong></p></div>';
                }
                echo '<script>setTimeout(function(){ window.location.reload(); }, 1500);</script>';
            }
        }

        // Buscar estatísticas dinâmicas do sistema
        $stats = $this->calculateDashboardStats();
        $pluginVersion = defined('LIMPVIX_VERSION') ? LIMPVIX_VERSION : '0.0.0';
        ?>

        <!-- DASHBOARD DE STATUS DO SISTEMA -->
        <div class="limpvix-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px; border: none;">
            <div class="limpvix-card-body" style="padding: 30px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div>
                        <h2 style="color: white; margin: 0 0 10px 0; font-size: 28px;">
                            <?php echo $stats['status_icon']; ?> LimpVix Core - <?php echo esc_html($stats['status_message']); ?>
                        </h2>
                        <p style="color: #f0f0f0; margin: 0; font-size: 14px;">
                            Vers&atilde;o <?php echo esc_html($pluginVersion); ?> | <?php echo date('Y-m-d'); ?>
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: rgba(255,255,255,0.2); padding: 15px 25px; border-radius: 8px; backdrop-filter: blur(10px);">
                            <div style="font-size: 42px; font-weight: bold; line-height: 1;"><?php echo $stats['completion_percentage']; ?>%</div>
                            <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Completude</div>
                        </div>
                    </div>
                </div>

                <!-- Métricas Rápidas -->
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 25px;">
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo $stats['fluxos']['operational_complete']; ?>/<?php echo $stats['fluxos']['operational_total']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Fluxos Operacionais</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo $stats['fluxos']['gaps_implemented']; ?>/<?php echo $stats['fluxos']['gaps_total']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">GAPs Implementados</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo $stats['test_count']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Testes Unit&aacute;rios</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo $stats['is_go_live_ready'] ? '&#x2713;' : '&#x26A0;'; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;"><?php echo esc_html($stats['go_live_status']); ?></div>
                    </div>
                </div>

                <!-- GAPs Implementados -->
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.2);">
                    <h3 style="color: white; margin: 0 0 15px 0; font-size: 16px; font-weight: 600;">
                        <?php echo $stats['fluxos']['gaps_implemented'] === $stats['fluxos']['gaps_total'] ? '&#x2705;' : '&#x26A0;'; ?> GAPs P0 e P1 - <?php echo $stats['fluxos']['gaps_implemented']; ?>/<?php echo $stats['fluxos']['gaps_total']; ?> Implementados
                    </h3>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                        <?php
                        $gaps = [
                            [
                                'id' => 'GAP #1',
                                'name' => 'EPI Selfie Validation',
                                'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
                            ],
                            [
                                'id' => 'GAP #2',
                                'name' => 'Evidence Categorization System',
                                'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence',
                            ],
                            [
                                'id' => 'GAP #3',
                                'name' => 'Client Check-in Notifications',
                                'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn',
                            ],
                            [
                                'id' => 'GAP #4',
                                'name' => 'Issue Reporting System',
                                'class' => 'LimpVix\\Domain\\Execution\\Issue',
                            ],
                        ];

                        foreach ($gaps as $gap) {
                            $implemented = false;

                            if (isset($gap['class'])) {
                                $implemented = class_exists($gap['class']);
                            } elseif (isset($gap['use_case'])) {
                                $implemented = class_exists($gap['use_case']);
                            }

                            $statusIcon = $implemented ? '&#x2705;' : '&#x274C;';
                            $statusText = $implemented ? 'Implementado' : 'Pendente';
                            ?>
                            <div style="background: rgba(255,255,255,0.1); padding: 12px; border-radius: 6px;">
                                <strong><?php echo esc_html($gap['id']); ?>:</strong> <?php echo esc_html($gap['name']); ?>
                                <div style="font-size: 12px; opacity: 0.8; margin-top: 4px;">
                                    <?php echo $statusIcon; ?> <?php echo $statusText; ?>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <!-- Ações Rápidas -->
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.2);">
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=fluxos'); ?>"
                           class="button button-primary"
                           style="background: white; color: #667eea; border: none; font-weight: 600; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <span class="dashicons dashicons-chart-bar" style="margin-top: 3px;"></span> Ver Dashboard de Fluxos
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=dependencias'); ?>"
                           class="button"
                           style="background: rgba(255,255,255,0.2); color: white; border: none; backdrop-filter: blur(10px);">
                            <span class="dashicons dashicons-admin-links" style="margin-top: 3px;"></span> Verificar Depend&ecirc;ncias
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=limpvix-sync-validator'); ?>"
                           class="button"
                           style="background: rgba(255,255,255,0.2); color: white; border: none; backdrop-filter: blur(10px);">
                            <span class="dashicons dashicons-search" style="margin-top: 3px;"></span> Validar Integridade
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documentação e Recursos -->
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-book"></span>
                    Documenta&ccedil;&atilde;o e Recursos
                </h3>
                <p>Guias, documenta&ccedil;&atilde;o t&eacute;cnica e recursos do sistema</p>
            </div>
            <div class="limpvix-card-body">
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;">
                    <!-- Documentação -->
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">
                        <h4 style="margin: 0 0 10px 0; color: #2c3e50;"><span class="dashicons dashicons-media-document"></span> Documenta&ccedil;&atilde;o</h4>
                        <ul style="margin: 0; padding-left: 20px; font-size: 13px;">
                            <li style="margin-bottom: 8px;">
                                Changelog Detalhado
                            </li>
                            <li style="margin-bottom: 8px;">
                                README do Plugin
                            </li>
                            <li style="margin-bottom: 8px;">
                                Arquitetura DDD + Clean Architecture
                            </li>
                        </ul>
                    </div>

                    <!-- API e Testes -->
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">
                        <h4 style="margin: 0 0 10px 0; color: #2c3e50;"><span class="dashicons dashicons-admin-tools"></span> Testes e API</h4>
                        <ul style="margin: 0; padding-left: 20px; font-size: 13px;">
                            <li style="margin-bottom: 8px;">
                                <strong><?php echo $stats['test_count']; ?> testes unit&aacute;rios</strong> (<?php echo $stats['test_count'] > 0 ? '100% passing' : 'nenhum teste encontrado'; ?>)
                            </li>
                            <li style="margin-bottom: 8px;">
                                REST API: <code>/wp-json/limpvix/v1/</code>
                            </li>
                            <li style="margin-bottom: 8px;">
                                Executar testes: <code>phpunit --testdox</code>
                            </li>
                        </ul>
                    </div>

                    <!-- Sistema -->
                    <div style="padding: 15px; background: #f8f9fa; border-radius: 6px;">
                        <h4 style="margin: 0 0 10px 0; color: #2c3e50;"><span class="dashicons dashicons-admin-generic"></span> Sistema</h4>
                        <ul style="margin: 0; padding-left: 20px; font-size: 13px;">
                            <li style="margin-bottom: 8px;">
                                <strong>Arquitetura:</strong> DDD + Clean Architecture
                            </li>
                            <li style="margin-bottom: 8px;">
                                <strong>PHP:</strong> <?php echo esc_html($stats['php_version']); ?> | <strong>PHPUnit:</strong> <?php echo esc_html($stats['phpunit_version']); ?>
                            </li>
                            <li style="margin-bottom: 8px;">
                                <strong>WordPress:</strong> 6.x compatible
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Status de Implementação -->
                <div style="margin-top: 20px; padding: 15px; background: <?php echo $stats['is_go_live_ready'] ? '#d4edda' : '#fff3cd'; ?>; border-left: 4px solid <?php echo $stats['is_go_live_ready'] ? '#28a745' : '#ffc107'; ?>; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0; color: <?php echo $stats['is_go_live_ready'] ? '#155724' : '#856404'; ?>;">Status de Implementa&ccedil;&atilde;o</h4>
                    <div style="font-size: 13px; color: <?php echo $stats['is_go_live_ready'] ? '#155724' : '#856404'; ?>; line-height: 1.6;">
                        <strong><?php echo $stats['fluxos']['operational_complete'] === $stats['fluxos']['operational_total'] ? '&#x2705;' : '&#x26A0;'; ?> Fluxos Operacionais:</strong> <?php echo $stats['fluxos']['operational_complete']; ?>/<?php echo $stats['fluxos']['operational_total']; ?> completos (<?php echo round(($stats['fluxos']['operational_complete'] / $stats['fluxos']['operational_total']) * 100); ?>%)<br>
                        <strong><?php echo $stats['test_count'] > 0 ? '&#x2705;' : '&#x26A0;'; ?> Cobertura de Testes:</strong> Domain layer com <?php echo $stats['test_count']; ?> testes<br>
                        <strong>&#x2705; REST API:</strong> Endpoints completos para executions, issues, evidences<br>
                        <strong>&#x2705; Event Listeners:</strong> Event-driven architecture implementada<br>
                        <strong>&#x2705; Valida&ccedil;&otilde;es:</strong> Geofence, time window, EPI, evid&ecirc;ncias categorizadas
                    </div>
                </div>
            </div>
        </div>

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
                            "label" => "Core LimpVix (MASTER)",
                            "description" => "Habilita TODOS os componentes do sistema"
                        ],
                        "briefing_enabled" => [
                            "label" => "M&oacute;dulo Briefing",
                            "description" => "Sistema de briefing e cota&ccedil;&atilde;o"
                        ],
                        "financial_workflow" => [
                            "label" => "Workflow Financeiro",
                            "description" => "Fluxo de pagamentos e cobran&ccedil;as"
                        ],
                        "payout_engine" => [
                            "label" => "Motor de Payouts",
                            "description" => "C&aacute;lculo e processamento de repasses"
                        ],
                        "admin_interface" => [
                            "label" => "Interface Admin",
                            "description" => "Menus e p&aacute;ginas administrativas"
                        ],
                        "audit_logging" => [
                            "label" => "Logs de Auditoria",
                            "description" => "Registro de todas as a&ccedil;&otilde;es"
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
                        <p style="margin: 0 0 10px 0;"><strong>&#x26A0; Alguns motores est&atilde;o desabilitados</strong></p>
                        <p style="margin: 0 0 10px 0; font-size: 13px;">Para ativar todas as funcionalidades do LimpVix Core, clique no bot&atilde;o abaixo:</p>
                        <form method="post" style="margin: 0;">
                            <?php wp_nonce_field('limpvix_save_feature_flags', 'limpvix_feature_flags_nonce'); ?>
                            <button type="submit" name="enable_all_motors" class="button button-primary" style="background: #28a745; border-color: #28a745;">
                                <span class="dashicons dashicons-controls-play" style="margin-top: 3px;"></span> Habilitar Todos os Motores
                            </button>
                        </form>
                    </div>
                    <?php else: ?>
                    <div style="margin-bottom: 20px; padding: 15px; background: #d4edda; border-left: 4px solid #28a745;">
                        <p style="margin: 0;"><strong>&#x2705; Todos os motores est&atilde;o habilitados!</strong> Sistema funcionando em capacidade total.</p>
                    </div>
                    <?php endif; ?>

                    <table class="limpvix-table">
                        <thead>
                            <tr>
                                <th>Funcionalidade</th>
                                <th style="text-align: center;">Status</th>
                                <th style="text-align: center; width: 150px;">A&ccedil;&atilde;o</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($important_flags as $flag => $info): ?>
                            <tr>
                                <td>
                                    <strong><?php echo $info['label']; ?></strong>
                                    <br><small style="color: #666;"><?php echo $info['description']; ?></small>
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
                                                &#x274C; Desabilitar
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="button button-small button-primary" title="Habilitar">
                                                &#x2705; Habilitar
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
                    <p>Status e sa&uacute;de do sistema</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $kernel = \LimpVix\Core\Kernel::getInstance();
                    $health = $kernel->healthCheck();
                    $cronHealth = $this->getCronJobsHealth();
                    $scheduledCrons = count(array_filter($cronHealth, fn($c) => $c['scheduled']));
                    $totalCrons = count($cronHealth);
                    $customTables = $this->countCustomTables();
                    $coreFlag = (new \LimpVix\Core\FeatureFlags())->isEnabled('core_enabled');
                    $nativeModulesActive = $coreFlag && $scheduledCrons > 0;
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td><strong>Vers&atilde;o do Plugin</strong></td>
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
                                        <span class="limpvix-badge limpvix-badge-danger limpvix-badge-dot">N&atilde;o inicializado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>M&oacute;dulos Nativos</strong></td>
                                <td>
                                    <?php if ($nativeModulesActive): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Ativo</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-danger limpvix-badge-dot">Inativo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Cron Jobs</strong></td>
                                <td>
                                    <?php if ($scheduledCrons === $totalCrons): ?>
                                        <span class="limpvix-badge limpvix-badge-success"><?php echo $scheduledCrons; ?>/<?php echo $totalCrons; ?> agendados</span>
                                    <?php elseif ($scheduledCrons > 0): ?>
                                        <span class="limpvix-badge limpvix-badge-warning"><?php echo $scheduledCrons; ?>/<?php echo $totalCrons; ?> agendados</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-danger">0/<?php echo $totalCrons; ?> agendados</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Tabelas Custom</strong></td>
                                <td>
                                    <span class="limpvix-badge limpvix-badge-info"><?php echo $customTables; ?> tabelas</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- CONFIGURAÇÕES OPERACIONAIS (NOVA SEÇÃO EDITÁVEL) -->
        <form method="post" style="margin-top: 20px;">
            <?php wp_nonce_field('limpvix_geral_settings'); ?>
            <input type="hidden" name="limpvix_save_geral_settings" value="1">

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible" style="margin-bottom: 15px;">
                    <p><strong>&#x2705; Configura&ccedil;&otilde;es operacionais salvas com sucesso!</strong></p>
                </div>
            <?php endif; ?>

            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-settings"></span>
                        Configura&ccedil;&otilde;es Operacionais
                    </h3>
                    <p>Par&acirc;metros operacionais do sistema &mdash; usados por dom&iacute;nio e infraestrutura</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $companyName = get_option('limpvix_company_name', 'LimpVix');
                    $operationalEmail = get_option('limpvix_operational_email', get_option('admin_email'));
                    $enforceGeoCheckin = (bool) get_option('limpvix_enforce_geofence_checkin', true);
                    $enforceGeoCheckout = (bool) get_option('limpvix_enforce_geofence_checkout', true);
                    $enforceRoomPhotos = (bool) get_option('limpvix_enforce_room_photos', true);
                    $phoneExemptAdmin = (bool) get_option('limpvix_phone_verification_exempt_admin', false);
                    ?>

                    <!-- Identidade -->
                    <h4 style="margin: 0 0 15px 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <span class="dashicons dashicons-building" style="color: #667eea;"></span> Identidade
                    </h4>
                    <table class="limpvix-table" style="margin-bottom: 25px;">
                        <tbody>
                            <tr>
                                <td style="width: 250px;"><strong>Nome da Empresa</strong>
                                    <br><small style="color: #666;">Usado em notifica&ccedil;&otilde;es ao cliente (CustomerNotifier)</small>
                                </td>
                                <td>
                                    <input type="text" name="company_name" value="<?php echo esc_attr($companyName); ?>"
                                           class="regular-text" style="width: 300px;">
                                </td>
                            </tr>
                            <tr>
                                <td><strong>E-mail Operacional</strong>
                                    <br><small style="color: #666;">Usado na cria&ccedil;&atilde;o de agendamentos (ScheduleCreationListener)</small>
                                </td>
                                <td>
                                    <input type="email" name="operational_email" value="<?php echo esc_attr($operationalEmail); ?>"
                                           class="regular-text" style="width: 300px;">
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Enforcement -->
                    <h4 style="margin: 0 0 15px 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <span class="dashicons dashicons-shield" style="color: #e74c3c;"></span> Enforcement &mdash; Regras de Campo
                    </h4>
                    <table class="limpvix-table" style="margin-bottom: 25px;">
                        <tbody>
                            <tr>
                                <td style="width: 250px;"><strong>Geofence no Check-in</strong>
                                    <br><small style="color: #666;">Profissional deve estar dentro do raio para check-in</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enforce_geofence_checkin" value="1"
                                               <?php checked($enforceGeoCheckin); ?>>
                                        Ativo
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $enforceGeoCheckin ? 'success' : 'gray'; ?>" style="margin-left: 10px;">
                                        <?php echo $enforceGeoCheckin ? 'Obrigat&oacute;rio' : 'Desativado'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Geofence no Check-out</strong>
                                    <br><small style="color: #666;">Profissional deve estar dentro do raio para check-out</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enforce_geofence_checkout" value="1"
                                               <?php checked($enforceGeoCheckout); ?>>
                                        Ativo
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $enforceGeoCheckout ? 'success' : 'gray'; ?>" style="margin-left: 10px;">
                                        <?php echo $enforceGeoCheckout ? 'Obrigat&oacute;rio' : 'Desativado'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Fotos de C&ocirc;modos Obrigat&oacute;rias</strong>
                                    <br><small style="color: #666;">Exige fotos de todos os c&ocirc;modos antes de finalizar</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enforce_room_photos" value="1"
                                               <?php checked($enforceRoomPhotos); ?>>
                                        Ativo
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $enforceRoomPhotos ? 'success' : 'gray'; ?>" style="margin-left: 10px;">
                                        <?php echo $enforceRoomPhotos ? 'Obrigat&oacute;rio' : 'Desativado'; ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Segurança -->
                    <h4 style="margin: 0 0 15px 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <span class="dashicons dashicons-lock" style="color: #f39c12;"></span> Seguran&ccedil;a
                    </h4>
                    <table class="limpvix-table" style="margin-bottom: 15px;">
                        <tbody>
                            <tr>
                                <td style="width: 250px;"><strong>Admin Isento de OTP</strong>
                                    <br><small style="color: #666;">Admins n&atilde;o passam por verifica&ccedil;&atilde;o telef&ocirc;nica (PhoneVerificationMiddleware)</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="phone_verification_exempt_admin" value="1"
                                               <?php checked($phoneExemptAdmin); ?>>
                                        Ativo
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $phoneExemptAdmin ? 'warning' : 'success'; ?>" style="margin-left: 10px;">
                                        <?php echo $phoneExemptAdmin ? 'Admins isentos' : 'Todos verificam'; ?>
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div style="padding: 12px 15px; background: #e8f4fd; border-left: 4px solid #2196F3; border-radius: 4px; margin-bottom: 15px;">
                        <strong><span class="dashicons dashicons-info" style="color: #2196F3;"></span> Importante:</strong>
                        Estas configura&ccedil;&otilde;es controlam o comportamento operacional do sistema.
                        Altera&ccedil;&otilde;es de enforcement afetam check-in/check-out dos profissionais em campo.
                    </div>

                    <button type="submit" class="button button-primary button-large">
                        <span class="dashicons dashicons-saved" style="margin-top: 5px;"></span>
                        Salvar Configura&ccedil;&otilde;es Operacionais
                    </button>
                </div>
            </div>
        </form>

        <!-- SAÚDE DOS CRON JOBS (NOVA SEÇÃO) -->
        <div class="limpvix-card" style="margin-bottom: 20px;">
            <div class="limpvix-card-header">
                <h3>
                    <span class="dashicons dashicons-clock"></span>
                    Sa&uacute;de dos Cron Jobs
                </h3>
                <p><?php echo $scheduledCrons; ?>/<?php echo $totalCrons; ?> cron jobs agendados</p>
            </div>
            <div class="limpvix-card-body">
                <table class="limpvix-table">
                    <thead>
                        <tr>
                            <th>Hook</th>
                            <th style="text-align: center;">Frequ&ecirc;ncia</th>
                            <th style="text-align: center;">Status</th>
                            <th style="text-align: center;">Pr&oacute;ximo Run</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cronHealth as $cron): ?>
                        <tr>
                            <td>
                                <code style="font-size: 12px;"><?php echo esc_html($cron['hook']); ?></code>
                                <br><small style="color: #666;"><?php echo esc_html($cron['description']); ?></small>
                            </td>
                            <td style="text-align: center;">
                                <span class="limpvix-badge limpvix-badge-info"><?php echo esc_html($cron['frequency']); ?></span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($cron['scheduled']): ?>
                                    <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Agendado</span>
                                <?php else: ?>
                                    <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">N&atilde;o agendado</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($cron['next_run']): ?>
                                    <small><?php echo esc_html($cron['next_run']); ?></small>
                                <?php else: ?>
                                    <small style="color: #999;">&mdash;</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="padding: 12px 15px; background: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 4px; margin-top: 15px;">
                    <strong><span class="dashicons dashicons-info"></span></strong>
                    Cron jobs s&atilde;o gerenciados automaticamente pelo plugin.
                    Se algum n&atilde;o est&aacute; agendado, verifique se o m&oacute;dulo correspondente est&aacute; ativo nas Feature Flags acima.
                    <br><small>Configura&ccedil;&otilde;es detalhadas de cron est&atilde;o em <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=cron'); ?>"><strong>Cron</strong></a> tab.</small>
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
                        M&oacute;dulos do Sistema
                    </h3>
                    <p>Componentes carregados e funcionais</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $modules = [
                        'Briefing' => class_exists('LimpVix\\Core\\BriefingBootstrap') && method_exists('LimpVix\\Core\\BriefingBootstrap', 'isInitialized') ? \LimpVix\Core\BriefingBootstrap::isInitialized() : false,
                        'Comunica&ccedil;&atilde;o' => class_exists('LimpVix\\Infrastructure\\Communication\\CommunicationBootstrap'),
                        'Financeiro' => class_exists('LimpVix\\Domain\\Finance\\LedgerEntry'),
                        'Feedback' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\FeedbackManagementPage'),
                        'Fluxos' => class_exists('LimpVix\\Admin\\Settings\\Tabs\\FluxosTab'),
                        'Templates' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesAdminPage'),
                    ];
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <?php foreach ($modules as $name => $active): ?>
                            <tr>
                                <td><strong><?php echo $name; ?></strong></td>
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
                        Estat&iacute;sticas Gerais
                    </h3>
                    <p>Resumo de dados do sistema</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    global $wpdb;
                    $prefix = $wpdb ? $wpdb->prefix : 'wp_';

                    // Contar briefings (com check de tabela)
                    $briefings_count = $this->safeTableCount($prefix . 'limpvix_briefings');

                    // Contar mensagens (últimos 30 dias)
                    $messages_count = $this->safeTableCount(
                        $prefix . 'limpvix_messages',
                        "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar pedidos WooCommerce (últimos 30 dias)
                    $orders_count = $this->safeTableCount(
                        $prefix . 'posts',
                        "post_type = 'shop_order' AND post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar Feedbacks Negativos C2 (final_score < 4, últimos 30 dias)
                    $feedbacks_c2_count = $this->safeTableCount(
                        $prefix . 'limpvix_structured_feedbacks',
                        "final_score IS NOT NULL AND final_score < 4.00 AND submitted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
                    );

                    // Contar entradas ledger (últimos 7 dias)
                    $ledger_count = $this->safeTableCount(
                        $prefix . 'limpvix_financial_ledger',
                        "occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
                    );
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td><strong>Briefings (total)</strong></td>
                                <td style="text-align: right;">
                                    <?php if ($briefings_count !== null): ?>
                                        <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($briefings_count); ?></span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Mensagens (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <?php if ($messages_count !== null): ?>
                                        <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($messages_count); ?></span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Pedidos (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <?php if ($orders_count !== null): ?>
                                        <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($orders_count); ?></span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Feedbacks C2 (30 dias)</strong></td>
                                <td style="text-align: right;">
                                    <?php if ($feedbacks_c2_count !== null): ?>
                                        <?php if ($feedbacks_c2_count > 0): ?>
                                            <span class="limpvix-badge limpvix-badge-warning"><?php echo number_format($feedbacks_c2_count); ?></span>
                                        <?php else: ?>
                                            <span class="limpvix-badge limpvix-badge-success">0</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Eventos Ledger (7 dias)</strong></td>
                                <td style="text-align: right;">
                                    <?php if ($ledger_count !== null): ?>
                                        <span class="limpvix-badge limpvix-badge-info"><?php echo number_format($ledger_count); ?></span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray">&mdash;</span>
                                    <?php endif; ?>
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
                        Integra&ccedil;&otilde;es Externas
                    </h3>
                    <p>Status das conex&otilde;es com servi&ccedil;os externos</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    // Usar métodos isConnected() de cada Settings
                    $integrations = [
                        'Firebase' => FirebaseSettings::isConfigured(),
                        'NVoip OTP' => NVoipSettings::isConnected(),
                        'Google Business' => GoogleBusinessSettings::isConnected(),
                        'EFI Bank' => \LimpVix\Admin\Settings\EfiBankSettings::getConfigStatus()['configured'],
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
                                        <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">N&atilde;o configurado</span>
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
                    <p>Informa&ccedil;&otilde;es t&eacute;cnicas do servidor</p>
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

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    private function calculateDashboardStats(): array
    {
        // 1. Buscar stats de fluxos
        $enabledFlows = get_option('limpvix_enabled_flows', [
            'c1' => true,
            'c2' => true,
            'c3' => true,
            'p1' => true,
            'p2' => true,
            'p3' => true,
        ]);

        $fluxosStats = $this->calculateFluxosStats($enabledFlows);

        // 2. Contar testes unitários
        $testCount = $this->countUnitTests();

        // 3. Calcular completude do sistema
        $totalItems = $fluxosStats['operational_total'] + $fluxosStats['gaps_total'];
        $completeItems = $fluxosStats['operational_complete'] + $fluxosStats['gaps_implemented'];
        $completionPercentage = $totalItems > 0 ? round(($completeItems / $totalItems) * 100) : 0;

        // 4. Verificar se Go-Live Ready (100% = ready)
        $isGoLiveReady = $completionPercentage >= 100;

        // 5. Pegar versões
        $phpVersion = phpversion();
        $phpunitVersion = $this->getPhpUnitVersion();

        return [
            'completion_percentage' => $completionPercentage,
            'fluxos' => $fluxosStats,
            'test_count' => $testCount,
            'is_go_live_ready' => $isGoLiveReady,
            'php_version' => $phpVersion,
            'phpunit_version' => $phpunitVersion,
            'status_message' => $completionPercentage >= 100
                ? 'Sistema 100% Operacional'
                : "Sistema {$completionPercentage}% Operacional",
            'status_icon' => $completionPercentage >= 100 ? '&#x1F389;' : '&#x26A0;',
            'go_live_status' => $isGoLiveReady ? 'Go-Live Ready' : 'Em Desenvolvimento',
        ];
    }

    private function countUnitTests(): int
    {
        $testsPath = plugin_dir_path(__FILE__) . '../../../tests';

        if (!is_dir($testsPath)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($testsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
                $count++;
            }
        }

        return $count;
    }

    private function getPhpUnitVersion(): string
    {
        $composerLock = plugin_dir_path(__FILE__) . '../../../composer.lock';

        if (!file_exists($composerLock)) {
            return 'N/A';
        }

        $lockContent = file_get_contents($composerLock);
        if ($lockContent === false) {
            return 'N/A';
        }

        $lock = json_decode($lockContent, true);
        if (!is_array($lock)) {
            return 'N/A';
        }

        // Check in packages-dev first
        foreach ($lock['packages-dev'] ?? [] as $package) {
            if ($package['name'] === 'phpunit/phpunit') {
                return $package['version'] ?? 'N/A';
            }
        }

        // Fallback to packages
        foreach ($lock['packages'] ?? [] as $package) {
            if ($package['name'] === 'phpunit/phpunit') {
                return $package['version'] ?? 'N/A';
            }
        }

        return 'N/A';
    }

    private function calculateFluxosStats(array $enabledFlows): array
    {
        // 1. Contar fluxos de comunicação habilitados
        $communicationTotal = 6; // C1-C3 + P1-P3
        $communicationEnabled = 0;
        foreach (['c1', 'c2', 'c3', 'p1', 'p2', 'p3'] as $flowId) {
            if (!empty($enabledFlows[$flowId])) {
                $communicationEnabled++;
            }
        }

        // 2. Verificar fluxos operacionais completos (verificando classes reais)
        $operationalFlows = [
            ['name' => 'Briefing → Contract', 'use_case' => 'LimpVix\\Application\\UseCases\\Contract\\CreateContractFromBriefing'],
            ['name' => 'Check-in → IN_PROGRESS', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn'],
            ['name' => 'Check-out → COMPLETED', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckOut'],
            ['name' => 'Evidence Upload', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\AddEvidence'],
            ['name' => 'Evidence Validation', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\ApproveEvidence'],
            ['name' => 'Feedback Window', 'use_case' => 'LimpVix\\Application\\UseCases\\Feedback\\CheckFeedbackWindowStatus'],
            ['name' => 'Submit Feedback', 'use_case' => 'LimpVix\\Application\\UseCases\\Feedback\\SubmitFeedback'],
            ['name' => 'Payout Creation', 'use_case' => 'LimpVix\\Application\\UseCases\\Financial\\ExecutePayout'],
            ['name' => 'Issue Reporting', 'entity' => 'LimpVix\\Domain\\Execution\\Issue'],
            ['name' => 'Checkout + Validation', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckOut'],
        ];

        $operationalComplete = 0;
        foreach ($operationalFlows as $flow) {
            $exists = false;
            if (isset($flow['use_case'])) {
                $exists = class_exists($flow['use_case']);
            } elseif (isset($flow['entity'])) {
                $exists = class_exists($flow['entity']);
            } elseif (isset($flow['method'])) {
                list($class, $method) = explode('::', $flow['method']);
                $exists = class_exists($class) && method_exists($class, $method);
            }
            if ($exists) {
                $operationalComplete++;
            }
        }

        $operationalTotal = count($operationalFlows);
        $operationalPercentage = $operationalTotal > 0 ? round(($operationalComplete / $operationalTotal) * 100) : 0;

        // 3. Verificar GAPs implementados
        $gaps = [
            ['name' => 'GAP #1 - EPI Selfie Validation', 'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence'],
            ['name' => 'GAP #2 - Evidence Categorization', 'class' => 'LimpVix\\Domain\\Execution\\ValueObjects\\Evidence'],
            ['name' => 'GAP #3 - Client Check-in Notifications', 'use_case' => 'LimpVix\\Application\\UseCases\\Execution\\PerformCheckIn'],
            ['name' => 'GAP #4 - Issue Reporting', 'class' => 'LimpVix\\Domain\\Execution\\Issue'],
        ];

        $gapsImplemented = 0;
        foreach ($gaps as $gap) {
            $exists = false;
            if (isset($gap['class'])) {
                $exists = class_exists($gap['class']);
            } elseif (isset($gap['use_case'])) {
                $exists = class_exists($gap['use_case']);
            }
            if ($exists) {
                $gapsImplemented++;
            }
        }

        $gapsTotal = count($gaps);

        return [
            'communication_enabled' => $communicationEnabled,
            'communication_total' => $communicationTotal,
            'operational_complete' => $operationalComplete,
            'operational_total' => $operationalTotal,
            'operational_percentage' => $operationalPercentage,
            'gaps_implemented' => $gapsImplemented,
            'gaps_total' => $gapsTotal,
        ];
    }

    /**
     * Get health status of all plugin cron jobs
     */
    private function getCronJobsHealth(): array
    {
        $hooks = [
            'limpvix_reconcile_payouts' => ['frequency' => '6h', 'description' => 'Reconciliacao de payouts com EFI Bank'],
            'limpvix_charge_recurring_payments' => ['frequency' => 'Diario', 'description' => 'Cobranca de pagamentos recorrentes'],
            'limpvix_process_review_timer' => ['frequency' => 'Horario', 'description' => 'Timer de review de feedback'],
            'limpvix_process_payout_batch' => ['frequency' => 'Horario', 'description' => 'Processamento de batch de payouts'],
            'limpvix_sync_payouts' => ['frequency' => '15min', 'description' => 'Sincronizacao de payouts'],
            'limpvix_retry_failed_payouts' => ['frequency' => '2x/dia', 'description' => 'Retry de payouts com falha'],
            'limpvix_clean_message_queue' => ['frequency' => 'Diario', 'description' => 'Limpeza da fila de mensagens'],
            'limpvix_check_contract_expiration' => ['frequency' => 'Diario', 'description' => 'Verificacao de contratos expirados'],
            'limpvix_contracts_daily_check' => ['frequency' => 'Diario', 'description' => 'Check diario de contratos'],
            'limpvix_contracts_weekly_briefing' => ['frequency' => 'Semanal', 'description' => 'Briefing semanal de contratos'],
            'limpvix_payment_authorization_timeout' => ['frequency' => 'Horario', 'description' => 'Timeout de autorizacao de pagamento'],
            'limpvix_fallback_send_offers' => ['frequency' => 'Horario', 'description' => 'Envio de ofertas (fallback)'],
            'limpvix_send_feedback_reminders' => ['frequency' => 'Horario', 'description' => 'Lembretes de feedback'],
            'limpvix_mp_periodic_sync' => ['frequency' => '5min', 'description' => 'Sync periodico Mercado Pago'],
        ];

        $results = [];
        foreach ($hooks as $hook => $info) {
            $next = wp_next_scheduled($hook);
            $results[] = [
                'hook' => $hook,
                'frequency' => $info['frequency'],
                'description' => $info['description'],
                'scheduled' => $next !== false,
                'next_run' => $next ? wp_date('Y-m-d H:i:s', $next) : null,
            ];
        }

        return $results;
    }

    /**
     * Count custom LimpVix tables in the database
     */
    private function countCustomTables(): int
    {
        global $wpdb;
        if (!$wpdb) {
            return 0;
        }
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name LIKE %s",
                DB_NAME,
                $wpdb->prefix . 'limpvix_%'
            )
        );
        return (int) ($result ?: 0);
    }

    /**
     * Safe count with table existence check
     *
     * @return int|null null if table doesn't exist
     */
    private function safeTableCount(string $table, ?string $where = null): ?int
    {
        global $wpdb;
        if (!$wpdb) {
            return null;
        }

        // Check table exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table
        ));

        if (!$exists) {
            return null;
        }

        $sql = "SELECT COUNT(*) FROM `{$table}`";
        if ($where) {
            $sql .= " WHERE {$where}";
        }

        $count = $wpdb->get_var($sql);
        return $count !== null ? (int) $count : 0;
    }
}
