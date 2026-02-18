<?php

namespace LimpVix\Admin\Settings\Tabs;

defined('ABSPATH') || exit;

class BriefingTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'briefing'; }
    public function getLabel(): string { return 'Briefing'; }
    public function getIcon(): string { return '&#x1F4CB;'; }

    public function handleSave(): void
    {
        if (!isset($_POST['limpvix_save_briefing_settings'])) {
            return;
        }
        if (!check_admin_referer('limpvix_briefing_settings')) {
            return;
        }

        $m2Table = [
            'bedroom' => (float) ($_POST['m2_bedroom'] ?? 12),
            'bathroom' => (float) ($_POST['m2_bathroom'] ?? 4),
            'living_room' => (float) ($_POST['m2_living_room'] ?? 20),
            'kitchen' => (float) ($_POST['m2_kitchen'] ?? 10),
            'office' => (float) ($_POST['m2_office'] ?? 10),
            'external_area' => (float) ($_POST['m2_external_area'] ?? 25)
        ];
        update_option('limpvix_briefing_m2_table', $m2Table);

        $timeFactors = [
            'limpeza_pesada' => (float) ($_POST['time_factor_limpeza_pesada'] ?? 0.40),
            'pos_obra' => (float) ($_POST['time_factor_pos_obra'] ?? 0.70),
            'pre_mudanca' => (float) ($_POST['time_factor_pre_mudanca'] ?? 0.30)
        ];
        update_option('limpvix_briefing_time_factors', $timeFactors);

        update_option('limpvix_briefing_buffer_minutes', (int) ($_POST['buffer_minutes'] ?? 30));
        update_option('limpvix_briefing_price_per_m2', (float) ($_POST['price_per_m2'] ?? 15.00));

        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=briefing&updated=1'));
        exit;
    }

    public function render(): void
    {
        $m2Table = $this->getM2Table();
        $timeFactors = $this->getTimeFactors();
        $bufferMinutes = get_option('limpvix_briefing_buffer_minutes', 30);
        $pricePerM2 = get_option('limpvix_briefing_price_per_m2', 15.00);

        ?>
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_briefing_settings'); ?>

            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-home"></span>
                        Tabela de m2 por Comodo
                    </h3>
                    <p>Valores usados para calculo de area estimada do briefing</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Quarto:</th>
                            <td><input type="number" name="m2_bedroom" value="<?php echo esc_attr($m2Table['bedroom']); ?>" step="0.1" class="small-text"> m2</td>
                        </tr>
                        <tr>
                            <th scope="row">Banheiro:</th>
                            <td><input type="number" name="m2_bathroom" value="<?php echo esc_attr($m2Table['bathroom']); ?>" step="0.1" class="small-text"> m2</td>
                        </tr>
                        <tr>
                            <th scope="row">Sala:</th>
                            <td><input type="number" name="m2_living_room" value="<?php echo esc_attr($m2Table['living_room']); ?>" step="0.1" class="small-text"> m2</td>
                        </tr>
                        <tr>
                            <th scope="row">Cozinha:</th>
                            <td><input type="number" name="m2_kitchen" value="<?php echo esc_attr($m2Table['kitchen']); ?>" step="0.1" class="small-text"> m2</td>
                        </tr>
                        <tr>
                            <th scope="row">Escritorio:</th>
                            <td><input type="number" name="m2_office" value="<?php echo esc_attr($m2Table['office']); ?>" step="0.1" class="small-text"> m2</td>
                        </tr>
                        <tr>
                            <th scope="row">Area Externa:</th>
                            <td><input type="number" name="m2_external_area" value="<?php echo esc_attr($m2Table['external_area']); ?>" step="0.1" class="small-text"> m2</td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-clock"></span>
                        Fatores de Tempo por Tipo de Limpeza
                    </h3>
                    <p>Multiplicadores aplicados ao tempo base (ex: 0.40 = +40% de tempo)</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Limpeza Pesada:</th>
                            <td>
                                <input type="number" name="time_factor_limpeza_pesada" value="<?php echo esc_attr($timeFactors['limpeza_pesada']); ?>" step="0.01" class="small-text">
                                <span class="description">(+40% padrao)</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Pos-Obra:</th>
                            <td>
                                <input type="number" name="time_factor_pos_obra" value="<?php echo esc_attr($timeFactors['pos_obra']); ?>" step="0.01" class="small-text">
                                <span class="description">(+70% padrao)</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Pre-Mudanca:</th>
                            <td>
                                <input type="number" name="time_factor_pre_mudanca" value="<?php echo esc_attr($timeFactors['pre_mudanca']); ?>" step="0.01" class="small-text">
                                <span class="description">(+30% padrao)</span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-admin-generic"></span>
                        Outros Parametros
                    </h3>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Buffer Operacional:</th>
                            <td>
                                <input type="number" name="buffer_minutes" value="<?php echo esc_attr($bufferMinutes); ?>" class="small-text"> minutos
                                <p class="description">Tempo adicional para deslocamento e preparacao</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Preco por m2:</th>
                            <td>
                                R$ <input type="number" name="price_per_m2" value="<?php echo esc_attr($pricePerM2); ?>" step="0.01" class="small-text">
                                <p class="description">Valor base para calculo de preco</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <p class="submit">
                <button type="submit" name="limpvix_save_briefing_settings" class="button button-primary button-large">
                    Salvar Configuracoes do Briefing
                </button>
            </p>
        </form>
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
}
