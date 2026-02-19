<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class RiskTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'risk'; }
    public function getLabel(): string { return 'Risk'; }
    public function getIcon(): string { return '&#x1F6E1;'; }

    public function handleSave(): void
    {
        if (!isset($_POST['limpvix_save_risk_settings'])) {
            return;
        }
        if (!check_admin_referer('limpvix_risk_settings')) {
            return;
        }

        // Policy Engine — review categories
        $reviewCategories = array_map('sanitize_text_field', (array) ($_POST['policy_review_categories'] ?? []));
        update_option('limpvix_policy_review_categories', $reviewCategories);

        // Min KYC confidence
        update_option('limpvix_policy_min_kyc_confidence',
            max(50, min(100, intval($_POST['min_kyc_confidence'] ?? 85))));

        // KYC toggle (use string '1'/'0' — WordPress update_option with boolean false doesn't persist reliably)
        update_option('limpvix_kyc_verification_enabled', isset($_POST['kyc_verification_enabled']) ? '1' : '0');

        // Background Check toggle + settings
        update_option('limpvix_background_check_enabled', isset($_POST['background_check_enabled']) ? '1' : '0');
        update_option('limpvix_prof_background_check_validity_days', max(30, intval($_POST['background_check_validity_days'] ?? 365)));
        update_option('limpvix_prof_background_check_use_mock', isset($_POST['background_check_use_mock']) ? '1' : '0');

        wp_redirect(add_query_arg(['page' => 'limpvix-settings', 'tab' => 'risk', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        // Load all options
        $ppidConnected  = $this->isPpidConnected();
        $exatoConnected = !empty(get_option('limpvix_exato_api_key')) && !empty(get_option('limpvix_exato_token'));
        $policyCategories = (array) get_option('limpvix_policy_review_categories', []);
        $minKycConfidence = (int) get_option('limpvix_policy_min_kyc_confidence', 85);

        // Read toggles — filter_var handles both legacy boolean and new string '1'/'0' values
        $kycEnabled = filter_var(get_option('limpvix_kyc_verification_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
        $bgEnabled  = filter_var(get_option('limpvix_background_check_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        $bgCheckValidityDays = get_option('limpvix_prof_background_check_validity_days', 365);
        $bgCheckUseMock = filter_var(get_option('limpvix_prof_background_check_use_mock', '1'), FILTER_VALIDATE_BOOLEAN);

        // Stats
        $verificationStats = $this->getVerificationStats();
        $bgStats = $this->getBackgroundStats();

        // Categories aligned with BackgroundResult constants
        $allReviewCategories = [
            'FRAUD_RELEVANT' => 'Fraude / Estelionato',
            'DRUGS'          => 'Tr&aacute;fico / Uso de entorpecentes',
            'THEFT'          => 'Crime contra patrim&ocirc;nio (furto, roubo)',
            'OTHER'          => 'Outras categorias',
        ];
        ?>

        <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible" style="margin-bottom: 15px;">
                <p><strong>&#x2705; Configura&ccedil;&otilde;es de Risk &amp; Compliance salvas com sucesso!</strong></p>
            </div>
        <?php endif; ?>

        <!-- SECTION 1: Hero Dashboard -->
        <div class="limpvix-card" style="background: linear-gradient(135deg, #dc2626 0%, #7c3aed 100%); color: white; margin-bottom: 20px; border: none; box-shadow: 0 10px 40px rgba(124, 58, 237, 0.3);">
            <div style="padding: 30px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div>
                        <h1 style="color: white; margin: 0 0 10px 0; font-size: 28px; font-weight: 600;">
                            <span class="dashicons dashicons-shield" style="font-size: 28px; width: 28px; height: 28px; margin-right: 8px;"></span>
                            Risk &amp; Compliance
                        </h1>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 15px;">
                            Pipeline: OTP &#x2192; KYC Biom&eacute;trico &#x2192; Background Check &#x2192; Policy Engine
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); padding: 15px 25px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.3);">
                            <div style="font-size: 32px; font-weight: 700; color: white; line-height: 1;">
                                <?php echo (int) $verificationStats['total']; ?>
                            </div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8); margin-top: 5px; font-weight: 500;">
                                Verifica&ccedil;&otilde;es
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-top: 25px;">
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo (int) $verificationStats['total']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Total</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo (int) $verificationStats['approved']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Aprovados</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo (int) $verificationStats['under_review']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Em Revis&atilde;o</div>
                    </div>
                    <div style="background: rgba(255,255,255,0.15); padding: 20px; border-radius: 8px; text-align: center; backdrop-filter: blur(10px);">
                        <div style="font-size: 32px; font-weight: bold; margin-bottom: 5px;"><?php echo (int) $verificationStats['blocked']; ?></div>
                        <div style="font-size: 13px; opacity: 0.9;">Bloqueados</div>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" action="">
            <?php wp_nonce_field('limpvix_risk_settings'); ?>
            <input type="hidden" name="limpvix_save_risk_settings" value="1">

            <!-- SECTION 2: Status dos Providers -->
            <div class="limpvix-grid limpvix-grid-2" style="margin-bottom: 20px;">
                <div class="limpvix-card">
                    <div class="limpvix-card-body" style="padding: 20px;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <strong><span class="dashicons dashicons-id" style="color: #3b82f6;"></span> PPID &mdash; KYC Biom&eacute;trico</strong>
                                <br><small style="color: #666;">Verifica&ccedil;&atilde;o de identidade (OCR + Liveness + FaceMatch)</small>
                            </div>
                            <div>
                                <?php if ($ppidConnected): ?>
                                    <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Provider Real</span>
                                <?php else: ?>
                                    <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">Mock</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="limpvix-card">
                    <div class="limpvix-card-body" style="padding: 20px;">
                        <div style="display: flex; align-items: center; justify-content: space-between;">
                            <div>
                                <strong><span class="dashicons dashicons-search" style="color: #7c3aed;"></span> Exato Digital &mdash; Background</strong>
                                <br><small style="color: #666;">Antecedentes criminais e an&aacute;lise de risco</small>
                            </div>
                            <div>
                                <?php if ($exatoConnected): ?>
                                    <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Provider Real</span>
                                <?php else: ?>
                                    <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">Mock</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div style="margin-bottom: 20px;">
                <small style="color: #6b7280;">
                    <span class="dashicons dashicons-admin-links" style="font-size: 14px; width: 14px; height: 14px;"></span>
                    Credenciais dos providers: <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=conexoes'); ?>"><strong>Conex&otilde;es</strong></a>
                </small>
            </div>

            <!-- SECTION 3: KYC Biometrico -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header" style="border-left: 4px solid #3b82f6;">
                    <h3>
                        <span class="dashicons dashicons-id"></span>
                        KYC Biom&eacute;trico &mdash; Verifica&ccedil;&atilde;o de Identidade
                    </h3>
                    <p>OCR de Documentos | Liveness Detection | Face Match | Aprova&ccedil;&atilde;o Autom&aacute;tica</p>
                </div>
                <div class="limpvix-card-body">
                    <?php
                    $ppidEnabled = get_option('limpvix_ppid_enabled', false);
                    $ppidEmail = get_option('limpvix_ppid_email', '');
                    ?>
                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td style="width: 250px;"><strong>KYC Obrigat&oacute;rio</strong>
                                    <br><small style="color: #666;">Profissionais sem KYC n&atilde;o aceitam ofertas</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="kyc_verification_enabled" value="1" <?php checked($kycEnabled); ?>>
                                        Ativo
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $kycEnabled ? 'success' : 'gray'; ?>" style="margin-left: 10px;">
                                        <?php echo $kycEnabled ? 'Obrigat&oacute;rio' : 'Desativado'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Status do Provider</strong></td>
                                <td>
                                    <?php if ($ppidEnabled && !empty($ppidEmail)): ?>
                                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">PPID Ativo</span>
                                    <?php elseif ($ppidConnected): ?>
                                        <span class="limpvix-badge limpvix-badge-info limpvix-badge-dot">PPID Configurado (via API Key)</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">N&atilde;o Configurado (Mock)</span>
                                    <?php endif; ?>
                                    <br><small><a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=conexoes'); ?>">Configurar credenciais PPID</a></small>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Confian&ccedil;a M&iacute;nima KYC</strong>
                                    <br><small style="color: #666;">Abaixo desse % &#x2192; UNDER_REVIEW<br>Usado por PolicyEngine</small>
                                </td>
                                <td>
                                    <input type="number" name="min_kyc_confidence" value="<?php echo esc_attr($minKycConfidence); ?>"
                                           min="50" max="100" class="small-text" style="width: 80px;"> %
                                    <p class="description">Recomendado: 85%. M&iacute;nimo: 50%. M&aacute;ximo: 100%.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <?php if ($kycEnabled): ?>
                    <div style="padding: 10px 14px; background: #fff3cd; border-left: 4px solid #f0ad4e; border-radius: 4px; margin-top: 15px; font-size: 13px;">
                        <strong><span class="dashicons dashicons-warning" style="color: #f0ad4e; font-size: 16px; width: 16px; height: 16px;"></span> Ativo:</strong>
                        Profissionais SEM KYC aprovado N&Atilde;O podem aceitar ofertas de trabalho.
                    </div>
                    <?php else: ?>
                    <div style="padding: 10px 14px; background: #e8f4fd; border-left: 4px solid #2196F3; border-radius: 4px; margin-top: 15px; font-size: 13px;">
                        <strong><span class="dashicons dashicons-info" style="color: #2196F3; font-size: 16px; width: 16px; height: 16px;"></span> Desabilitado:</strong>
                        Profissionais podem trabalhar sem KYC (modo desenvolvimento).
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECTION 4: Background Check -->
            <?php
            $exatoApiKey = get_option('limpvix_exato_api_key', '');
            $exatoEnabled = !empty($exatoApiKey);
            $bgMockActive = $bgCheckUseMock || !$exatoEnabled;
            ?>
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header" style="border-left: 4px solid #7c3aed;">
                    <h3>
                        <span class="dashicons dashicons-visibility"></span>
                        Background Check &mdash; Antecedentes Criminais
                    </h3>
                    <p>Consulta autom&aacute;tica via Exato Digital | An&aacute;lise de risco | Categoriza&ccedil;&atilde;o</p>
                </div>
                <div class="limpvix-card-body">
                    <!-- Stats mini-cards -->
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px;">
                        <div style="padding: 12px; background: <?php echo $exatoEnabled ? '#f0fdf4' : '#fef9f1'; ?>; border: 1px solid <?php echo $exatoEnabled ? '#bbf7d0' : '#fed7aa'; ?>; border-radius: 8px; text-align: center;">
                            <?php echo $exatoEnabled ? '<span class="dashicons dashicons-yes-alt" style="color: #16a34a;"></span>' : '<span class="dashicons dashicons-warning" style="color: #f59e0b;"></span>'; ?>
                            <br><strong style="font-size: 12px;">Exato Digital</strong>
                            <br><small><?php echo $exatoEnabled ? 'Configurado' : 'N&atilde;o configurado'; ?></small>
                        </div>
                        <div style="padding: 12px; background: <?php echo $bgMockActive ? '#fef9f1' : '#f0fdf4'; ?>; border: 1px solid <?php echo $bgMockActive ? '#fed7aa' : '#bbf7d0'; ?>; border-radius: 8px; text-align: center;">
                            <?php echo $bgMockActive ? '<span class="dashicons dashicons-admin-tools" style="color: #f59e0b;"></span>' : '<span class="dashicons dashicons-migrate" style="color: #16a34a;"></span>'; ?>
                            <br><strong style="font-size: 12px;">Modo</strong>
                            <br><small><?php echo $bgMockActive ? 'Mock (Teste)' : 'Real (Produ&ccedil;&atilde;o)'; ?></small>
                        </div>
                        <div style="padding: 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; text-align: center;">
                            <span class="dashicons dashicons-chart-pie" style="color: #3b82f6;"></span>
                            <br><strong style="font-size: 12px;">Aprovados</strong>
                            <br><small><?php echo (int) ($bgStats['approved'] ?? 0); ?> / <?php echo (int) ($bgStats['total'] ?? 0); ?></small>
                        </div>
                        <div style="padding: 12px; background: <?php echo ((int)($bgStats['expired'] ?? 0)) > 0 ? '#fef2f2' : '#f0fdf4'; ?>; border: 1px solid <?php echo ((int)($bgStats['expired'] ?? 0)) > 0 ? '#fecaca' : '#bbf7d0'; ?>; border-radius: 8px; text-align: center;">
                            <span class="dashicons dashicons-clock" style="color: <?php echo ((int)($bgStats['expired'] ?? 0)) > 0 ? '#dc2626' : '#16a34a'; ?>;"></span>
                            <br><strong style="font-size: 12px;">Expirados</strong>
                            <br><small><?php echo (int) ($bgStats['expired'] ?? 0); ?></small>
                        </div>
                    </div>

                    <table class="limpvix-table">
                        <tbody>
                            <tr>
                                <td style="width: 250px;"><strong>Background Obrigat&oacute;rio</strong>
                                    <br><small style="color: #666;">Profissional precisa check aprovado para ofertas</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="background_check_enabled" value="1" <?php checked($bgEnabled); ?>>
                                        Ativo
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $bgEnabled ? 'success' : 'gray'; ?>" style="margin-left: 10px;">
                                        <?php echo $bgEnabled ? 'Obrigat&oacute;rio' : 'Desativado'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Usar Provider Mock</strong>
                                    <br><small style="color: #666;">Simula aprova&ccedil;&atilde;o, n&atilde;o consome cr&eacute;ditos</small>
                                </td>
                                <td>
                                    <label>
                                        <input type="checkbox" name="background_check_use_mock" value="1" <?php checked($bgCheckUseMock); ?>>
                                        Modo Mock
                                    </label>
                                    <span class="limpvix-badge limpvix-badge-<?php echo $bgCheckUseMock ? 'warning' : 'info'; ?>" style="margin-left: 10px;">
                                        <?php echo $bgCheckUseMock ? 'Mock ativo' : 'Produ&ccedil;&atilde;o'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Validade do Check</strong>
                                    <br><small style="color: #666;">Ap&oacute;s expirar, revalida&ccedil;&atilde;o necess&aacute;ria</small>
                                </td>
                                <td>
                                    <input type="number" name="background_check_validity_days" value="<?php echo esc_attr($bgCheckValidityDays); ?>"
                                           min="30" max="730" class="small-text" style="width: 80px;"> dias
                                    <p class="description">Recomendado: 365 dias (anual). M&iacute;nimo: 30 dias.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Distribution stats -->
                    <?php if ((int) ($bgStats['total'] ?? 0) > 0): ?>
                    <div style="padding: 12px 15px; background: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 4px; margin-top: 15px; font-size: 13px;">
                        <strong>Distribui&ccedil;&atilde;o:</strong>
                        <span class="limpvix-badge limpvix-badge-success" style="margin-left: 8px;">Approved: <?php echo (int) ($bgStats['approved'] ?? 0); ?></span>
                        <span class="limpvix-badge limpvix-badge-warning" style="margin-left: 4px;">Restricted: <?php echo (int) ($bgStats['restricted'] ?? 0); ?></span>
                        <span class="limpvix-badge limpvix-badge-danger" style="margin-left: 4px;">Not Eligible: <?php echo (int) ($bgStats['not_eligible'] ?? 0); ?></span>
                        <span class="limpvix-badge limpvix-badge-gray" style="margin-left: 4px;">Pending: <?php echo (int) ($bgStats['pending'] ?? 0); ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($bgEnabled): ?>
                    <div style="padding: 10px 14px; background: #fff3cd; border-left: 4px solid #f0ad4e; border-radius: 4px; margin-top: 15px; font-size: 13px;">
                        <strong><span class="dashicons dashicons-warning" style="color: #f0ad4e; font-size: 16px; width: 16px; height: 16px;"></span> Ativo:</strong>
                        Profissional precisa background check aprovado para aceitar ofertas.
                    </div>
                    <?php else: ?>
                    <div style="padding: 10px 14px; background: #e8f4fd; border-left: 4px solid #2196F3; border-radius: 4px; margin-top: 15px; font-size: 13px;">
                        <strong><span class="dashicons dashicons-info" style="color: #2196F3; font-size: 16px; width: 16px; height: 16px;"></span> Desabilitado:</strong>
                        Background check n&atilde;o bloqueia profissionais.
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SECTION 5: Policy Engine -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header" style="border-left: 4px solid #dc2626;">
                    <h3>
                        <span class="dashicons dashicons-shield-alt"></span>
                        Policy Engine &mdash; Regras de Elegibilidade
                    </h3>
                    <p>Motor de decis&atilde;o autom&aacute;tica para status dos profissionais</p>
                </div>
                <div class="limpvix-card-body">
                    <!-- Immutable blockers -->
                    <h4 style="margin: 0 0 10px 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <span class="dashicons dashicons-dismiss" style="color: #dc2626;"></span>
                        Bloqueadores Imut&aacute;veis (sempre NOT_ELIGIBLE)
                    </h4>
                    <div style="display: flex; gap: 12px; margin-bottom: 20px;">
                        <div style="padding: 10px 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; flex: 1;">
                            <span class="limpvix-badge limpvix-badge-danger">SEXUAL_CRIME</span>
                            <br><small style="color: #991b1b;">Crimes sexuais &mdash; bloqueio permanente</small>
                        </div>
                        <div style="padding: 10px 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; flex: 1;">
                            <span class="limpvix-badge limpvix-badge-danger">VIOLENT_CRIME</span>
                            <br><small style="color: #991b1b;">Crimes violentos com v&iacute;tima &mdash; bloqueio permanente</small>
                        </div>
                    </div>

                    <!-- Configurable categories -->
                    <h4 style="margin: 0 0 10px 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <span class="dashicons dashicons-admin-settings" style="color: #f59e0b;"></span>
                        Categorias Config&uacute;r&aacute;veis (geram UNDER_REVIEW)
                    </h4>
                    <table class="limpvix-table" style="margin-bottom: 15px;">
                        <tbody>
                            <?php foreach ($allReviewCategories as $value => $label): ?>
                            <tr>
                                <td style="width: 200px;">
                                    <label style="cursor: pointer;">
                                        <input type="checkbox"
                                               name="policy_review_categories[]"
                                               value="<?php echo esc_attr($value); ?>"
                                               <?php checked(in_array($value, $policyCategories, true)); ?>>
                                        <strong><?php echo esc_html($value); ?></strong>
                                    </label>
                                </td>
                                <td>
                                    <small style="color: #666;"><?php echo $label; ?></small>
                                    <?php if (in_array($value, $policyCategories, true)): ?>
                                        <span class="limpvix-badge limpvix-badge-warning" style="margin-left: 8px;">Revis&atilde;o</span>
                                    <?php else: ?>
                                        <span class="limpvix-badge limpvix-badge-gray" style="margin-left: 8px;">Ignorado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="padding: 10px 14px; background: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 4px; font-size: 12px; margin-bottom: 20px;">
                        Categorias <strong>marcadas</strong> &#x2192; profissional vai para UNDER_REVIEW (revis&atilde;o manual).
                        Categorias <strong>n&atilde;o marcadas</strong> &#x2192; aprovado com monitoramento (ACTIVE_MONITORED).
                    </div>

                    <!-- Pipeline de Decisao -->
                    <h4 style="margin: 0 0 10px 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <span class="dashicons dashicons-randomize" style="color: #7c3aed;"></span>
                        Pipeline de Decis&atilde;o (PolicyEngine)
                    </h4>
                    <div style="background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; font-size: 13px; font-family: monospace; line-height: 2;">
                        <span style="color: #6b7280;">1.</span> OTP n&atilde;o verificado &#x2192; <span class="limpvix-badge limpvix-badge-gray">PENDING_VERIFICATION</span><br>
                        <span style="color: #6b7280;">2.</span> KYC reprovado &#x2192; <span class="limpvix-badge limpvix-badge-danger">NOT_ELIGIBLE</span><br>
                        <span style="color: #6b7280;">3.</span> Fraude detectada &#x2192; <span class="limpvix-badge limpvix-badge-danger">NOT_ELIGIBLE</span><br>
                        <span style="color: #6b7280;">4.</span> Confian&ccedil;a KYC &lt; <?php echo esc_html($minKycConfidence); ?>% &#x2192; <span class="limpvix-badge limpvix-badge-warning">UNDER_REVIEW</span><br>
                        <span style="color: #6b7280;">5.</span> Background NOT_ELIGIBLE &#x2192; <span class="limpvix-badge limpvix-badge-danger">NOT_ELIGIBLE</span><br>
                        <span style="color: #6b7280;">6.</span> Hard-block (SEXUAL / VIOLENT) &#x2192; <span class="limpvix-badge limpvix-badge-danger">NOT_ELIGIBLE</span><br>
                        <span style="color: #6b7280;">7.</span> Risco HIGH &#x2192; <span class="limpvix-badge limpvix-badge-danger">NOT_ELIGIBLE</span><br>
                        <span style="color: #6b7280;">8.</span> Categoria config. match &#x2192; <span class="limpvix-badge limpvix-badge-warning">UNDER_REVIEW</span><br>
                        <span style="color: #6b7280;">9.</span> Background RESTRICTED &#x2192; <span class="limpvix-badge limpvix-badge-warning">UNDER_REVIEW</span><br>
                        <span style="color: #6b7280;">10.</span> Score final &#x2192; <span class="limpvix-badge limpvix-badge-success">ACTIVE</span> / <span class="limpvix-badge limpvix-badge-info">MONITORED</span> / <span class="limpvix-badge limpvix-badge-warning">REVIEW</span> / <span class="limpvix-badge limpvix-badge-danger">SUSPENDED</span>
                    </div>

                    <!-- Score Formula -->
                    <h4 style="margin: 0 0 10px 0; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 8px;">
                        <span class="dashicons dashicons-performance" style="color: #059669;"></span>
                        F&oacute;rmula de Score Final
                    </h4>
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px 20px; margin-bottom: 15px;">
                        <div style="font-family: monospace; font-size: 15px; color: #166534; margin-bottom: 12px; text-align: center;">
                            Score = (Identidade &times; <strong>30%</strong>) + (Background &times; <strong>40%</strong>) + (Comportamento &times; <strong>30%</strong>)
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; font-size: 12px; text-align: center;">
                            <div style="padding: 8px; background: #dcfce7; border-radius: 4px;">
                                <strong>&#x2265; 85</strong><br>
                                <span class="limpvix-badge limpvix-badge-success">ACTIVE</span>
                            </div>
                            <div style="padding: 8px; background: #dbeafe; border-radius: 4px;">
                                <strong>70 &ndash; 84</strong><br>
                                <span class="limpvix-badge limpvix-badge-info">MONITORED</span>
                            </div>
                            <div style="padding: 8px; background: #fef3c7; border-radius: 4px;">
                                <strong>50 &ndash; 69</strong><br>
                                <span class="limpvix-badge limpvix-badge-warning">REVIEW</span>
                            </div>
                            <div style="padding: 8px; background: #fecaca; border-radius: 4px;">
                                <strong>&lt; 50</strong><br>
                                <span class="limpvix-badge limpvix-badge-danger">SUSPENDED</span>
                            </div>
                        </div>
                    </div>
                    <div style="padding: 10px 14px; background: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 4px; font-size: 12px;">
                        <strong>Identidade:</strong> Confian&ccedil;a do KYC (0-100) |
                        <strong>Background:</strong> LOW=100, MEDIUM=60, HIGH=20 |
                        <strong>Comportamento:</strong> Score de feedback e hist&oacute;rico (0-100)
                    </div>
                </div>
            </div>

            <!-- SECTION 6: Verificacoes Expiradas -->
            <div class="limpvix-card" style="margin-bottom: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-backup"></span>
                        Verifica&ccedil;&otilde;es Expiradas
                    </h3>
                    <p>Background checks que ultrapassaram a validade configurada</p>
                </div>
                <div class="limpvix-card-body">
                    <?php $expiredCount = (int) ($bgStats['expired'] ?? 0); ?>
                    <?php if ($expiredCount === 0): ?>
                    <div style="padding: 12px 15px; background: #f0fdf4; border-left: 4px solid #22c55e; border-radius: 4px;">
                        <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Nenhuma verifica&ccedil;&atilde;o expirada</span>
                        <br><small style="color: #166534;">Todos os profissionais ativos est&atilde;o com background check v&aacute;lido.</small>
                    </div>
                    <?php else: ?>
                    <div style="padding: 12px 15px; background: #fef2f2; border-left: 4px solid #dc2626; border-radius: 4px;">
                        <span class="limpvix-badge limpvix-badge-danger"><?php echo $expiredCount; ?> verifica&ccedil;&otilde;es expiradas</span>
                        <br><small style="color: #991b1b;">
                            Profissionais ativos com background check expirado. Revalida&ccedil;&atilde;o necess&aacute;ria.
                            <br>Validade configurada: <strong><?php echo esc_html($bgCheckValidityDays); ?> dias</strong>.
                        </small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-large">
                    <span class="dashicons dashicons-saved" style="margin-top: 5px;"></span>
                    Salvar Risk &amp; Compliance
                </button>
            </p>
        </form>
        <?php
    }

    // ========================================================================
    // PRIVATE HELPERS
    // ========================================================================

    /**
     * Check if PPID KYC provider is connected (replicates PpidKycProvider::isConnected logic)
     */
    private function isPpidConnected(): bool
    {
        $email  = (string) get_option('limpvix_ppid_email', '');
        $senha  = (string) get_option('limpvix_ppid_senha', '');
        $apiKey = (string) get_option('limpvix_ppid_api_key', '');
        return (!empty($email) && !empty($senha)) || !empty($apiKey);
    }

    /**
     * Get verification stats from wp_limpvix_professional_verification (safe)
     */
    private function getVerificationStats(): array
    {
        $defaults = ['total' => 0, 'approved' => 0, 'under_review' => 0, 'blocked' => 0, 'pending' => 0];

        global $wpdb;
        if (!$wpdb) {
            return $defaults;
        }

        $table = $wpdb->prefix . 'limpvix_professional_verification';
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table
        )) == 0) {
            return $defaults;
        }

        $row = $wpdb->get_row("SELECT
            COUNT(*) AS total,
            SUM(final_status IN ('ACTIVE','ACTIVE_MONITORED')) AS approved,
            SUM(final_status = 'UNDER_REVIEW') AS under_review,
            SUM(final_status IN ('NOT_ELIGIBLE','SUSPENDED')) AS blocked,
            SUM(final_status = 'PENDING_VERIFICATION') AS pending
            FROM `{$table}`", ARRAY_A);

        return $row ? array_map('intval', $row) : $defaults;
    }

    /**
     * Get background check stats with CORRECT enum values
     */
    private function getBackgroundStats(): array
    {
        $defaults = ['total' => 0, 'approved' => 0, 'restricted' => 0, 'not_eligible' => 0, 'pending' => 0, 'expired' => 0];

        global $wpdb;
        if (!$wpdb) {
            return $defaults;
        }

        $table = $wpdb->prefix . 'limpvix_professional_verification';
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME, $table
        )) == 0) {
            return $defaults;
        }

        $row = $wpdb->get_row("SELECT
            COUNT(*) AS total,
            SUM(background_status = 'APPROVED') AS approved,
            SUM(background_status = 'RESTRICTED') AS restricted,
            SUM(background_status = 'NOT_ELIGIBLE') AS not_eligible,
            SUM(background_status = 'PENDING') AS pending,
            SUM(background_expires_at IS NOT NULL AND background_expires_at < NOW()
                AND final_status IN ('ACTIVE','ACTIVE_MONITORED')) AS expired
            FROM `{$table}`", ARRAY_A);

        return $row ? array_map('intval', $row) : $defaults;
    }
}
