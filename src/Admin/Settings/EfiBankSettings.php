<?php
/**
 * EfiBankSettings - Configurações EFI Bank no Admin LimpVix
 *
 * RESPONSABILIDADE:
 * - Renderizar card de configuração EFI Bank na tab Pagamentos
 * - Gerenciar credenciais OAuth (clientId, clientSecret, pixKey, certPath)
 * - Testar conexão OAuth (POST /oauth/token) no sandbox/produção
 * - Detectar e mostrar status do plugin woo-gerencianet-official
 * - AJAX: salvar credenciais + testar conexão
 *
 * DEPENDÊNCIAS WC:
 * - Plugin: woo-gerencianet-official (checkout transparente PIX/Cartão)
 *
 * DEPENDÊNCIAS LIMPVIX:
 * - EfiPayoutProvider (payout automático para profissionais)
 *
 * @package LimpVix\Admin\Settings
 * @since 0.2.0
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

class EfiBankSettings
{
    /** Slug do plugin WC EFI oficial */
    private const WC_PLUGIN_SLUG   = 'woo-gerencianet-official/gerencianet-oficial.php';
    private const WC_PLUGIN_SLUG_2 = 'efi-pay/efi-pay.php'; // slug alternativo

    /** wp_options keys */
    private const OPT_CLIENT_ID     = 'limpvix_efi_client_id';
    private const OPT_CLIENT_SECRET = 'limpvix_efi_client_secret';
    private const OPT_PIX_KEY       = 'limpvix_efi_pix_key';
    private const OPT_CERT_PATH     = 'limpvix_efi_cert_path';
    private const OPT_SANDBOX       = 'limpvix_efi_sandbox';

    /** URLs da API EFI */
    private const API_SANDBOX    = 'https://pix-h.api.efipay.com.br';
    private const API_PRODUCTION = 'https://pix.api.efipay.com.br';

    // ──────────────────────────────────────────────────────────
    // Registro de hooks
    // ──────────────────────────────────────────────────────────

    public static function registerHooks(): void
    {
        add_action('wp_ajax_limpvix_efi_save_settings',    [__CLASS__, 'ajaxSaveSettings']);
        add_action('wp_ajax_limpvix_efi_test_connection',  [__CLASS__, 'ajaxTestConnection']);
        add_action('wp_ajax_limpvix_efi_check_wc_plugin',      [__CLASS__, 'ajaxCheckWcPlugin']);
        add_action('wp_ajax_limpvix_efi_sync_wc_credentials',  [__CLASS__, 'ajaxSyncWcCredentials']);
        add_action('wp_ajax_limpvix_efi_upload_cert',          [__CLASS__, 'ajaxUploadCertificate']);
    }

    // ──────────────────────────────────────────────────────────
    // Status do plugin WooCommerce EFI
    // ──────────────────────────────────────────────────────────

    public static function isWcPluginInstalled(): bool
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        return isset($plugins[self::WC_PLUGIN_SLUG]) || isset($plugins[self::WC_PLUGIN_SLUG_2]);
    }

    public static function isWcPluginActive(): bool
    {
        return is_plugin_active(self::WC_PLUGIN_SLUG)
            || is_plugin_active(self::WC_PLUGIN_SLUG_2)
            || is_plugin_active('woo-gerencianet-official/gerencianet-oficial.php');
    }

    /** Detectar credenciais sincronizadas do plugin WC EFI */
    public static function getWcPluginCredentials(): array
    {
        // woo-gerencianet-official armazena em woocommerce_wc_gerencianet_pix_settings
        $s       = get_option('woocommerce_wc_gerencianet_pix_settings', []);
        $sandbox = ($s['gn_sandbox'] ?? 'yes') === 'yes';

        // Selecionar credenciais de acordo com ambiente
        if ($sandbox) {
            $client_id     = $s['gn_client_id_homologation']     ?? '';
            $client_secret = $s['gn_client_secret_homologation'] ?? '';
        } else {
            $client_id     = $s['gn_client_id_production']     ?? '';
            $client_secret = $s['gn_client_secret_production'] ?? '';
        }

        return [
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
            'pix_key'       => $s['gn_pix_key']             ?? '',
            'sandbox'       => $sandbox,
            'cert_name'     => $s['gn_certificate_file_name'] ?? '',
            'hmac'          => $s['gn_hmac']                 ?? '',
        ];
    }

    /** Status das credenciais LimpVix (wp_options) */
    public static function getConfigStatus(): array
    {
        $client_id     = get_option(self::OPT_CLIENT_ID, '');
        $client_secret = get_option(self::OPT_CLIENT_SECRET, '');
        $pix_key       = get_option(self::OPT_PIX_KEY, '');
        $sandbox       = get_option(self::OPT_SANDBOX, 'no');

        $configured = !empty($client_id) && !empty($client_secret) && !empty($pix_key);

        $missing = [];
        if (empty($client_id))     $missing[] = 'Client ID';
        if (empty($client_secret)) $missing[] = 'Client Secret';
        if (empty($pix_key))       $missing[] = 'Chave PIX';

        return [
            'configured'      => $configured,
            'client_id'       => $client_id,
            'client_secret'   => $client_secret,
            'pix_key'         => $pix_key,
            'cert_path'       => get_option(self::OPT_CERT_PATH, ''),
            'sandbox'         => $sandbox === 'yes',
            'missing'         => $missing,
            'status_icon'     => $configured ? '✓' : '⚠',
            'status_color'    => $configured ? '#4ade80' : '#fbbf24',
            'status_text'     => $configured
                ? 'Configurado — ' . ($sandbox === 'yes' ? 'Sandbox' : 'Produção')
                : 'Credenciais incompletas',
        ];
    }

    public static function render(): void
    {
        self::enqueueInlineScript();

        $wcInstalled = self::isWcPluginInstalled();
        $wcActive    = self::isWcPluginActive();
        $config      = self::getConfigStatus();
        $certExists  = !empty($config['cert_path']) && file_exists($config['cert_path']);
        $certName    = $certExists ? basename($config['cert_path']) : '';
        ?>

        <!-- ── Status Banner ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-radius:12px;margin-bottom:16px;background:<?php echo $config['configured'] ? 'linear-gradient(135deg,#ecfdf5,#d1fae5)' : 'linear-gradient(135deg,#fffbeb,#fef3c7)'; ?>;border:1px solid <?php echo $config['configured'] ? '#a7f3d0' : '#fde68a'; ?>;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:42px;height:42px;border-radius:10px;background:<?php echo $config['configured'] ? '#10b981' : '#f59e0b'; ?>;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;box-shadow:0 4px 12px <?php echo $config['configured'] ? 'rgba(16,185,129,.35)' : 'rgba(245,158,11,.35)'; ?>;">
                    <?php echo $config['configured'] ? '✓' : '⚙'; ?>
                </div>
                <div>
                    <div style="font-weight:700;font-size:14px;color:#111827;">
                        <?php echo $config['configured'] ? 'EFI Bank configurado' : 'EFI Bank — aguardando configuração'; ?>
                    </div>
                    <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                        <?php echo esc_html($config['status_text']); ?>
                        <?php if (!empty($config['missing'])): ?>
                            &nbsp;·&nbsp;<span style="color:#d97706;font-weight:600;">Faltando: <?php echo esc_html(implode(', ', $config['missing'])); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <span style="padding:5px 14px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.4px;background:<?php echo $config['configured'] ? '#10b981' : '#f59e0b'; ?>;color:#fff;">
                <?php echo $config['configured'] ? '● ATIVO' : '● INATIVO'; ?>
            </span>
        </div>

        <!-- ── WC Plugin Status ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:8px;margin-bottom:16px;background:<?php echo $wcActive ? '#f0fdf4' : '#fef2f2'; ?>;border-left:4px solid <?php echo $wcActive ? '#10b981' : '#ef4444'; ?>;">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:18px;"><?php echo $wcActive ? '✅' : '❌'; ?></span>
                <div>
                    <div style="font-weight:600;font-size:13px;color:#111827;">Plugin WooCommerce EFI <span style="font-weight:400;color:#6b7280;">(woo-gerencianet-official)</span></div>
                    <div style="font-size:12px;color:#6b7280;margin-top:1px;">
                        <?php if ($wcActive): ?>Ativo — Checkout PIX/Cartão transparente disponível<?php elseif ($wcInstalled): ?>Instalado mas inativo<?php else: ?>Não instalado<?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($wcActive): ?>
                <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout'); ?>" class="button button-small" style="font-size:12px;">Configurar WC ↗</a>
            <?php else: ?>
                <a href="<?php echo $wcInstalled ? admin_url('plugins.php') : admin_url('plugin-install.php?s=gerencianet&tab=search'); ?>" class="button button-small" style="font-size:12px;"><?php echo $wcInstalled ? 'Ativar' : 'Instalar'; ?></a>
            <?php endif; ?>
        </div>

        <!-- ── Sync WC Credentials ── -->
        <?php if ($wcActive): $wc = self::getWcPluginCredentials(); ?>
        <div style="padding:12px 16px;border-radius:8px;background:#eff6ff;border:1px solid #bfdbfe;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div style="flex:1;min-width:0;">
                <div style="font-weight:600;font-size:12px;color:#1d4ed8;margin-bottom:4px;">🔄 Credenciais detectadas no plugin WooCommerce EFI</div>
                <div style="font-size:11px;color:#1e40af;display:flex;flex-wrap:wrap;gap:8px;">
                    <span><strong>ID:</strong> <?php echo esc_html(substr($wc['client_id'], 0, 28) . '…'); ?></span>
                    <span>·</span>
                    <span><strong>PIX:</strong> <?php echo esc_html($wc['pix_key'] ?: '—'); ?></span>
                    <span>·</span>
                    <span><?php echo $wc['sandbox'] ? '🏖 Sandbox' : '🚀 Produção'; ?></span>
                    <?php if (!empty($wc['cert_name'])): ?><span>· 🔐 <?php echo esc_html($wc['cert_name']); ?></span><?php endif; ?>
                </div>
            </div>
            <button type="button" id="limpvix-efi-sync-wc" class="button button-primary" style="white-space:nowrap;font-size:12px;flex-shrink:0;">⬇ Usar estas credenciais</button>
        </div>
        <?php endif; ?>

        <!-- ── Formulário ── -->
        <form id="limpvix-efi-settings-form" style="margin:0;" enctype="multipart/form-data">
            <?php wp_nonce_field('limpvix_efi_actions', 'limpvix_efi_nonce'); ?>

            <!-- Secao: Credenciais API -->
            <div style="margin-bottom:20px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                    <span style="height:1px;flex:1;background:#e5e7eb;"></span>
                    <span>Credenciais da API</span>
                    <span style="height:1px;flex:1;background:#e5e7eb;"></span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <!-- Client ID -->
                    <div class="efi-field-wrap">
                        <label class="efi-label">Client ID <span style="color:#ef4444;">*</span></label>
                        <div class="efi-input-row">
                            <div class="efi-input-icon">🔑</div>
                            <input type="password" id="limpvix-efi-client-id" name="client_id"
                                   value="<?php echo esc_attr($config['client_id']); ?>"
                                   placeholder="Client_Id_…"
                                   class="efi-input efi-mono">
                            <button type="button" class="efi-eye-btn limpvix-efi-toggle-visibility" data-target="limpvix-efi-client-id" title="Mostrar/Ocultar">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Client Secret -->
                    <div class="efi-field-wrap">
                        <label class="efi-label">Client Secret <span style="color:#ef4444;">*</span></label>
                        <div class="efi-input-row">
                            <div class="efi-input-icon">🔒</div>
                            <input type="password" id="limpvix-efi-client-secret" name="client_secret"
                                   value="<?php echo esc_attr($config['client_secret']); ?>"
                                   placeholder="Client_Secret_…"
                                   class="efi-input efi-mono">
                            <button type="button" class="efi-eye-btn limpvix-efi-toggle-visibility" data-target="limpvix-efi-client-secret" title="Mostrar/Ocultar">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Secao: PIX -->
            <div style="margin-bottom:20px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                    <span style="height:1px;flex:1;background:#e5e7eb;"></span>
                    <span>Configuração PIX</span>
                    <span style="height:1px;flex:1;background:#e5e7eb;"></span>
                </div>
                <div class="efi-field-wrap">
                    <label class="efi-label">Chave PIX da Plataforma (Pagador) <span style="color:#ef4444;">*</span></label>
                    <div class="efi-input-row">
                        <div class="efi-input-icon">📲</div>
                        <input type="text" id="limpvix-efi-pix-key" name="pix_key"
                               value="<?php echo esc_attr($config['pix_key']); ?>"
                               placeholder="CPF, CNPJ, e-mail, telefone ou chave aleatória"
                               class="efi-input" style="flex:1;">
                    </div>
                    <div class="efi-hint">Chave PIX da conta EFI Bank da plataforma — conta que realizará os repasses aos profissionais</div>
                </div>
            </div>

            <!-- Secao: Certificado mTLS -->
            <div style="margin-bottom:20px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                    <span style="height:1px;flex:1;background:#e5e7eb;"></span>
                    <span>Certificado mTLS <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#d1d5db;">(produção)</span></span>
                    <span style="height:1px;flex:1;background:#e5e7eb;"></span>
                </div>

                <input type="hidden" id="limpvix-efi-cert-path" name="cert_path" value="<?php echo esc_attr($config['cert_path']); ?>">

                <!-- Dropzone -->
                <div id="limpvix-efi-cert-dropzone" class="efi-dropzone <?php echo $certExists ? 'efi-dropzone--has-file' : ''; ?>">
                    <input type="file" id="limpvix-efi-cert-file" accept=".pem,.crt,.p12" style="display:none;">

                    <!-- Empty state -->
                    <div id="efi-cert-empty" style="<?php echo $certExists ? 'display:none;' : ''; ?>text-align:center;">
                        <div style="font-size:36px;margin-bottom:8px;opacity:.6;">🔐</div>
                        <div style="font-weight:600;font-size:14px;color:#374151;margin-bottom:4px;">
                            Arraste o certificado aqui ou <span style="color:#3b82f6;cursor:pointer;" id="efi-cert-browse-trigger">clique para selecionar</span>
                        </div>
                        <div style="font-size:12px;color:#9ca3af;">Aceita .pem · .crt · .p12 — Máx. 2 MB</div>
                    </div>

                    <!-- File info (after upload) -->
                    <div id="efi-cert-info" style="<?php echo !$certExists ? 'display:none;' : ''; ?>display:<?php echo $certExists ? 'flex' : 'none'; ?>;align-items:center;gap:12px;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:40px;height:40px;background:linear-gradient(135deg,#10b981,#059669);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;">🔐</div>
                            <div>
                                <div style="font-weight:600;font-size:13px;color:#111827;" id="efi-cert-filename"><?php echo esc_html($certName); ?></div>
                                <div style="font-size:11px;color:#10b981;margin-top:2px;">
                                    ✓ Certificado carregado
                                    <?php if ($certExists): ?>— <span style="color:#6b7280;"><?php echo esc_html($config['cert_path']); ?></span><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <button type="button" id="efi-cert-remove" style="background:none;border:1px solid #fca5a5;color:#ef4444;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;font-weight:600;">✕ Remover</button>
                    </div>

                    <!-- Upload progress -->
                    <div id="efi-cert-uploading" style="display:none;text-align:center;">
                        <div style="font-size:24px;margin-bottom:8px;">⏳</div>
                        <div style="font-size:13px;color:#6b7280;">Enviando certificado…</div>
                        <div style="margin-top:10px;height:4px;background:#e5e7eb;border-radius:2px;overflow:hidden;">
                            <div id="efi-cert-progress-bar" style="height:100%;background:linear-gradient(90deg,#3b82f6,#10b981);width:0%;transition:width .3s;border-radius:2px;"></div>
                        </div>
                    </div>
                </div>
                <div class="efi-hint" style="margin-top:6px;">Gerado em <strong>Painel EFI → API → Gerenciar Certificados</strong>. Obrigatório em produção. Não necessário em sandbox.</div>
            </div>

            <!-- Secao: Ambiente -->
            <div style="margin-bottom:20px;">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#9ca3af;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                    <span style="height:1px;flex:1;background:#e5e7eb;"></span>
                    <span>Ambiente</span>
                    <span style="height:1px;flex:1;background:#e5e7eb;"></span>
                </div>

                <input type="hidden" id="limpvix-efi-sandbox" name="sandbox" value="<?php echo $config['sandbox'] ? 'yes' : 'no'; ?>">
                <div style="display:flex;gap:10px;">
                    <label id="efi-env-sandbox" class="efi-env-pill <?php echo $config['sandbox'] ? 'efi-env-pill--active efi-env-pill--sandbox' : ''; ?>" data-env="yes" style="cursor:pointer;flex:1;display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:10px;border:2px solid <?php echo $config['sandbox'] ? '#f59e0b' : '#e5e7eb'; ?>;background:<?php echo $config['sandbox'] ? '#fffbeb' : '#fafafa'; ?>;transition:all .2s;">
                        <span style="font-size:20px;">🧪</span>
                        <div>
                            <div style="font-weight:700;font-size:13px;color:<?php echo $config['sandbox'] ? '#92400e' : '#6b7280'; ?>;">Sandbox</div>
                            <div style="font-size:11px;color:#9ca3af;">Testes e Homologação</div>
                        </div>
                        <?php if ($config['sandbox']): ?><span style="margin-left:auto;font-size:16px;">✓</span><?php endif; ?>
                    </label>

                    <label id="efi-env-prod" class="efi-env-pill <?php echo !$config['sandbox'] ? 'efi-env-pill--active efi-env-pill--prod' : ''; ?>" data-env="no" style="cursor:pointer;flex:1;display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:10px;border:2px solid <?php echo !$config['sandbox'] ? '#10b981' : '#e5e7eb'; ?>;background:<?php echo !$config['sandbox'] ? '#f0fdf4' : '#fafafa'; ?>;transition:all .2s;">
                        <span style="font-size:20px;">🚀</span>
                        <div>
                            <div style="font-weight:700;font-size:13px;color:<?php echo !$config['sandbox'] ? '#065f46' : '#6b7280'; ?>;">Produção</div>
                            <div style="font-size:11px;color:#9ca3af;">Operação real</div>
                        </div>
                        <?php if (!$config['sandbox']): ?><span style="margin-left:auto;font-size:16px;">✓</span><?php endif; ?>
                    </label>
                </div>
                <div id="efi-env-hint" class="efi-hint" style="margin-top:8px;">
                    <?php echo $config['sandbox']
                        ? '🏖 Sandbox: <code>https://pix-h.api.efipay.com.br</code> — credenciais de homologação'
                        : '🚀 Produção: <code>https://pix.api.efipay.com.br</code> — credenciais de produção + certificado mTLS obrigatório'; ?>
                </div>
            </div>

            <!-- Botoes de Acao -->
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding-top:4px;">
                <button type="submit" id="limpvix-efi-save-btn" class="button button-primary" style="display:flex;align-items:center;gap:6px;padding:8px 18px;height:auto;font-size:13px;font-weight:600;">
                    <span class="dashicons dashicons-saved" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span> Salvar Credenciais
                </button>
                <button type="button" id="limpvix-efi-test-btn" class="button" style="display:flex;align-items:center;gap:6px;padding:8px 16px;height:auto;font-size:13px;">
                    <span class="dashicons dashicons-admin-network" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span> Testar OAuth
                </button>
                <button type="button" id="limpvix-efi-check-plugin-btn" class="button" style="display:flex;align-items:center;gap:6px;padding:8px 16px;height:auto;font-size:13px;">
                    <span class="dashicons dashicons-admin-plugins" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span> Verificar Plugin WC
                </button>
                <div id="limpvix-efi-result" style="display:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;flex:1;min-width:200px;transition:all .3s;"></div>
            </div>
        </form>

        <!-- Links Uteis -->
        <div style="margin-top:20px;padding-top:14px;border-top:1px solid #f3f4f6;display:flex;gap:16px;flex-wrap:wrap;">
            <a href="https://dev.efipay.com.br/docs/api-pix/credenciais/" target="_blank" style="font-size:12px;color:#3b82f6;text-decoration:none;display:flex;align-items:center;gap:4px;">📚 Docs API PIX</a>
            <a href="https://dev.efipay.com.br/docs/api-pix/endpoints-exclusivos-efi/" target="_blank" style="font-size:12px;color:#3b82f6;text-decoration:none;display:flex;align-items:center;gap:4px;">🔑 PIX Cash-Out</a>
            <a href="https://conta.efipay.com.br/" target="_blank" style="font-size:12px;color:#3b82f6;text-decoration:none;display:flex;align-items:center;gap:4px;">🏦 Painel EFI</a>
            <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout'); ?>" style="font-size:12px;color:#3b82f6;text-decoration:none;display:flex;align-items:center;gap:4px;">⚙️ Gateway WooCommerce</a>
        </div>
        <?php
    }

    private static function enqueueInlineScript(): void
    {
        static $enqueued = false;
        if ($enqueued) return;
        $enqueued = true;
        ?>
        <style>
        .efi-field-wrap { margin-bottom:0; }
        .efi-label { display:block; font-weight:600; font-size:12px; color:#374151; margin-bottom:6px; letter-spacing:.1px; }
        .efi-input-row { display:flex; align-items:center; background:#fff; border:1.5px solid #e5e7eb; border-radius:9px; overflow:hidden; transition:border-color .2s, box-shadow .2s; }
        .efi-input-row:focus-within { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.12); }
        .efi-input-icon { padding:0 10px; font-size:16px; color:#9ca3af; flex-shrink:0; }
        .efi-input { border:none !important; outline:none !important; box-shadow:none !important; background:transparent !important; padding:9px 8px !important; font-size:13px !important; flex:1; min-width:0; }
        .efi-mono { font-family:monospace !important; font-size:12px !important; }
        .efi-eye-btn { border:none !important; background:transparent !important; box-shadow:none !important; padding:0 10px !important; cursor:pointer; color:#9ca3af; display:flex; align-items:center; }
        .efi-eye-btn:hover { color:#374151 !important; }
        .efi-hint { font-size:11px; color:#9ca3af; margin-top:4px; line-height:1.5; }
        .efi-dropzone { border:2px dashed #d1d5db; border-radius:12px; padding:24px 20px; background:#fafafa; transition:all .2s; cursor:pointer; }
        .efi-dropzone:hover, .efi-dropzone--dragover { border-color:#3b82f6; background:#eff6ff; }
        .efi-dropzone--has-file { border-color:#10b981; background:#f0fdf4; border-style:solid; cursor:default; }
        .efi-env-pill { user-select:none; }
        </style>
        <script>
        jQuery(function($) {
            'use strict';
            var limpvixEfi = {
                nonce: '<?php echo wp_create_nonce('limpvix_efi_actions'); ?>',
                ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>'
            };

            function showResult(msg, ok) {
                var $r = $('#limpvix-efi-result');
                $r.show()
                  .css({ background: ok ? '#f0fdf4' : '#fef2f2',
                         color: ok ? '#065f46' : '#991b1b',
                         border: '1px solid ' + (ok ? '#bbf7d0' : '#fecaca') })
                  .html(msg);
                setTimeout(function() { $r.fadeOut(400, function() { $r.css('border',''); }); }, 5000);
            }

            // ── Toggle visibilidade password ──
            $(document).on('click', '.limpvix-efi-toggle-visibility', function(e) {
                e.preventDefault();
                var $i = $('#' + $(this).data('target'));
                var visible = $i.attr('type') === 'text';
                $i.attr('type', visible ? 'password' : 'text');
                $(this).find('.dashicons')
                    .toggleClass('dashicons-visibility', visible)
                    .toggleClass('dashicons-hidden', !visible);
            });

            // ── Ambiente (pills) ──
            function setEnv(val) {
                $('#limpvix-efi-sandbox').val(val);
                var isSandbox = val === 'yes';

                $('#efi-env-sandbox').css({
                    border: isSandbox ? '2px solid #f59e0b' : '2px solid #e5e7eb',
                    background: isSandbox ? '#fffbeb' : '#fafafa'
                }).find('> div > div:first-child').css('color', isSandbox ? '#92400e' : '#6b7280');

                $('#efi-env-prod').css({
                    border: !isSandbox ? '2px solid #10b981' : '2px solid #e5e7eb',
                    background: !isSandbox ? '#f0fdf4' : '#fafafa'
                }).find('> div > div:first-child').css('color', !isSandbox ? '#065f46' : '#6b7280');

                // Atualizar check mark
                $('#efi-env-sandbox, #efi-env-prod').each(function() {
                    var env = $(this).data('env');
                    var isActive = (env === 'yes' && isSandbox) || (env === 'no' && !isSandbox);
                    $(this).find('span:last-child').toggle(isActive);
                });

                $('#efi-env-hint').html(isSandbox
                    ? '🏖 Sandbox: <code>https://pix-h.api.efipay.com.br</code> — credenciais de homologação'
                    : '🚀 Produção: <code>https://pix.api.efipay.com.br</code> — credenciais de produção + certificado mTLS obrigatório');
            }

            $(document).on('click', '.efi-env-pill', function() {
                setEnv($(this).data('env'));
            });

            // ── Certificado — Dropzone ──
            var $dropzone = $('#limpvix-efi-cert-dropzone');

            function showCertEmpty() {
                $('#efi-cert-empty').show();
                $('#efi-cert-info').hide();
                $('#efi-cert-uploading').hide();
                $dropzone.removeClass('efi-dropzone--has-file');
                $('#limpvix-efi-cert-path').val('');
            }

            function showCertFile(name, path) {
                $('#efi-cert-filename').text(name);
                $('#efi-cert-empty').hide();
                $('#efi-cert-uploading').hide();
                $('#efi-cert-info').css('display', 'flex');
                $dropzone.addClass('efi-dropzone--has-file');
                $('#limpvix-efi-cert-path').val(path);
            }

            function showCertUploading() {
                $('#efi-cert-empty').hide();
                $('#efi-cert-info').hide();
                $('#efi-cert-uploading').show();
                $('#efi-cert-progress-bar').css('width', '0%');
                // Animate bar
                var w = 0;
                var iv = setInterval(function() {
                    w = Math.min(w + 8, 85);
                    $('#efi-cert-progress-bar').css('width', w + '%');
                    if (w >= 85) clearInterval(iv);
                }, 150);
                return iv;
            }

            function uploadCertFile(file) {
                if (file.size > 2 * 1024 * 1024) {
                    showResult('❌ Arquivo muito grande. Máximo 2 MB.', false);
                    return;
                }
                var iv = showCertUploading();
                var fd = new FormData();
                fd.append('action', 'limpvix_efi_upload_cert');
                fd.append('nonce', limpvixEfi.nonce);
                fd.append('cert_file', file);

                $.ajax({
                    url: limpvixEfi.ajaxUrl,
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    success: function(res) {
                        clearInterval(iv);
                        $('#efi-cert-progress-bar').css('width', '100%');
                        setTimeout(function() {
                            if (res.success) {
                                showCertFile(res.data.name, res.data.path);
                                showResult('✅ Certificado carregado: ' + res.data.name, true);
                            } else {
                                showCertEmpty();
                                showResult('❌ ' + (res.data ? res.data.message : 'Erro ao enviar'), false);
                            }
                        }, 300);
                    },
                    error: function() {
                        clearInterval(iv);
                        showCertEmpty();
                        showResult('❌ Falha de comunicação ao enviar o certificado', false);
                    }
                });
            }

            // Click para selecionar
            $('#efi-cert-browse-trigger, #limpvix-efi-cert-dropzone').on('click', function(e) {
                if ($dropzone.hasClass('efi-dropzone--has-file')) return;
                if (!$(e.target).is('#efi-cert-remove, #efi-cert-remove *')) {
                    $('#limpvix-efi-cert-file').click();
                }
            });

            $('#limpvix-efi-cert-file').on('change', function() {
                var f = this.files[0];
                if (f) uploadCertFile(f);
            });

            // Drag & drop
            $dropzone.on('dragover dragenter', function(e) {
                e.preventDefault(); e.stopPropagation();
                if (!$dropzone.hasClass('efi-dropzone--has-file'))
                    $dropzone.addClass('efi-dropzone--dragover');
            }).on('dragleave drop', function(e) {
                $dropzone.removeClass('efi-dropzone--dragover');
                if (e.type === 'drop') {
                    e.preventDefault(); e.stopPropagation();
                    var f = e.originalEvent.dataTransfer.files[0];
                    if (f) uploadCertFile(f);
                }
            });

            // Remover certificado
            $(document).on('click', '#efi-cert-remove', function(e) {
                e.stopPropagation();
                showCertEmpty();
                $('#limpvix-efi-cert-file').val('');
            });

            // ── Sync WC credentials ──
            $('#limpvix-efi-sync-wc').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Importando…');
                $.ajax({
                    url: limpvixEfi.ajaxUrl, type: 'POST',
                    data: { action: 'limpvix_efi_sync_wc_credentials', nonce: limpvixEfi.nonce },
                    success: function(res) {
                        if (res.success && res.data) {
                            var d = res.data;
                            if (d.client_id)     $('#limpvix-efi-client-id').val(d.client_id).attr('type','text');
                            if (d.client_secret) $('#limpvix-efi-client-secret').val(d.client_secret).attr('type','text');
                            if (d.pix_key)       $('#limpvix-efi-pix-key').val(d.pix_key);
                            if (d.sandbox !== undefined) setEnv(d.sandbox ? 'yes' : 'no');
                            if (d.cert_path)     { showCertFile(d.cert_name || d.cert_path.split('/').pop(), d.cert_path); }
                            showResult('✅ Credenciais importadas do plugin WC. Clique em Salvar para confirmar.', true);
                        } else {
                            showResult('❌ ' + ((res.data && res.data.message) || 'Erro ao importar'), false);
                        }
                    },
                    complete: function() { $btn.prop('disabled', false).text('⬇ Usar estas credenciais'); }
                });
            });

            // ── Salvar ──
            $('#limpvix-efi-settings-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $('#limpvix-efi-save-btn').prop('disabled', true);
                $btn.find('.dashicons').attr('class','dashicons dashicons-update-alt');
                var orig = $btn.clone(true);

                $.ajax({
                    url: limpvixEfi.ajaxUrl, type: 'POST',
                    data: {
                        action:        'limpvix_efi_save_settings',
                        nonce:         limpvixEfi.nonce,
                        client_id:     $('#limpvix-efi-client-id').val(),
                        client_secret: $('#limpvix-efi-client-secret').val(),
                        pix_key:       $('#limpvix-efi-pix-key').val(),
                        cert_path:     $('#limpvix-efi-cert-path').val(),
                        sandbox:       $('#limpvix-efi-sandbox').val()
                    },
                    success: function(res) {
                        showResult(res.success ? '✅ Credenciais salvas!' : '❌ ' + ((res.data && res.data.message) || 'Erro ao salvar'), res.success);
                        if (res.success) setTimeout(function() { location.reload(); }, 1400);
                    },
                    error: function() { showResult('❌ Erro de comunicação', false); },
                    complete: function() {
                        $btn.prop('disabled', false);
                        $btn.find('.dashicons').attr('class','dashicons dashicons-saved');
                    }
                });
            });

            // ── Testar Conexao ──
            $('#limpvix-efi-test-btn').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Testando…');
                $.ajax({
                    url: limpvixEfi.ajaxUrl, type: 'POST',
                    data: {
                        action:        'limpvix_efi_test_connection',
                        nonce:         limpvixEfi.nonce,
                        client_id:     $('#limpvix-efi-client-id').val(),
                        client_secret: $('#limpvix-efi-client-secret').val(),
                        sandbox:       $('#limpvix-efi-sandbox').val()
                    },
                    success: function(res) {
                        showResult(res.success ? '✅ ' + res.data.message : '❌ ' + ((res.data && res.data.message) || 'Falhou'), res.success);
                    },
                    error: function() { showResult('❌ Erro de comunicação', false); },
                    complete: function() { $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-network" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span> Testar OAuth'); }
                });
            });

            // ── Verificar Plugin WC ──
            $('#limpvix-efi-check-plugin-btn').on('click', function() {
                var $btn = $(this).prop('disabled', true).text('Verificando…');
                $.ajax({
                    url: limpvixEfi.ajaxUrl, type: 'POST',
                    data: { action: 'limpvix_efi_check_wc_plugin', nonce: limpvixEfi.nonce },
                    success: function(res) {
                        showResult(res.success ? '✅ ' + res.data.message : '⚠️ ' + ((res.data && res.data.message) || 'Plugin não encontrado'), res.success);
                    },
                    error: function() { showResult('❌ Erro de comunicação', false); },
                    complete: function() { $btn.prop('disabled', false).html('<span class="dashicons dashicons-admin-plugins" style="font-size:16px;width:16px;height:16px;margin-top:1px;"></span> Verificar Plugin WC'); }
                });
            });

        });
        </script>
        <?php
    }

    // ──────────────────────────────────────────────────────────
    // AJAX Handlers
    // ──────────────────────────────────────────────────────────


    public static function ajaxSaveSettings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
        }

        if (!check_ajax_referer('limpvix_efi_actions', 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce inválido'], 403);
        }

        $client_id     = sanitize_text_field($_POST['client_id']     ?? '');
        $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
        $pix_key       = sanitize_text_field($_POST['pix_key']       ?? '');
        $cert_path     = sanitize_text_field($_POST['cert_path']     ?? '');
        $sandbox       = ($_POST['sandbox'] ?? 'no') === 'yes' ? 'yes' : 'no';

        // Validar campos obrigatórios
        $missing = [];
        if (empty($client_id))     $missing[] = 'Client ID';
        if (empty($client_secret)) $missing[] = 'Client Secret';
        if (empty($pix_key))       $missing[] = 'Chave PIX';

        if (!empty($missing)) {
            wp_send_json_error(['message' => 'Campos obrigatórios: ' . implode(', ', $missing)]);
        }

        // Validar cert path se fornecido
        if (!empty($cert_path) && !file_exists($cert_path)) {
            wp_send_json_error(['message' => "Certificado não encontrado: {$cert_path}"]);
        }

        // Salvar
        update_option(self::OPT_CLIENT_ID,     $client_id);
        update_option(self::OPT_CLIENT_SECRET, $client_secret);
        update_option(self::OPT_PIX_KEY,       $pix_key);
        update_option(self::OPT_CERT_PATH,     $cert_path);
        update_option(self::OPT_SANDBOX,       $sandbox);

        // Limpar token cacheado (forçar re-autenticação)
        delete_transient('limpvix_efi_access_token');

        wp_send_json_success(['message' => 'Credenciais salvas com sucesso']);
    }

    public static function ajaxTestConnection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
        }

        if (!check_ajax_referer('limpvix_efi_actions', 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce inválido'], 403);
        }

        $client_id     = sanitize_text_field($_POST['client_id']     ?? '');
        $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
        $sandbox       = ($_POST['sandbox'] ?? 'no') === 'yes';

        if (empty($client_id) || empty($client_secret)) {
            wp_send_json_error(['message' => 'Informe Client ID e Client Secret antes de testar']);
        }

        // Certificado mTLS (obrigatório pela API EFI Bank — inclusive no sandbox)
        $cert_path = sanitize_text_field($_POST['cert_path'] ?? '') ?: get_option(self::OPT_CERT_PATH, '');

        $base_url = $sandbox ? self::API_SANDBOX : self::API_PRODUCTION;
        $basic    = base64_encode("{$client_id}:{$client_secret}");

        // A API EFI exige mTLS — usar cURL com certificado cliente
        $ch = curl_init();
        $curl_opts = [
            CURLOPT_URL            => $base_url . '/oauth/token',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => [
                "Authorization: Basic {$basic}",
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ];

        if (!empty($cert_path) && file_exists($cert_path)) {
            $curl_opts[CURLOPT_SSLCERT] = $cert_path;
            $curl_opts[CURLOPT_SSLKEY]  = $cert_path;
        }

        curl_setopt_array($ch, $curl_opts);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            wp_send_json_error(['message' => "Erro de rede (cURL): {$err}"]);
        }

        $body = json_decode($resp, true) ?? [];

        if ($status === 200 && !empty($body['access_token'])) {
            $env   = $sandbox ? 'Sandbox' : 'Produção';
            $scope = $body['scope'] ?? 'pix';
            $cert_info = (!empty($cert_path) && file_exists($cert_path))
                ? ' · mTLS: ' . basename($cert_path)
                : ' · Sem certificado (mTLS)';
            wp_send_json_success([
                'message'     => "OAuth OK! Ambiente: {$env}{$cert_info}. Escopos: {$scope}.",
                'scope'       => $scope,
                'environment' => $env,
            ]);
        }

        $err_msg = $body['error_description'] ?? ($body['message'] ?? "HTTP {$status}");
        if ($status === 0 || $status === 56) {
            $err_msg = 'Conexão recusada — verifique se o certificado mTLS está configurado corretamente';
        }
        wp_send_json_error(['message' => "OAuth falhou: {$err_msg}"]);
    }

    /** AJAX: Importar credenciais do plugin WooCommerce EFI */
    public static function ajaxSyncWcCredentials(): void
    {
        check_ajax_referer('limpvix_efi_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão']);
        }

        $creds = self::getWcPluginCredentials();

        if (empty($creds['client_id'])) {
            wp_send_json_error(['message' => 'Nenhuma credencial encontrada no plugin WooCommerce EFI']);
        }

        // Localizar o arquivo de certificado real do plugin WC
        if (!empty($creds['cert_name'])) {
            $plugin_dir = WP_PLUGIN_DIR . '/woo-gerencianet-official';
            $found_cert = '';
            if (is_dir($plugin_dir)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($plugin_dir));
                foreach ($it as $file) {
                    if ($file->isFile() && $file->getFilename() === $creds['cert_name']) {
                        $found_cert = $file->getPathname();
                        break;
                    }
                }
            }
            $creds['cert_path'] = $found_cert;
        }

        wp_send_json_success($creds);
    }

    /** AJAX: Upload do certificado mTLS */
    public static function ajaxUploadCertificate(): void
    {
        check_ajax_referer('limpvix_efi_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão']);
        }

        if (empty($_FILES['cert_file'])) {
            wp_send_json_error(['message' => 'Nenhum arquivo enviado']);
        }

        $file = $_FILES['cert_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'Erro no upload: código ' . $file['error']]);
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            wp_send_json_error(['message' => 'Arquivo muito grande. Máximo 2 MB.']);
        }

        // Validar extensão
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pem', 'crt', 'p12'], true)) {
            wp_send_json_error(['message' => 'Tipo inválido. Aceito: .pem, .crt, .p12']);
        }

        // Criar diretório protegido
        $upload_dir = wp_upload_dir();
        $cert_dir   = $upload_dir['basedir'] . '/limpvix-certs';

        if (!file_exists($cert_dir)) {
            wp_mkdir_p($cert_dir);
            file_put_contents($cert_dir . '/.htaccess', "deny from all\n");
            file_put_contents($cert_dir . '/index.php', "<?php // Silence is golden\n");
        }

        $safe_name = 'efi-cert-' . time() . '.' . $ext;
        $dest_path = $cert_dir . '/' . $safe_name;

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            wp_send_json_error(['message' => 'Falha ao salvar o arquivo no servidor']);
        }

        wp_send_json_success([
            'name' => $safe_name,
            'path' => $dest_path,
        ]);
    }

        public static function ajaxCheckWcPlugin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada'], 403);
        }

        if (!check_ajax_referer('limpvix_efi_actions', 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce inválido'], 403);
        }

        $installed = self::isWcPluginInstalled();
        $active    = self::isWcPluginActive();

        if ($active) {
            wp_send_json_success(['message' => 'Plugin woo-gerencianet-official está ativo. Checkout PIX/Cartão disponível.', 'status' => 'active']);
        } elseif ($installed) {
            wp_send_json_error(['message' => 'Plugin instalado mas inativo. Ative em Plugins → woo-gerencianet-official.', 'status' => 'inactive']);
        } else {
            wp_send_json_error(['message' => 'Plugin não encontrado. Instale o woo-gerencianet-official para checkout PIX transparente.', 'status' => 'not_installed']);
        }
    }
}
