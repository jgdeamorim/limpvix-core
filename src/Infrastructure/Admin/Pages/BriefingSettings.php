<?php
/**
 * BriefingSettings - Configurações do Módulo Briefing
 *
 * RESPONSABILIDADE:
 * - Menu: LimpVix → Configurações → Briefing
 * - Configurações paramétricas editáveis:
 *   - Tabela m² por cômodo
 *   - Fatores de tempo por tipo limpeza
 *   - Buffer operacional
 *   - Firebase credentials (Project ID)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class BriefingSettings
{
    private const PAGE_SLUG = 'limpvix-briefing-settings';
    private const OPTION_GROUP = 'limpvix_briefing_settings';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'limpvix',
            'Configurações Briefing',
            'Config. Briefing',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, 'limpvix_briefing_m2_table');
        register_setting(self::OPTION_GROUP, 'limpvix_briefing_time_factors');
        register_setting(self::OPTION_GROUP, 'limpvix_briefing_buffer_minutes');
        register_setting(self::OPTION_GROUP, 'limpvix_briefing_price_per_m2');
    }

    public function render(): void
    {
        if (isset($_POST['submit']) && check_admin_referer('limpvix_briefing_settings')) {
            $this->handleSave();
        }

        $m2Table = $this->getM2Table();
        $timeFactors = $this->getTimeFactors();
        $bufferMinutes = get_option('limpvix_briefing_buffer_minutes', 30);
        $pricePerM2 = get_option('limpvix_briefing_price_per_m2', 15.00);

        ?>
        <div class="wrap">
            <h1>Configurações do Briefing</h1>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Configurações salvas com sucesso!</p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('limpvix_briefing_settings'); ?>

                <!-- Tabela m² -->
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                    <h2>Tabela de m² por Cômodo</h2>
                    <p>Valores usados para cálculo de área estimada.</p>
                    <table class="form-table">
                        <tr>
                            <th>Quarto:</th>
                            <td><input type="number" name="m2_bedroom" value="<?php echo esc_attr($m2Table['bedroom']); ?>" step="0.1"> m²</td>
                        </tr>
                        <tr>
                            <th>Banheiro:</th>
                            <td><input type="number" name="m2_bathroom" value="<?php echo esc_attr($m2Table['bathroom']); ?>" step="0.1"> m²</td>
                        </tr>
                        <tr>
                            <th>Sala:</th>
                            <td><input type="number" name="m2_living_room" value="<?php echo esc_attr($m2Table['living_room']); ?>" step="0.1"> m²</td>
                        </tr>
                        <tr>
                            <th>Cozinha:</th>
                            <td><input type="number" name="m2_kitchen" value="<?php echo esc_attr($m2Table['kitchen']); ?>" step="0.1"> m²</td>
                        </tr>
                        <tr>
                            <th>Escritório:</th>
                            <td><input type="number" name="m2_office" value="<?php echo esc_attr($m2Table['office']); ?>" step="0.1"> m²</td>
                        </tr>
                        <tr>
                            <th>Área Externa:</th>
                            <td><input type="number" name="m2_external_area" value="<?php echo esc_attr($m2Table['external_area']); ?>" step="0.1"> m²</td>
                        </tr>
                    </table>
                </div>

                <!-- Fatores de Tempo -->
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                    <h2>Fatores de Tempo por Tipo de Limpeza</h2>
                    <p>Multiplicadores aplicados ao tempo base (ex: 0.40 = +40% de tempo).</p>
                    <table class="form-table">
                        <tr>
                            <th>Limpeza Pesada:</th>
                            <td><input type="number" name="time_factor_limpeza_pesada" value="<?php echo esc_attr($timeFactors['limpeza_pesada']); ?>" step="0.01"> (+40% padrão)</td>
                        </tr>
                        <tr>
                            <th>Pós-Obra:</th>
                            <td><input type="number" name="time_factor_pos_obra" value="<?php echo esc_attr($timeFactors['pos_obra']); ?>" step="0.01"> (+70% padrão)</td>
                        </tr>
                        <tr>
                            <th>Pré-Mudança:</th>
                            <td><input type="number" name="time_factor_pre_mudanca" value="<?php echo esc_attr($timeFactors['pre_mudanca']); ?>" step="0.01"> (+30% padrão)</td>
                        </tr>
                    </table>
                </div>

                <!-- Outros Parâmetros -->
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                    <h2>Outros Parâmetros</h2>
                    <table class="form-table">
                        <tr>
                            <th>Buffer Operacional:</th>
                            <td><input type="number" name="buffer_minutes" value="<?php echo esc_attr($bufferMinutes); ?>"> minutos</td>
                        </tr>
                        <tr>
                            <th>Preço por m²:</th>
                            <td>R$ <input type="number" name="price_per_m2" value="<?php echo esc_attr($pricePerM2); ?>" step="0.01"></td>
                        </tr>
                    </table>
                </div>

                <!-- Pacotes de Serviço -->
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                    <h2>📦 Pacotes de Serviço</h2>
                    <p>Configure os pacotes disponíveis para os clientes selecionarem. Cada pacote tem um percentual de aumento no preço base.</p>

                    <?php $this->renderPackagesTable(); ?>

                    <p style="margin-top:15px">
                        <small>
                            <strong>Como funciona:</strong><br>
                            • Preço Base = m² × Preço por m² (R$ <?php echo number_format($pricePerM2, 2, ',', '.'); ?>)<br>
                            • Preço Final = Preço Base × (1 + Percentual do Pacote)<br>
                            • Exemplo: 100m² × R$ 15,00 = R$ 1.500,00 (base)<br>
                            &nbsp;&nbsp;→ Básico: R$ 1.500,00 (0%)<br>
                            &nbsp;&nbsp;→ Padrão: R$ 1.725,00 (+15%)<br>
                            &nbsp;&nbsp;→ Premium: R$ 1.950,00 (+30%)
                        </small>
                    </p>
                </div>

                <!-- Firebase -->
                <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
                    <h2>Firebase Configuration</h2>
                    <p>Configurado via <code>wp-config.php</code>:</p>
                    <table class="form-table">
                        <tr>
                            <th>Project ID:</th>
                            <td>
                                <?php if (defined('LIMPVIX_FIREBASE_PROJECT_ID')): ?>
                                    <code><?php echo esc_html(LIMPVIX_FIREBASE_PROJECT_ID); ?></code> ✅
                                <?php else: ?>
                                    <span style="color:red">❌ Não configurado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <p><small>Para alterar, edite <code>wp-config.php</code> e adicione: <code>define('LIMPVIX_FIREBASE_PROJECT_ID', 'seu-projeto');</code></small></p>
                </div>

                <p class="submit">
                    <button type="submit" name="submit" class="button button-primary">Salvar Configurações</button>
                </p>
            </form>
        </div>
        <?php
    }

    private function handleSave(): void
    {
        // Salvar tabela m²
        $m2Table = [
            'bedroom' => (float) $_POST['m2_bedroom'],
            'bathroom' => (float) $_POST['m2_bathroom'],
            'living_room' => (float) $_POST['m2_living_room'],
            'kitchen' => (float) $_POST['m2_kitchen'],
            'office' => (float) $_POST['m2_office'],
            'external_area' => (float) $_POST['m2_external_area']
        ];
        update_option('limpvix_briefing_m2_table', $m2Table);

        // Salvar fatores de tempo
        $timeFactors = [
            'limpeza_pesada' => (float) $_POST['time_factor_limpeza_pesada'],
            'pos_obra' => (float) $_POST['time_factor_pos_obra'],
            'pre_mudanca' => (float) $_POST['time_factor_pre_mudanca']
        ];
        update_option('limpvix_briefing_time_factors', $timeFactors);

        // Salvar buffer
        update_option('limpvix_briefing_buffer_minutes', (int) $_POST['buffer_minutes']);

        // Salvar preço por m²
        update_option('limpvix_briefing_price_per_m2', (float) $_POST['price_per_m2']);

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=1'));
        exit;
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

    /**
     * Renderizar tabela de pacotes
     *
     * @return void
     */
    private function renderPackagesTable(): void
    {
        global $wpdb;

        $packages = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}limpvix_package_configs ORDER BY percentage_increase ASC",
            ARRAY_A
        );

        if (empty($packages)) {
            echo '<p><em>Nenhum pacote configurado.</em></p>';
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:10px">
            <thead>
                <tr>
                    <th style="width:15%">Tipo</th>
                    <th style="width:15%">Nome</th>
                    <th style="width:30%">Descrição</th>
                    <th style="width:10%">% Aumento</th>
                    <th style="width:10%">Profissionais</th>
                    <th style="width:10%">Skills</th>
                    <th style="width:10%">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $pkg): ?>
                    <?php
                    $skills = json_decode($pkg['required_skills'], true) ?: [];
                    $percentage = (float) $pkg['percentage_increase'] * 100;
                    $isActive = (bool) $pkg['is_active'];
                    ?>
                    <tr style="<?php echo $isActive ? '' : 'opacity:0.5'; ?>">
                        <td><code><?php echo esc_html($pkg['package_type']); ?></code></td>
                        <td><strong><?php echo esc_html($pkg['display_name']); ?></strong></td>
                        <td><?php echo esc_html($pkg['description']); ?></td>
                        <td>
                            <?php if ($percentage > 0): ?>
                                <span style="color:#d63638">+<?php echo number_format($percentage, 0); ?>%</span>
                            <?php else: ?>
                                <span style="color:#2271b1">0%</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($pkg['min_professionals']); ?>-<?php echo esc_html($pkg['max_professionals']); ?></td>
                        <td>
                            <span title="<?php echo esc_attr(implode(', ', $skills)); ?>" style="cursor:help">
                                <?php echo count($skills); ?> skills
                            </span>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span style="color:#00a32a">● Ativo</span>
                            <?php else: ?>
                                <span style="color:#dba617">○ Inativo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:15px">
            <a href="<?php echo admin_url('admin.php?page=limpvix-package-editor'); ?>" class="button">
                Editar Pacotes
            </a>
            <small style="margin-left:10px">
                <em>Para criar, editar ou desativar pacotes, use o editor de pacotes.</em>
            </small>
        </p>
        <?php
    }
}
