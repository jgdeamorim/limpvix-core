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

        $reviewCategories = array_map('sanitize_text_field', (array) ($_POST['policy_review_categories'] ?? []));
        update_option('limpvix_policy_review_categories', $reviewCategories);

        wp_redirect(add_query_arg(['page' => 'limpvix-settings', 'tab' => 'risk', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        $ppidConnected  = !empty(get_option('limpvix_ppid_api_key'));
        $exatoConnected = !empty(get_option('limpvix_exato_api_key')) && !empty(get_option('limpvix_exato_token'));
        $policyCategories = (array) get_option('limpvix_policy_review_categories', []);

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
                        Salvar Policy Engine
                    </button>
                </p>
            </div>
        </form>
        <?php
    }
}
