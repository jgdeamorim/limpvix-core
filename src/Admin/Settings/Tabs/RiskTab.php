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

        // Policy Engine
        $reviewCategories = array_map('sanitize_text_field', (array) ($_POST['policy_review_categories'] ?? []));
        update_option('limpvix_policy_review_categories', $reviewCategories);

        // KYC toggle
        update_option('limpvix_kyc_verification_enabled', isset($_POST['kyc_verification_enabled']));

        // Background Check toggle + settings
        update_option('limpvix_background_check_enabled', isset($_POST['background_check_enabled']));
        update_option('limpvix_prof_background_check_validity_days', max(30, intval($_POST['background_check_validity_days'] ?? 365)));
        update_option('limpvix_prof_background_check_use_mock', isset($_POST['background_check_use_mock']));

        wp_redirect(add_query_arg(['page' => 'limpvix-settings', 'tab' => 'risk', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        $ppidConnected  = !empty(get_option('limpvix_ppid_api_key'));
        $exatoConnected = !empty(get_option('limpvix_exato_api_key')) && !empty(get_option('limpvix_exato_token'));
        $policyCategories = (array) get_option('limpvix_policy_review_categories', []);

        $kycEnabled = get_option('limpvix_kyc_verification_enabled', true);
        $bgEnabled  = get_option('limpvix_background_check_enabled', false);
        $bgCheckValidityDays = get_option('limpvix_prof_background_check_validity_days', 365);
        $bgCheckUseMock = get_option('limpvix_prof_background_check_use_mock', true);

        $allReviewCategories = [
            'FRAUD_RELEVANT'  => 'Fraude / Estelionato',
            'PROPERTY_CRIME'  => 'Crime contra patrimonio (furto, roubo)',
            'DRUG_OFFENSE'    => 'Trafico / Uso de entorpecentes',
            'PUBLIC_DISORDER' => 'Perturbacao da ordem publica',
        ];
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_risk_settings'); ?>
            <div style="max-width:900px;margin-top:20px;">

                <!-- Status dos Providers -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                    <div style="padding:14px 18px;background:<?php echo $ppidConnected ? '#f0fdf4' : '#fef9f1'; ?>;border:1px solid <?php echo $ppidConnected ? '#bbf7d0' : '#fed7aa'; ?>;border-radius:8px;">
                        <strong><?php echo $ppidConnected ? '&#x2705;' : '&#x1F534;'; ?> PPID - KYC</strong><br>
                        <small style="color:<?php echo $ppidConnected ? '#15803d' : '#c2410c'; ?>;">
                            <?php echo $ppidConnected ? 'Provider real ativo' : 'Modo teste (MockKycProvider)'; ?>
                        </small>
                    </div>
                    <div style="padding:14px 18px;background:<?php echo $exatoConnected ? '#f0fdf4' : '#fef9f1'; ?>;border:1px solid <?php echo $exatoConnected ? '#bbf7d0' : '#fed7aa'; ?>;border-radius:8px;">
                        <strong><?php echo $exatoConnected ? '&#x2705;' : '&#x1F534;'; ?> Exato Digital - Background</strong><br>
                        <small style="color:<?php echo $exatoConnected ? '#15803d' : '#c2410c'; ?>;">
                            <?php echo $exatoConnected ? 'Provider real ativo' : 'Modo teste (MockBackgroundProvider)'; ?>
                        </small>
                    </div>
                </div>
                <p style="font-size:13px;color:#6b7280;margin:0 0 28px;">
                    Para configurar as credenciais dos providers, acesse
                    <a href="<?php echo admin_url('admin.php?page=limpvix-settings&tab=conexoes'); ?>">Conexoes</a>.
                </p>

                <!-- KYC Biometrico -->
                <div style="background: #f0f9ff; border-left: 4px solid #3b82f6; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h3 style="margin:0;font-size:16px;">&#x1F510; KYC Biometrico - Verificacao de Identidade</h3>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                            <input type="checkbox" name="kyc_verification_enabled" value="1" <?php checked($kycEnabled); ?>>
                            <strong><?php echo $kycEnabled ? '&#x2705; Habilitado' : '&#x26A0;&#xFE0F; Desabilitado'; ?></strong>
                        </label>
                    </div>
                    <?php
                    $ppidEnabled = get_option('limpvix_ppid_enabled', false);
                    $ppidEmail = get_option('limpvix_ppid_email', '');
                    ?>
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th scope="row" style="padding:8px 10px 8px 0;">Status do KYC:</th>
                            <td style="padding:8px 10px;">
                                <?php
                                if ($ppidEnabled && !empty($ppidEmail)) {
                                    echo '<span class="limpvix-badge limpvix-badge-success">&#x2705; Ativo</span>';
                                } else {
                                    echo '<span class="limpvix-badge limpvix-badge-warning">&#x26A0;&#xFE0F; Nao Configurado</span>';
                                }
                                ?>
                                <p class="description">
                                    <a href="?page=limpvix-settings&tab=conexoes">Configurar credenciais PPID</a> |
                                    <a href="?page=limpvix-kyc">Gerenciar verificacoes KYC</a>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <div style="font-size:13px;color:#374151;margin-top:8px;">
                        <strong>Funcionalidades:</strong> OCR de Documentos | Liveness Detection | Face Match | Aprovacao Automatica | Modo Mock
                    </div>
                    <?php if ($kycEnabled): ?>
                    <div style="background: #fff3cd; padding: 8px 12px; border-left: 3px solid #f0ad4e; margin-top: 12px; font-size: 12px;">
                        <strong>Ativo:</strong> Profissionais SEM KYC aprovado NAO podem aceitar ofertas de trabalho.
                    </div>
                    <?php else: ?>
                    <div style="background: #e8f4f8; padding: 8px 12px; border-left: 3px solid #00a0d2; margin-top: 12px; font-size: 12px;">
                        <strong>Desabilitado:</strong> Profissionais podem trabalhar sem KYC (modo desenvolvimento).
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Background Check -->
                <?php
                $exatoApiKey = get_option('limpvix_exato_api_key', '');
                $exatoEnabled = !empty($exatoApiKey);
                $bgMockActive = $bgCheckUseMock || !$exatoEnabled;
                global $wpdb;
                $bgTable = $wpdb->prefix . 'limpvix_professional_verification';
                $bgStats = [];
                if ($wpdb->get_var("SHOW TABLES LIKE '{$bgTable}'") === $bgTable) {
                    $bgStats = $wpdb->get_row("SELECT COUNT(*) AS total, SUM(background_status = 'CLEAR') AS cleared, SUM(background_status = 'CONSIDER') AS consider, SUM(background_status = 'ADVERSE') AS adverse, SUM(background_status = 'PENDING') AS pending, SUM(background_expires_at IS NOT NULL AND background_expires_at < NOW() AND final_status IN ('ACTIVE','ACTIVE_MONITORED')) AS expired FROM {$bgTable}", ARRAY_A) ?? [];
                }
                ?>
                <div style="background: #faf5ff; border-left: 4px solid #7c3aed; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h3 style="margin:0;font-size:16px;">&#x1F50D; Background Check — Antecedentes Criminais</h3>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">
                            <input type="checkbox" name="background_check_enabled" value="1" <?php checked($bgEnabled); ?>>
                            <strong><?php echo $bgEnabled ? '&#x2705; Habilitado' : '&#x26A0;&#xFE0F; Desabilitado'; ?></strong>
                        </label>
                    </div>

                    <div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 140px; background: <?php echo $exatoEnabled ? '#d1fae5' : '#fef3c7'; ?>; border: 1px solid <?php echo $exatoEnabled ? '#10b981' : '#f59e0b'; ?>; border-radius: 6px; padding: 10px; text-align: center; font-size: 12px;">
                            <?php echo $exatoEnabled ? '&#x2705;' : '&#x26A0;&#xFE0F;'; ?> <strong>Exato Digital</strong><br>
                            <?php echo $exatoEnabled ? 'Configurado' : 'Nao configurado'; ?>
                            <?php if (!$exatoEnabled): ?><br><a href="?page=limpvix-settings&tab=conexoes" style="font-size: 11px;">Configurar</a><?php endif; ?>
                        </div>
                        <div style="flex: 1; min-width: 140px; background: <?php echo $bgMockActive ? '#fef3c7' : '#f0fdf4'; ?>; border: 1px solid <?php echo $bgMockActive ? '#f59e0b' : '#22c55e'; ?>; border-radius: 6px; padding: 10px; text-align: center; font-size: 12px;">
                            <?php echo $bgMockActive ? '&#x1F9EA;' : '&#x1F680;'; ?> <strong>Modo</strong><br>
                            <?php echo $bgMockActive ? 'Mock (Teste)' : 'Real (Producao)'; ?>
                        </div>
                        <?php if (!empty($bgStats) && (int)($bgStats['total'] ?? 0) > 0): ?>
                        <div style="flex: 1; min-width: 140px; background: #eff6ff; border: 1px solid #3b82f6; border-radius: 6px; padding: 10px; text-align: center; font-size: 12px;">
                            &#x1F4CA; <strong>Verificacoes</strong><br>
                            <?php echo (int)($bgStats['cleared'] ?? 0); ?> aprovadas / <?php echo (int)($bgStats['total'] ?? 0); ?> total
                        </div>
                        <?php endif; ?>
                    </div>

                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th scope="row" style="padding:8px 10px 8px 0;">Usar Provider Mock:</th>
                            <td style="padding:8px 10px;">
                                <label><input type="checkbox" name="background_check_use_mock" value="1" <?php checked($bgCheckUseMock); ?>> Modo mock (simula aprovacao, nao consome creditos)</label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="padding:8px 10px 8px 0;">Validade do Check:</th>
                            <td style="padding:8px 10px;">
                                <input type="number" name="background_check_validity_days" value="<?php echo esc_attr($bgCheckValidityDays); ?>" min="30" max="730" class="small-text"> dias
                                <p class="description">Recomendado: 365 dias (anual). Minimo: 30 dias.</p>
                            </td>
                        </tr>
                    </table>

                    <?php if (!empty($bgStats) && (int)($bgStats['total'] ?? 0) > 0): ?>
                    <div style="margin-top: 12px; padding: 10px; background: #f8fafc; border-radius: 6px; font-size: 12px;">
                        <strong>Distribuicao:</strong>
                        &#x2705; Clear: <strong><?php echo (int)($bgStats['cleared'] ?? 0); ?></strong> |
                        &#x26A0;&#xFE0F; Consider: <strong><?php echo (int)($bgStats['consider'] ?? 0); ?></strong> |
                        &#x274C; Adverse: <strong><?php echo (int)($bgStats['adverse'] ?? 0); ?></strong> |
                        &#x23F3; Pending: <strong><?php echo (int)($bgStats['pending'] ?? 0); ?></strong>
                    </div>
                    <?php endif; ?>

                    <?php if ($bgEnabled): ?>
                    <div style="background: #fff3cd; padding: 8px 12px; border-left: 3px solid #f0ad4e; margin-top: 12px; font-size: 12px;">
                        <strong>Ativo:</strong> Profissional precisa background check aprovado para aceitar ofertas.
                    </div>
                    <?php else: ?>
                    <div style="background: #e8f4f8; padding: 8px 12px; border-left: 3px solid #00a0d2; margin-top: 12px; font-size: 12px;">
                        <strong>Desabilitado:</strong> Background check nao bloqueia profissionais.
                    </div>
                    <?php endif; ?>

                    <div style="margin-top:12px;font-size:12px;">
                        <a href="?page=limpvix-professionals&tab=risk_score">Profissionais > Risk Score</a> |
                        <a href="?page=limpvix-professionals&tab=kyc">Profissionais > KYC</a>
                    </div>
                </div>

                <!-- Policy Engine -->
                <h2 style="margin:0 0 8px;padding-bottom:10px;border-bottom:2px solid #e2e8f0;">
                    Policy Engine - Regras de Elegibilidade
                </h2>
                <p style="color:#64748b;margin:0 0 16px;font-size:13px;">
                    Configure quais categorias de antecedentes geram <strong>revisao manual</strong> (UNDER_REVIEW)
                    em vez de bloqueio automatico. Crimes violentos e sexuais sao sempre bloqueadores imutaveis.
                </p>
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin-bottom:24px;">
                    <p style="margin:0 0 8px;font-weight:600;font-size:13px;color:#374151;">
                        Bloqueadores imutaveis (sempre NOT_ELIGIBLE - nao configuravel):
                    </p>
                    <ul style="margin:0 0 16px;padding-left:20px;color:#6b7280;font-size:13px;">
                        <li>Crimes sexuais (SEXUAL_CRIME)</li>
                        <li>Crimes violentos com vitima (VIOLENT_CRIME)</li>
                    </ul>
                    <p style="margin:0 0 8px;font-weight:600;font-size:13px;color:#374151;">
                        Categorias configuraveis - marque as que devem gerar UNDER_REVIEW:
                    </p>
                    <?php foreach ($allReviewCategories as $value => $label): ?>
                        <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:13px;cursor:pointer;">
                            <input type="checkbox"
                                   name="policy_review_categories[]"
                                   value="<?php echo esc_attr($value); ?>"
                                   <?php checked(in_array($value, $policyCategories, true)); ?>>
                            <strong><?php echo esc_html($value); ?></strong> - <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                    <p style="margin:8px 0 0;font-size:12px;color:#9ca3af;">
                        Categorias nao marcadas -> status aprovado com monitoramento (ACTIVE_MONITORED).
                    </p>
                </div>

                <p class="submit">
                    <input type="hidden" name="limpvix_save_risk_settings" value="1">
                    <button type="submit" class="button button-primary button-large">
                        Salvar Risk & Compliance
                    </button>
                </p>
            </div>
        </form>
        <?php
    }
}
