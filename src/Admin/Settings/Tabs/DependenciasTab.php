<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class DependenciasTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'dependencias'; }
    public function getLabel(): string { return 'Dependencias'; }
    public function getIcon(): string { return "\xF0\x9F\x94\x97"; }

    public function handleSave(): void
    {
        // Read-only tab — no POST handling
    }

    public function render(): void
    {
        global $wpdb;

        // ── Collect All System Data ──────────────────────────────────
        $data = $this->collectSystemData($wpdb);

        // ── Inline styles for this tab ───────────────────────────────
        $this->renderStyles();

        // ── Hero Card ────────────────────────────────────────────────
        $this->renderHeroCard($data);

        // ── Section: Plugins & Gateways ──────────────────────────────
        $this->renderPluginsSection($data);

        // ── Section: REST API ────────────────────────────────────────
        $this->renderRestApiSection($data);

        // ── Section: Database ────────────────────────────────────────
        $this->renderDatabaseSection($data, $wpdb);

        // ── Section: Cron Health ─────────────────────────────────────
        $this->renderCronSection($data);

        // ── Section: Modules & Bootstraps ────────────────────────────
        $this->renderModulesSection($data);

        // ── Section: Communication Providers ─────────────────────────
        $this->renderCommunicationSection($data);

        // ── Section: Feature Flags ───────────────────────────────────
        $this->renderFeatureFlagsSection($data);

        // ── Section: PHP Environment ─────────────────────────────────
        $this->renderEnvironmentSection($data);
    }

    // ═════════════════════════════════════════════════════════════════
    // DATA COLLECTION
    // ═════════════════════════════════════════════════════════════════

    private function collectSystemData(\wpdb $wpdb): array
    {
        $d = [];

        // ── Plugins ──
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $d['woocommerce_active'] = is_plugin_active('woocommerce/woocommerce.php');
        $d['woocommerce_version'] = $d['woocommerce_active'] ? $this->getPluginVersion('woocommerce/woocommerce.php') : null;
        $d['efi_plugin_active'] = is_plugin_active('woo-gerencianet-official/woo-gerencianet-official.php');
        $d['efi_plugin_version'] = $d['efi_plugin_active'] ? $this->getPluginVersion('woo-gerencianet-official/woo-gerencianet-official.php') : null;
        $d['limpvix_version'] = defined('LIMPVIX_VERSION') ? LIMPVIX_VERSION : '0.2.0';

        // ── EFI Bank API credentials ──
        $d['efi_client_id'] = get_option('limpvix_efi_client_id', '');
        $d['efi_client_secret'] = get_option('limpvix_efi_client_secret', '');
        $d['efi_pix_key'] = get_option('limpvix_efi_pix_key', '');
        $d['efi_cert_path'] = get_option('limpvix_efi_cert_path', '');
        $d['efi_sandbox'] = get_option('limpvix_efi_sandbox', 'no') === 'yes';
        $d['efi_configured'] = !empty($d['efi_client_id']) && !empty($d['efi_client_secret']) && !empty($d['efi_pix_key']);
        $d['efi_cert_ok'] = !empty($d['efi_cert_path']) && file_exists($d['efi_cert_path']);

        // ── REST API routes ──
        $d['rest_routes'] = $this->countRestRoutes();

        // ── Database tables ──
        $d['tables'] = $this->getLimpvixTables($wpdb);
        $d['tables_total'] = count($d['tables']);
        $d['tables_exist'] = count(array_filter($d['tables'], fn($t) => $t['exists']));
        $d['total_rows'] = array_sum(array_column($d['tables'], 'rows'));

        // ── Migrations ──
        $d['migrations'] = $this->getMigrationStatus($wpdb);

        // ── Cron jobs ──
        $d['crons'] = $this->getLimpvixCrons();
        $d['crons_total'] = count($d['crons']);
        $d['crons_overdue'] = count(array_filter($d['crons'], fn($c) => $c['overdue']));

        // ── Modules bootstrapped ──
        $d['modules'] = $this->getModuleStatus();
        $d['modules_loaded'] = count(array_filter($d['modules'], fn($m) => $m['loaded']));

        // ── Feature Flags ──
        $d['flags'] = $this->getFeatureFlagStatus();

        // ── Communication Providers ──
        $d['twilio_account_sid'] = get_option('limpvix_twilio_account_sid', '');
        $d['twilio_auth_token'] = get_option('limpvix_twilio_auth_token', '');
        $d['twilio_verify_sid'] = get_option('limpvix_twilio_verify_service_sid', '');
        $d['twilio_from_number'] = get_option('limpvix_twilio_from_number', '');
        $d['twilio_configured'] = !empty($d['twilio_account_sid']) && !empty($d['twilio_auth_token']);
        $d['twilio_enabled'] = (bool) get_option('limpvix_twilio_enabled', false);

        $nvoipSettings = get_option('limpvix_nvoip_settings', []);
        if (!is_array($nvoipSettings)) $nvoipSettings = [];
        $d['nvoip_configured'] = !empty($nvoipSettings['api_key']) && !empty($nvoipSettings['user_token']);
        $d['nvoip_enabled'] = !empty($nvoipSettings['enabled']);
        $d['nvoip_number'] = $nvoipSettings['default_number'] ?? '';

        $d['ppid_configured'] = !empty(get_option('limpvix_ppid_email'))
                              && !empty(get_option('limpvix_ppid_senha'));
        $d['ppid_enabled'] = (bool) get_option('limpvix_ppid_enabled', false);

        $d['firebase_configured'] = !empty(get_option('limpvix_firebase_credentials'))
                                  || !empty(get_option('limpvix_firebase_project_id'));

        $d['has_comm_provider'] = ($d['twilio_configured'] && $d['twilio_enabled'])
                                || ($d['nvoip_configured'] && $d['nvoip_enabled']);

        // ── PHP Environment ──
        $d['php_version'] = PHP_VERSION;
        $d['php_ok'] = version_compare(PHP_VERSION, '8.0', '>=');
        $d['mysql_version'] = $wpdb->db_version();
        $d['mysql_ok'] = version_compare($d['mysql_version'], '5.7', '>=');
        $d['wp_version'] = get_bloginfo('version');
        $d['wp_ok'] = version_compare($d['wp_version'], '5.8', '>=');
        $d['memory_limit'] = ini_get('memory_limit');
        $d['upload_max'] = ini_get('upload_max_filesize');
        $d['post_max'] = ini_get('post_max_size');
        $d['max_execution'] = ini_get('max_execution_time');
        $d['wp_memory'] = defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : '40M';
        $d['wp_debug'] = defined('WP_DEBUG') && WP_DEBUG;
        $d['extensions'] = get_loaded_extensions();
        $d['has_curl'] = extension_loaded('curl');
        $d['has_mbstring'] = extension_loaded('mbstring');
        $d['has_openssl'] = extension_loaded('openssl');
        $d['has_gd'] = extension_loaded('gd');
        $d['has_intl'] = extension_loaded('intl');
        $d['has_pdo_mysql'] = extension_loaded('pdo_mysql');
        $d['has_imagick'] = extension_loaded('imagick');

        // ── Roles ──
        $d['roles'] = $this->getLimpvixRoles();

        // ── Users count ──
        $d['professionals_count'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_professionals"
        );
        $d['customers_count'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
            $wpdb->prefix . 'capabilities',
            '%limpvix_customer%'
        ));

        // ── Overall score ──
        $d['score'] = $this->calculateOverallScore($d);

        return $d;
    }

    // ═════════════════════════════════════════════════════════════════
    // RENDER SECTIONS
    // ═════════════════════════════════════════════════════════════════

    private function renderStyles(): void
    {
        ?>
        <style>
            .lvx-dep { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .lvx-hero { background: linear-gradient(135deg, #1E7A66 0%, #2D9B83 50%, #3BB59A 100%); color: #fff; border-radius: 12px; padding: 28px; margin-bottom: 24px; }
            .lvx-hero h1 { color: #fff; margin: 0 0 6px; font-size: 26px; font-weight: 700; }
            .lvx-hero .sub { color: #c8ede5; margin: 0; font-size: 13px; }
            .lvx-score-box { background: rgba(255,255,255,.18); padding: 14px 22px; border-radius: 8px; text-align: center; }
            .lvx-score-box .label { font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; opacity: .85; }
            .lvx-score-box .value { font-size: 32px; font-weight: 800; margin-top: 2px; }
            .lvx-quick { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; margin-top: 20px; }
            .lvx-qstat { background: rgba(255,255,255,.13); padding: 14px 8px; border-radius: 8px; text-align: center; }
            .lvx-qstat .icon { font-size: 22px; margin-bottom: 2px; }
            .lvx-qstat .num { font-size: 18px; font-weight: 700; }
            .lvx-qstat .lbl { font-size: 10px; opacity: .85; margin-top: 2px; }
            .lvx-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 20px; overflow: hidden; }
            .lvx-section-head { padding: 16px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
            .lvx-section-head h3 { margin: 0; font-size: 15px; font-weight: 600; color: #1f2937; }
            .lvx-section-head .count { background: #e5e7eb; color: #374151; font-size: 11px; padding: 3px 10px; border-radius: 99px; font-weight: 600; }
            .lvx-section-body { padding: 16px 20px; }
            .lvx-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            .lvx-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
            .lvx-grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
            .lvx-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
            .lvx-tbl th { background: #f9fafb; padding: 8px 12px; text-align: left; font-weight: 600; color: #374151; border-bottom: 2px solid #e5e7eb; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
            .lvx-tbl td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; color: #4b5563; }
            .lvx-tbl tr:hover td { background: #f9fafb; }
            .lvx-tbl code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #1f2937; }
            .lvx-ok { color: #059669; font-weight: 600; }
            .lvx-warn { color: #d97706; font-weight: 600; }
            .lvx-err { color: #dc2626; font-weight: 600; }
            .lvx-muted { color: #9ca3af; }
            .lvx-badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
            .lvx-badge-ok { background: #d1fae5; color: #065f46; }
            .lvx-badge-warn { background: #fef3c7; color: #92400e; }
            .lvx-badge-err { background: #fee2e2; color: #991b1b; }
            .lvx-badge-info { background: #dbeafe; color: #1e40af; }
            .lvx-badge-neutral { background: #f3f4f6; color: #374151; }
            .lvx-kpi { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; text-align: center; }
            .lvx-kpi .num { font-size: 28px; font-weight: 800; color: #1f2937; }
            .lvx-kpi .lbl { font-size: 11px; color: #6b7280; margin-top: 4px; }
            .lvx-bar { height: 8px; background: #e5e7eb; border-radius: 99px; overflow: hidden; margin-top: 6px; }
            .lvx-bar-fill { height: 100%; border-radius: 99px; transition: width .3s; }
            .lvx-notice { padding: 12px 16px; border-radius: 6px; margin-top: 12px; font-size: 13px; }
            .lvx-notice-ok { background: #d1fae5; border-left: 4px solid #059669; color: #065f46; }
            .lvx-notice-warn { background: #fef3c7; border-left: 4px solid #d97706; color: #92400e; }
            .lvx-notice-err { background: #fee2e2; border-left: 4px solid #dc2626; color: #991b1b; }
            .lvx-notice-info { background: #dbeafe; border-left: 4px solid #3b82f6; color: #1e40af; }
            @media (max-width: 1200px) { .lvx-quick { grid-template-columns: repeat(3, 1fr); } .lvx-grid-2 { grid-template-columns: 1fr; } }
        </style>
        <?php
    }

    private function renderHeroCard(array $d): void
    {
        $score = $d['score'];
        $scoreColor = $score >= 85 ? '#d1fae5' : ($score >= 60 ? '#fef3c7' : '#fee2e2');
        ?>
        <div class="lvx-dep">
        <div class="lvx-hero">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h1>&#9881; Sistema LimpVix &mdash; Auditoria em Tempo Real</h1>
                    <p class="sub">Plugin v<?php echo esc_html($d['limpvix_version']); ?> &bull; WordPress <?php echo esc_html($d['wp_version']); ?> &bull; PHP <?php echo esc_html($d['php_version']); ?> &bull; MySQL <?php echo esc_html($d['mysql_version']); ?></p>
                </div>
                <div class="lvx-score-box">
                    <div class="label">Health Score</div>
                    <div class="value" style="color:<?php echo $scoreColor; ?>"><?php echo $score; ?>%</div>
                </div>
            </div>
            <div class="lvx-quick">
                <div class="lvx-qstat">
                    <div class="icon"><?php echo $d['woocommerce_active'] ? '&#9989;' : '&#10060;'; ?></div>
                    <div class="num"><?php echo $d['woocommerce_active'] ? 'OK' : '&#10060;'; ?></div>
                    <div class="lbl">WooCommerce</div>
                </div>
                <div class="lvx-qstat">
                    <div class="icon">&#128268;</div>
                    <div class="num"><?php echo $d['rest_routes']; ?></div>
                    <div class="lbl">REST Routes</div>
                </div>
                <div class="lvx-qstat">
                    <div class="icon">&#128451;</div>
                    <div class="num"><?php echo $d['tables_exist']; ?>/<?php echo $d['tables_total']; ?></div>
                    <div class="lbl">DB Tables</div>
                </div>
                <div class="lvx-qstat">
                    <div class="icon">&#9202;</div>
                    <div class="num"><?php echo $d['crons_total']; ?></div>
                    <div class="lbl">Cron Jobs</div>
                </div>
                <div class="lvx-qstat">
                    <div class="icon">&#128101;</div>
                    <div class="num"><?php echo $d['professionals_count']; ?></div>
                    <div class="lbl">Profissionais</div>
                </div>
                <div class="lvx-qstat">
                    <div class="icon">&#128230;</div>
                    <div class="num"><?php echo $d['modules_loaded']; ?>/<?php echo count($d['modules']); ?></div>
                    <div class="lbl">Modulos</div>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderPluginsSection(array $d): void
    {
        $plugins = [
            ['WooCommerce', $d['woocommerce_active'], $d['woocommerce_version'], '5.0.0', 'OBRIGATORIO', 'E-commerce e checkout de clientes'],
            ['EFI Bank (Gerencianet)', $d['efi_plugin_active'], $d['efi_plugin_version'], null, 'OBRIGATORIO', 'PIX cobranca (cob) e payouts (cash-out) via EFI'],
        ];
        ?>
        <div class="lvx-section">
            <div class="lvx-section-head">
                <h3>&#128268; Plugins &amp; Gateway de Pagamento</h3>
            </div>
            <div class="lvx-section-body">
                <table class="lvx-tbl">
                    <thead><tr><th style="width:40px;">Status</th><th>Plugin</th><th>Versao</th><th>Requisito</th><th>Descricao</th></tr></thead>
                    <tbody>
                    <?php foreach ($plugins as [$name, $active, $ver, $minVer, $req, $desc]): ?>
                        <tr>
                            <td><?php echo $active ? '<span class="lvx-ok">&#9989;</span>' : '<span class="lvx-err">&#10060;</span>'; ?></td>
                            <td><strong><?php echo esc_html($name); ?></strong></td>
                            <td>
                                <?php if ($ver): ?>
                                    <code><?php echo esc_html($ver); ?></code>
                                    <?php if ($minVer && version_compare($ver, $minVer, '>=')): ?>
                                        <span class="lvx-ok">&#10003;</span>
                                    <?php elseif ($minVer): ?>
                                        <span class="lvx-warn">min <?php echo esc_html($minVer); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="lvx-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="lvx-badge <?php echo $req === 'OBRIGATORIO' ? 'lvx-badge-err' : 'lvx-badge-neutral'; ?>"><?php echo esc_html($req); ?></span></td>
                            <td><?php echo esc_html($desc); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php // EFI Bank API Credentials detail ?>
                <h4 style="margin:16px 0 8px;font-size:13px;">EFI Bank &mdash; Credenciais API</h4>
                <table class="lvx-tbl">
                    <tbody>
                        <tr>
                            <td style="width:36px;"><?php echo $d['efi_configured'] ? '<span class="lvx-ok">&#9989;</span>' : '<span class="lvx-err">&#10060;</span>'; ?></td>
                            <td><strong>Client ID + Secret</strong></td>
                            <td><?php echo !empty($d['efi_client_id']) ? '<code>' . esc_html(substr($d['efi_client_id'], 0, 20)) . '...</code>' : '<span class="lvx-err">NAO CONFIGURADO</span>'; ?></td>
                        </tr>
                        <tr>
                            <td><?php echo !empty($d['efi_pix_key']) ? '<span class="lvx-ok">&#9989;</span>' : '<span class="lvx-err">&#10060;</span>'; ?></td>
                            <td><strong>PIX Key</strong></td>
                            <td><?php echo !empty($d['efi_pix_key']) ? '<code>' . esc_html($d['efi_pix_key']) . '</code>' : '<span class="lvx-err">NAO CONFIGURADO</span>'; ?></td>
                        </tr>
                        <tr>
                            <td><?php echo $d['efi_cert_ok'] ? '<span class="lvx-ok">&#9989;</span>' : '<span class="lvx-warn">&#9888;</span>'; ?></td>
                            <td><strong>Certificado mTLS</strong></td>
                            <td><?php echo $d['efi_cert_ok'] ? '<code>' . esc_html(basename($d['efi_cert_path'])) . '</code>' : (!empty($d['efi_cert_path']) ? '<span class="lvx-warn">arquivo nao encontrado</span>' : '<span class="lvx-warn">caminho nao definido</span>'); ?></td>
                        </tr>
                        <tr>
                            <td><?php echo $d['efi_sandbox'] ? '<span class="lvx-warn">&#9888;</span>' : '<span class="lvx-ok">&#9989;</span>'; ?></td>
                            <td><strong>Ambiente</strong></td>
                            <td><span class="lvx-badge <?php echo $d['efi_sandbox'] ? 'lvx-badge-warn' : 'lvx-badge-ok'; ?>"><?php echo $d['efi_sandbox'] ? 'SANDBOX' : 'PRODUCAO'; ?></span></td>
                        </tr>
                    </tbody>
                </table>

                <?php if (!$d['woocommerce_active']): ?>
                    <div class="lvx-notice lvx-notice-err"><strong>&#10060; WooCommerce nao esta ativo!</strong> O LimpVix-Core requer WooCommerce para processamento de pagamentos.</div>
                <?php elseif (!$d['efi_configured']): ?>
                    <div class="lvx-notice lvx-notice-err"><strong>&#10060; EFI Bank nao configurado.</strong> Configure Client ID, Client Secret e PIX Key nas configuracoes do plugin Gerencianet.</div>
                <?php elseif ($d['efi_sandbox']): ?>
                    <div class="lvx-notice lvx-notice-warn"><strong>&#9888; EFI Bank em modo SANDBOX.</strong> Pagamentos reais nao serao processados. Altere para producao quando pronto.</div>
                <?php else: ?>
                    <div class="lvx-notice lvx-notice-ok"><strong>&#9989; EFI Bank configurado e em PRODUCAO.</strong> PIX cobranca e payouts ativos.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderRestApiSection(array $d): void
    {
        $endpoints = $this->getRestEndpointGroups();
        ?>
        <div class="lvx-section">
            <div class="lvx-section-head">
                <h3>&#128268; REST API &mdash; Endpoints Registrados</h3>
                <span class="count"><?php echo $d['rest_routes']; ?> rotas</span>
            </div>
            <div class="lvx-section-body">
                <div class="lvx-grid-4" style="margin-bottom:16px;">
                    <?php foreach ($endpoints as $group => $info): ?>
                    <div class="lvx-kpi">
                        <div class="num"><?php echo $info['count']; ?></div>
                        <div class="lbl"><?php echo esc_html($group); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="lvx-notice lvx-notice-info">
                    <strong>Base URL:</strong> <code><?php echo esc_html(rest_url('limpvix/v1/')); ?></code>
                    &bull; Autenticacao: JWT Bearer + API Key &bull; Rate Limiting ativo
                </div>
            </div>
        </div>
        <?php
    }

    private function renderDatabaseSection(array $d, \wpdb $wpdb): void
    {
        $pct = $d['tables_total'] > 0 ? round(($d['tables_exist'] / $d['tables_total']) * 100) : 0;
        $barColor = $pct >= 95 ? '#059669' : ($pct >= 70 ? '#d97706' : '#dc2626');
        ?>
        <div class="lvx-section">
            <div class="lvx-section-head">
                <h3>&#128451; Database &mdash; Tabelas LimpVix</h3>
                <span class="count"><?php echo $d['tables_exist']; ?>/<?php echo $d['tables_total']; ?> tabelas &bull; <?php echo number_format($d['total_rows']); ?> registros &bull; <?php echo $d['migrations']['executed']; ?> migrations</span>
            </div>
            <div class="lvx-section-body">
                <div class="lvx-bar"><div class="lvx-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $barColor; ?>;"></div></div>
                <table class="lvx-tbl" style="margin-top:12px;">
                    <thead><tr><th style="width:36px;">OK</th><th>Tabela</th><th style="width:90px;text-align:right;">Registros</th><th style="width:100px;">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['tables'] as $t):
                        $name = str_replace($wpdb->prefix . 'limpvix_', '', $t['name']);
                    ?>
                        <tr>
                            <td><?php echo $t['exists'] ? '<span class="lvx-ok">&#9989;</span>' : '<span class="lvx-err">&#10060;</span>'; ?></td>
                            <td><code><?php echo esc_html($name); ?></code></td>
                            <td style="text-align:right;font-weight:600;<?php echo $t['rows'] > 0 ? 'color:#1f2937;' : 'color:#d1d5db;'; ?>"><?php echo $t['exists'] ? number_format($t['rows']) : '&mdash;'; ?></td>
                            <td>
                                <?php if (!$t['exists']): ?>
                                    <span class="lvx-badge lvx-badge-err">FALTANDO</span>
                                <?php elseif ($t['rows'] > 0): ?>
                                    <span class="lvx-badge lvx-badge-ok">COM DADOS</span>
                                <?php else: ?>
                                    <span class="lvx-badge lvx-badge-neutral">VAZIA</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($d['tables_exist'] < $d['tables_total']): ?>
                    <div class="lvx-notice lvx-notice-err"><strong>&#10060; <?php echo ($d['tables_total'] - $d['tables_exist']); ?> tabelas faltando.</strong> Desative e reative o plugin para executar migrations pendentes.</div>
                <?php else: ?>
                    <div class="lvx-notice lvx-notice-ok"><strong>&#9989; Todas as <?php echo $d['tables_total']; ?> tabelas existem.</strong> <?php echo $d['migrations']['executed']; ?>/<?php echo $d['migrations']['total']; ?> migrations executadas.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderCronSection(array $d): void
    {
        ?>
        <div class="lvx-section">
            <div class="lvx-section-head">
                <h3>&#9202; WP-Cron &mdash; Tarefas Agendadas</h3>
                <span class="count"><?php echo $d['crons_total']; ?> jobs <?php if ($d['crons_overdue'] > 0): ?>&bull; <span style="color:#dc2626;"><?php echo $d['crons_overdue']; ?> atrasados</span><?php endif; ?></span>
            </div>
            <div class="lvx-section-body">
                <table class="lvx-tbl">
                    <thead><tr><th style="width:36px;">OK</th><th>Hook</th><th>Recorrencia</th><th>Proxima Execucao</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['crons'] as $c): ?>
                        <tr>
                            <td><?php echo $c['overdue'] ? '<span class="lvx-warn">&#9888;</span>' : '<span class="lvx-ok">&#9989;</span>'; ?></td>
                            <td><code><?php echo esc_html($c['hook']); ?></code></td>
                            <td><?php echo esc_html($c['recurrence'] ?: 'unica'); ?></td>
                            <td>
                                <?php if ($c['next_run']): ?>
                                    <code><?php echo esc_html(wp_date('d/m/Y H:i', $c['next_run'])); ?></code>
                                    <?php if ($c['overdue']): ?>
                                        <span class="lvx-badge lvx-badge-warn"><?php echo esc_html($c['delay']); ?> atrasado</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="lvx-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="lvx-badge <?php echo $c['overdue'] ? 'lvx-badge-warn' : 'lvx-badge-ok'; ?>"><?php echo $c['overdue'] ? 'ATRASADO' : 'OK'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($d['crons_overdue'] > 0): ?>
                    <div class="lvx-notice lvx-notice-warn"><strong>&#9888; <?php echo $d['crons_overdue']; ?> cron jobs atrasados.</strong> Configure um system cron (<code>* * * * * curl -s <?php echo esc_html(site_url('/wp-cron.php')); ?></code>) para garantir execucao pontual.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderModulesSection(array $d): void
    {
        ?>
        <div class="lvx-grid-2">
        <div class="lvx-section">
            <div class="lvx-section-head">
                <h3>&#128230; Modulos Bootstrap</h3>
                <span class="count"><?php echo $d['modules_loaded']; ?>/<?php echo count($d['modules']); ?></span>
            </div>
            <div class="lvx-section-body">
                <table class="lvx-tbl">
                    <thead><tr><th style="width:36px;">OK</th><th>Modulo</th><th>Classe</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['modules'] as $m): ?>
                        <tr>
                            <td><?php echo $m['loaded'] ? '<span class="lvx-ok">&#9989;</span>' : '<span class="lvx-err">&#10060;</span>'; ?></td>
                            <td><strong><?php echo esc_html($m['name']); ?></strong></td>
                            <td><code style="font-size:10px;"><?php echo esc_html($m['class']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="lvx-section">
            <div class="lvx-section-head">
                <h3>&#128101; Roles &amp; Usuarios</h3>
            </div>
            <div class="lvx-section-body">
                <table class="lvx-tbl">
                    <thead><tr><th style="width:36px;">OK</th><th>Role</th><th>Label</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['roles'] as $r): ?>
                        <tr>
                            <td><?php echo $r['exists'] ? '<span class="lvx-ok">&#9989;</span>' : '<span class="lvx-err">&#10060;</span>'; ?></td>
                            <td><code><?php echo esc_html($r['slug']); ?></code></td>
                            <td><?php echo esc_html($r['label']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="lvx-grid-2" style="margin-top:12px;">
                    <div class="lvx-kpi"><div class="num"><?php echo $d['professionals_count']; ?></div><div class="lbl">Profissionais Cadastrados</div></div>
                    <div class="lvx-kpi"><div class="num"><?php echo $d['customers_count']; ?></div><div class="lbl">Clientes (role)</div></div>
                </div>
            </div>
        </div>
        </div>
        <?php
    }

    private function renderCommunicationSection(array $d): void
    {
        $providers = [
            [
                'name' => 'Twilio SMS + Verify OTP',
                'configured' => $d['twilio_configured'],
                'enabled' => $d['twilio_enabled'],
                'function' => 'SMS envio + OTP verificacao telefone',
                'details' => array_filter([
                    $d['twilio_configured'] ? 'SID: ' . substr($d['twilio_account_sid'], 0, 12) . '...' : null,
                    !empty($d['twilio_verify_sid']) ? 'Verify: ' . substr($d['twilio_verify_sid'], 0, 12) . '...' : 'Verify SID: nao configurado',
                    !empty($d['twilio_from_number']) ? 'From: ' . $d['twilio_from_number'] : null,
                ]),
            ],
            [
                'name' => 'NVoip',
                'configured' => $d['nvoip_configured'],
                'enabled' => $d['nvoip_enabled'],
                'function' => 'OTP + WhatsApp BR (alternativo)',
                'details' => array_filter([
                    !empty($d['nvoip_number']) ? 'Numero: ' . $d['nvoip_number'] : null,
                ]),
            ],
            [
                'name' => 'PPID (KYC)',
                'configured' => $d['ppid_configured'],
                'enabled' => $d['ppid_enabled'],
                'function' => 'Verificacao identidade profissional',
                'details' => [],
            ],
            [
                'name' => 'Firebase (FCM + Auth)',
                'configured' => $d['firebase_configured'],
                'enabled' => false,
                'function' => 'Push notifications + Phone Auth OTP',
                'details' => ['Pendente configuracao (contingencia: Twilio OTP)'],
            ],
        ];
        ?>
        <div class="lvx-section">
            <div class="lvx-section-head">
                <h3>&#128225; Providers de Comunicacao &amp; Verificacao</h3>
                <span class="count"><?php echo $d['has_comm_provider'] ? '<span class="lvx-ok">ATIVO</span>' : '<span class="lvx-warn">NENHUM ATIVO</span>'; ?></span>
            </div>
            <div class="lvx-section-body">
                <table class="lvx-tbl">
                    <thead><tr><th style="width:36px;">OK</th><th>Provider</th><th>Configurado</th><th>Habilitado</th><th>Funcao</th><th>Detalhes</th></tr></thead>
                    <tbody>
                    <?php foreach ($providers as $p): ?>
                        <tr>
                            <td><?php echo ($p['configured'] && $p['enabled']) ? '<span class="lvx-ok">&#9989;</span>' : ($p['configured'] ? '<span class="lvx-warn">&#9888;</span>' : '<span class="lvx-muted">&#9898;</span>'); ?></td>
                            <td><strong><?php echo esc_html($p['name']); ?></strong></td>
                            <td><span class="lvx-badge <?php echo $p['configured'] ? 'lvx-badge-ok' : 'lvx-badge-neutral'; ?>"><?php echo $p['configured'] ? 'SIM' : 'NAO'; ?></span></td>
                            <td><span class="lvx-badge <?php echo $p['enabled'] ? 'lvx-badge-ok' : ($p['configured'] ? 'lvx-badge-warn' : 'lvx-badge-neutral'); ?>"><?php echo $p['enabled'] ? 'SIM' : 'NAO'; ?></span></td>
                            <td><?php echo esc_html($p['function']); ?></td>
                            <td style="font-size:11px;color:#6b7280;"><?php echo esc_html(implode(' | ', $p['details'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$d['has_comm_provider']): ?>
                    <div class="lvx-notice lvx-notice-warn"><strong>&#9888; Nenhum provider de comunicacao ativo.</strong> Configure Twilio na aba <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=conexoes'); ?>">Conexoes</a>.</div>
                <?php elseif ($d['twilio_configured'] && $d['twilio_enabled']): ?>
                    <div class="lvx-notice lvx-notice-ok"><strong>&#9989; Twilio SMS ativo.</strong> OTP via <?php echo !empty($d['twilio_verify_sid']) ? 'Twilio Verify' : 'Twilio (falta Verify SID)'; ?>. Cascata: Firebase OTP &rarr; Twilio Verify &rarr; permissivo.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderFeatureFlagsSection(array $d): void
    {
        $enabled = count(array_filter($d['flags'], fn($f) => $f['value']));
        $warnings = count(array_filter($d['flags'], fn($f) => !$f['value'] && ($f['recommended'] ?? false)));
        ?>
        <div class="lvx-section">
            <div class="lvx-section-head">
                <h3>&#127987; Feature Flags</h3>
                <span class="count"><?php echo $enabled; ?>/<?php echo count($d['flags']); ?> ativas<?php if ($warnings): ?> &bull; <span style="color:#d97706;"><?php echo $warnings; ?> recomendadas OFF</span><?php endif; ?></span>
            </div>
            <div class="lvx-section-body">
                <table class="lvx-tbl">
                    <thead><tr><th style="width:36px;">ON</th><th>Flag</th><th>Categoria</th><th>Descricao</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($d['flags'] as $f): ?>
                        <tr>
                            <td><?php
                                if ($f['value']) {
                                    echo '<span class="lvx-ok">&#9989;</span>';
                                } elseif ($f['recommended'] ?? false) {
                                    echo '<span class="lvx-warn">&#9888;</span>';
                                } else {
                                    echo '<span class="lvx-muted">&#9898;</span>';
                                }
                            ?></td>
                            <td><code><?php echo esc_html($f['key']); ?></code></td>
                            <td><span class="lvx-badge lvx-badge-neutral"><?php echo esc_html($f['category']); ?></span></td>
                            <td style="font-size:12px;color:#4b5563;"><?php echo esc_html($f['description']); ?></td>
                            <td>
                                <?php if ($f['value']): ?>
                                    <span class="lvx-badge lvx-badge-ok">ATIVA</span>
                                <?php elseif ($f['recommended'] ?? false): ?>
                                    <span class="lvx-badge lvx-badge-warn">OFF &mdash; deveria estar ON</span>
                                <?php else: ?>
                                    <span class="lvx-badge lvx-badge-neutral">DESLIGADA</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="lvx-notice lvx-notice-info">Feature Flags sao gerenciadas via <code>wp_options</code> (chave: <code>limpvix_feature_flags</code>). Altere na aba <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=geral'); ?>">Geral</a>.</div>
            </div>
        </div>
        <?php
    }

    private function renderEnvironmentSection(array $d): void
    {
        $requiredExts = [
            ['curl', $d['has_curl'], 'HTTP requests (API calls)'],
            ['mbstring', $d['has_mbstring'], 'Multi-byte string handling'],
            ['openssl', $d['has_openssl'], 'JWT tokens + HTTPS'],
            ['gd', $d['has_gd'], 'Image processing'],
            ['intl', $d['has_intl'], 'Internationalization'],
            ['imagick', $d['has_imagick'], 'Advanced image processing'],
            ['pdo_mysql', $d['has_pdo_mysql'], 'PDO MySQL driver'],
        ];
        ?>
        <div class="lvx-grid-2">
        <div class="lvx-section">
            <div class="lvx-section-head"><h3>&#9881; Versoes do Ambiente</h3></div>
            <div class="lvx-section-body">
                <table class="lvx-tbl">
                    <tbody>
                        <tr><td><strong>PHP</strong></td><td><code><?php echo esc_html($d['php_version']); ?></code> <?php echo $d['php_ok'] ? '<span class="lvx-ok">&#10003;</span>' : '<span class="lvx-err">min 8.0</span>'; ?></td></tr>
                        <tr><td><strong>MySQL</strong></td><td><code><?php echo esc_html($d['mysql_version']); ?></code> <?php echo $d['mysql_ok'] ? '<span class="lvx-ok">&#10003;</span>' : '<span class="lvx-err">min 5.7</span>'; ?></td></tr>
                        <tr><td><strong>WordPress</strong></td><td><code><?php echo esc_html($d['wp_version']); ?></code> <?php echo $d['wp_ok'] ? '<span class="lvx-ok">&#10003;</span>' : '<span class="lvx-err">min 5.8</span>'; ?></td></tr>
                        <tr><td><strong>WP_DEBUG</strong></td><td><?php echo $d['wp_debug'] ? '<span class="lvx-warn">ATIVO</span>' : '<span class="lvx-ok">OFF</span>'; ?></td></tr>
                    </tbody>
                </table>
                <h4 style="margin:16px 0 8px;font-size:13px;">Limites</h4>
                <table class="lvx-tbl">
                    <tbody>
                        <tr><td>PHP memory_limit</td><td><code><?php echo esc_html($d['memory_limit']); ?></code> <?php echo ($d['memory_limit'] === '-1' || $this->parseBytes($d['memory_limit']) >= 256 * 1024 * 1024) ? '<span class="lvx-ok">&#10003;</span>' : '<span class="lvx-warn">recomendado 256M</span>'; ?></td></tr>
                        <tr><td>WP_MEMORY_LIMIT</td><td><code><?php echo esc_html($d['wp_memory']); ?></code> <?php echo $this->parseBytes($d['wp_memory']) >= 256 * 1024 * 1024 ? '<span class="lvx-ok">&#10003;</span>' : '<span class="lvx-warn">recomendado 256M</span>'; ?></td></tr>
                        <tr><td>upload_max_filesize</td><td><code><?php echo esc_html($d['upload_max']); ?></code> <?php echo $this->parseBytes($d['upload_max']) >= 16 * 1024 * 1024 ? '<span class="lvx-ok">&#10003;</span>' : '<span class="lvx-warn">recomendado 16M+</span>'; ?></td></tr>
                        <tr><td>post_max_size</td><td><code><?php echo esc_html($d['post_max']); ?></code></td></tr>
                        <tr><td>max_execution_time</td><td><code><?php echo esc_html($d['max_execution']); ?>s</code></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="lvx-section">
            <div class="lvx-section-head"><h3>&#128230; Extensoes PHP</h3><span class="count"><?php echo count($d['extensions']); ?> carregadas</span></div>
            <div class="lvx-section-body">
                <table class="lvx-tbl">
                    <thead><tr><th style="width:36px;">OK</th><th>Extensao</th><th>Uso</th></tr></thead>
                    <tbody>
                    <?php foreach ($requiredExts as [$ext, $loaded, $usage]): ?>
                        <tr>
                            <td><?php echo $loaded ? '<span class="lvx-ok">&#9989;</span>' : '<span class="lvx-warn">&#9888;</span>'; ?></td>
                            <td><code><?php echo esc_html($ext); ?></code></td>
                            <td><?php echo esc_html($usage); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (!$d['has_pdo_mysql']): ?>
                    <div class="lvx-notice lvx-notice-warn"><strong>&#9888; pdo_mysql nao esta carregado.</strong> Isso pode causar problemas se algum codigo usar PDO para MySQL. A extensao <code>mysqli</code> esta sendo usada como fallback.</div>
                <?php endif; ?>
            </div>
        </div>
        </div>

        </div><!-- close .lvx-dep -->
        <?php
    }

    // ═════════════════════════════════════════════════════════════════
    // HELPER / DATA METHODS
    // ═════════════════════════════════════════════════════════════════

    private function getPluginVersion(string $pluginPath): ?string
    {
        $file = WP_PLUGIN_DIR . '/' . $pluginPath;
        if (!file_exists($file)) return null;
        $data = get_plugin_data($file, false, false);
        return $data['Version'] ?? null;
    }

    private function countRestRoutes(): int
    {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $count = 0;
        foreach ($routes as $route => $handlers) {
            if (strpos($route, 'limpvix/v1') !== false) {
                $count++;
            }
        }
        return $count;
    }

    private function getRestEndpointGroups(): array
    {
        $server = rest_get_server();
        $routes = $server->get_routes();
        $groups = [];
        foreach ($routes as $route => $handlers) {
            if (strpos($route, 'limpvix/v1') === false) continue;
            // Extract group from route: /limpvix/v1/GROUP/...
            $parts = explode('/', trim($route, '/'));
            if (count($parts) >= 3) {
                $group = $parts[2]; // e.g. auth, briefing, contracts...
                if (!isset($groups[$group])) {
                    $groups[$group] = ['count' => 0];
                }
                $groups[$group]['count']++;
            }
        }
        ksort($groups);
        return $groups;
    }

    private function getLimpvixTables(\wpdb $wpdb): array
    {
        $expectedTables = [
            'briefings', 'briefing_steps', 'briefing_packages', 'briefing_snapshots',
            'contracts', 'contract_offers', 'contract_executions',
            'executions', 'execution_evidence', 'execution_issues',
            'orders', 'order_items',
            'professionals', 'professional_documents', 'professional_skills',
            'professional_availability', 'professional_verifications',
            'service_catalog', 'service_additionals', 'service_required_skills',
            'package_configs',
            'feedback', 'feedback_criteria', 'feedback_photos',
            'payouts', 'financial_ledger', 'recurring_payments',
            'message_queue', 'message_templates', 'message_flows',
            'schedules', 'schedule_allocations',
            'migrations', 'user_verifications', 'epi_catalog',
            'api_keys',
        ];

        // Also discover any tables not in our expected list
        $allDbTables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}limpvix_%'");
        $allTableNames = [];
        foreach ($allDbTables as $t) {
            $short = str_replace($wpdb->prefix . 'limpvix_', '', $t);
            $allTableNames[$short] = true;
        }

        // Merge expected + discovered
        foreach ($expectedTables as $t) {
            if (!isset($allTableNames[$t])) {
                $allTableNames[$t] = false;
            }
        }

        ksort($allTableNames);

        $result = [];
        foreach ($allTableNames as $short => $inDb) {
            $fullName = $wpdb->prefix . 'limpvix_' . $short;
            $exists = in_array($fullName, $allDbTables, true);
            $rows = 0;
            if ($exists) {
                $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$fullName}`");
            }
            $result[] = [
                'name' => $fullName,
                'short' => $short,
                'exists' => $exists,
                'rows' => $rows,
            ];
        }

        return $result;
    }

    private function getMigrationStatus(\wpdb $wpdb): array
    {
        $table = $wpdb->prefix . 'limpvix_migrations';
        $tableExists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;

        if (!$tableExists) {
            return ['executed' => 0, 'total' => 0, 'table_exists' => false];
        }

        $executed = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

        // Count SQL files in migrations folder
        $migrationsDir = WP_PLUGIN_DIR . '/limpvix-core/database-migrations/';
        $sqlFiles = 0;
        if (is_dir($migrationsDir)) {
            $sqlFiles = count(glob($migrationsDir . '*.sql'));
        }

        return [
            'executed' => $executed,
            'total' => max($sqlFiles, $executed),
            'table_exists' => true,
        ];
    }

    private function getLimpvixCrons(): array
    {
        $crons = _get_cron_array();
        if (!is_array($crons)) return [];

        $now = time();
        $result = [];
        $seen = [];

        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                if (strpos($hook, 'limpvix') === false) continue;
                if (isset($seen[$hook])) continue;
                $seen[$hook] = true;

                foreach ($events as $key => $event) {
                    $overdue = ($timestamp <= $now) && ($now - $timestamp > 300); // 5 min grace
                    $delay = '';
                    if ($overdue) {
                        $diff = $now - $timestamp;
                        if ($diff > 86400) {
                            $delay = round($diff / 86400) . 'd';
                        } elseif ($diff > 3600) {
                            $delay = round($diff / 3600) . 'h';
                        } else {
                            $delay = round($diff / 60) . 'min';
                        }
                    }

                    $result[] = [
                        'hook' => $hook,
                        'next_run' => $timestamp,
                        'recurrence' => $event['schedule'] ?? null,
                        'overdue' => $overdue,
                        'delay' => $delay,
                    ];
                    break; // One entry per hook
                }
            }
        }

        usort($result, fn($a, $b) => $a['next_run'] <=> $b['next_run']);

        return $result;
    }

    private function getModuleStatus(): array
    {
        $modules = [
            ['Kernel', 'LimpVix\\Core\\Kernel'],
            ['AuthBootstrap', 'LimpVix\\Infrastructure\\API\\AuthBootstrap'],
            ['BriefingBootstrap', 'LimpVix\\Core\\BriefingBootstrap'],
            ['CommunicationBootstrap', 'LimpVix\\Core\\CommunicationBootstrap'],
            ['FeedbackBootstrap', 'LimpVix\\Core\\FeedbackBootstrap'],
            ['SchedulingBootstrap', 'LimpVix\\Core\\SchedulingBootstrap'],
            ['ProfessionalBootstrap', 'LimpVix\\Core\\ProfessionalBootstrap'],
            ['ContractBootstrap', 'LimpVix\\Core\\ContractBootstrap'],
            ['ExecutionBootstrap', 'LimpVix\\Core\\ExecutionBootstrap'],
            ['AdapterBootstrap', 'LimpVix\\Infrastructure\\Adapters\\AdapterBootstrap'],
            ['AdminBootstrap', 'LimpVix\\Admin\\Bootstrap\\AdminBootstrap'],
            ['BriefingApiBootstrap', 'LimpVix\\Infrastructure\\API\\BriefingApiBootstrap'],
            ['ContractAutomation', 'LimpVix\\Infrastructure\\Automation\\ContractAutomation'],
        ];

        $result = [];
        foreach ($modules as [$name, $class]) {
            $result[] = [
                'name' => $name,
                'class' => $class,
                'loaded' => class_exists($class),
            ];
        }
        return $result;
    }

    private function getFeatureFlagStatus(): array
    {
        $saved = get_option('limpvix_feature_flags', []);
        if (!is_array($saved)) $saved = [];

        // Flag definitions: category, description, recommended ON
        $definitions = [
            'core_enabled'          => ['core',          'Habilita o nucleo do plugin LimpVix',                    true],
            'persist_orders'        => ['persistence',   'Persiste pedidos no banco LimpVix (independente WC)',    true],
            'financial_workflow'    => ['finance',        'Ativa FSM financeira (hold, approve, release, payout)', true],
            'payout_engine'         => ['finance',        'Motor de repasse PIX para profissionais via EFI Bank',  true],
            'admin_interface'       => ['admin',          'Habilita menus e paginas admin do LimpVix',             true],
            'admin_ui'              => ['admin',          'Componentes UI modernos no painel admin',               true],
            'audit_logging'         => ['audit',          'Registra eventos de auditoria (append-only log)',       true],
            'notifications'         => ['communication',  'Habilita envio de SMS/Push/Email via providers',        true],
            'external_integrations' => ['integrations',   'Permite chamadas a APIs externas (EFI, Twilio, PPID)', true],
            'rest_api'              => ['api',            'Endpoints REST /limpvix/v1/* para apps e briefing',     true],
            'briefing_enabled'      => ['modules',        'Formulario de briefing 10-steps para clientes',         true],
            'auto_send_offers'      => ['automation',     'Envia ofertas automaticas aos profissionais apos match', true],
        ];

        $result = [];
        foreach ($saved as $key => $value) {
            $def = $definitions[$key] ?? ['other', 'Flag sem descricao', false];
            $result[] = [
                'key' => $key,
                'value' => (bool) $value,
                'category' => $def[0],
                'description' => $def[1],
                'recommended' => $def[2],
            ];
        }

        // Sort: warnings first (recommended but OFF), then enabled, then disabled
        usort($result, function ($a, $b) {
            $aWarn = !$a['value'] && ($a['recommended'] ?? false) ? 0 : 1;
            $bWarn = !$b['value'] && ($b['recommended'] ?? false) ? 0 : 1;
            if ($aWarn !== $bWarn) return $aWarn <=> $bWarn;
            if ($a['value'] !== $b['value']) return $b['value'] <=> $a['value'];
            return strcmp($a['key'], $b['key']);
        });

        return $result;
    }

    private function getLimpvixRoles(): array
    {
        $expected = [
            'limpvix_customer' => 'Cliente LimpVix',
            'limpvix_professional' => 'Profissional LimpVix',
            'limpvix_gerente_nacional' => 'Gerente Nacional LimpVix',
            'limpvix_gerente_estadual' => 'Gerente Estadual LimpVix',
            'limpvix_gerente_regional' => 'Gerente Regional LimpVix',
            'limpvix_financeiro' => 'Financeiro LimpVix',
        ];

        $wpRoles = wp_roles();
        $result = [];
        foreach ($expected as $slug => $label) {
            $result[] = [
                'slug' => $slug,
                'label' => $label,
                'exists' => isset($wpRoles->roles[$slug]),
            ];
        }
        return $result;
    }

    private function calculateOverallScore(array $d): int
    {
        $checks = 0;
        $passed = 0;

        // WooCommerce active (critical)
        $checks += 3;
        if ($d['woocommerce_active']) $passed += 3;

        // PHP/MySQL/WP versions
        $checks += 3;
        if ($d['php_ok']) $passed++;
        if ($d['mysql_ok']) $passed++;
        if ($d['wp_ok']) $passed++;

        // Database tables
        $checks += 3;
        if ($d['tables_total'] > 0) {
            $tablePct = $d['tables_exist'] / $d['tables_total'];
            $passed += round($tablePct * 3);
        }

        // Modules loaded
        $checks += 2;
        $moduleTotal = count($d['modules']);
        if ($moduleTotal > 0) {
            $passed += round(($d['modules_loaded'] / $moduleTotal) * 2);
        }

        // REST API routes
        $checks += 2;
        if ($d['rest_routes'] >= 50) $passed += 2;
        elseif ($d['rest_routes'] >= 20) $passed += 1;

        // Cron health
        $checks += 1;
        if ($d['crons_total'] > 0 && $d['crons_overdue'] === 0) $passed += 1;

        // Communication provider
        $checks += 1;
        if ($d['has_comm_provider']) $passed += 1;

        // Payment gateway (EFI Bank)
        $checks += 1;
        if ($d['efi_configured']) $passed += 1;

        // Roles
        $checks += 1;
        $rolesOk = count(array_filter($d['roles'], fn($r) => $r['exists']));
        if ($rolesOk >= 4) $passed += 1;

        return $checks > 0 ? (int) round(($passed / $checks) * 100) : 0;
    }

    private function parseBytes(string $val): int
    {
        $val = trim($val);
        if ($val === '-1') return PHP_INT_MAX;
        $last = strtolower(substr($val, -1));
        $num = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024 * 1024 * 1024; break;
            case 'm': $num *= 1024 * 1024; break;
            case 'k': $num *= 1024; break;
        }
        return $num;
    }
}
