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

        // m2 table
        $m2Table = [
            'bedroom' => (float) ($_POST['m2_bedroom'] ?? 12),
            'bathroom' => (float) ($_POST['m2_bathroom'] ?? 4),
            'living_room' => (float) ($_POST['m2_living_room'] ?? 20),
            'kitchen' => (float) ($_POST['m2_kitchen'] ?? 10),
            'office' => (float) ($_POST['m2_office'] ?? 10),
            'external_area' => (float) ($_POST['m2_external_area'] ?? 25)
        ];
        update_option('limpvix_briefing_m2_table', $m2Table);

        // time factors
        $timeFactors = [
            'limpeza_pesada' => (float) ($_POST['time_factor_limpeza_pesada'] ?? 0.40),
            'pos_obra' => (float) ($_POST['time_factor_pos_obra'] ?? 0.70),
            'pre_mudanca' => (float) ($_POST['time_factor_pre_mudanca'] ?? 0.30)
        ];
        update_option('limpvix_briefing_time_factors', $timeFactors);

        // buffer + price
        update_option('limpvix_briefing_buffer_minutes', (int) ($_POST['buffer_minutes'] ?? 30));
        update_option('limpvix_briefing_price_per_m2', (float) ($_POST['price_per_m2'] ?? 15.00));

        // Complexity thresholds
        $complexitySave = [
            'simple_max_m2' => max(20, intval($_POST['complexity_simple_max_m2'] ?? 80)),
            'simple_max_duration' => max(30, intval($_POST['complexity_simple_max_duration'] ?? 180)),
            'medium_max_m2' => max(50, intval($_POST['complexity_medium_max_m2'] ?? 150)),
            'medium_max_duration' => max(60, intval($_POST['complexity_medium_max_duration'] ?? 300)),
            'simple_multiplier' => max(0.5, min(3.0, floatval($_POST['complexity_simple_multiplier'] ?? 1.0))),
            'medium_multiplier' => max(0.5, min(3.0, floatval($_POST['complexity_medium_multiplier'] ?? 1.3))),
            'complex_multiplier' => max(0.5, min(3.0, floatval($_POST['complexity_complex_multiplier'] ?? 1.5))),
        ];
        update_option('limpvix_complexity_thresholds', $complexitySave);

        // Professional allocation
        $allocationSave = [
            'base_duration_per_professional' => max(60, intval($_POST['allocation_base_duration'] ?? 300)),
            'large_area_threshold' => max(50, intval($_POST['allocation_large_area'] ?? 150)),
            'very_large_area_threshold' => max(100, intval($_POST['allocation_very_large_area'] ?? 200)),
            'complex_min_professionals' => max(1, min(5, intval($_POST['allocation_complex_min'] ?? 2))),
            'premium_min_professionals' => max(1, min(5, intval($_POST['allocation_premium_min'] ?? 2))),
            'max_professionals_allowed' => max(1, min(10, intval($_POST['allocation_max'] ?? 5))),
        ];
        update_option('limpvix_professional_allocation_config', $allocationSave);

        wp_redirect(admin_url('admin.php?page=limpvix-settings&tab=briefing&updated=1'));
        exit;
    }

    public function render(): void
    {
        // Existing values
        $m2Table = $this->getM2Table();
        $timeFactors = $this->getTimeFactors();
        $bufferMinutes = get_option('limpvix_briefing_buffer_minutes', 30);
        $pricePerM2 = get_option('limpvix_briefing_price_per_m2', 15.00);

        // Complexity thresholds
        $complexityThresholds = array_merge([
            'simple_max_m2' => 80, 'simple_max_duration' => 180,
            'medium_max_m2' => 150, 'medium_max_duration' => 300,
            'simple_multiplier' => 1.0, 'medium_multiplier' => 1.3, 'complex_multiplier' => 1.5,
        ], get_option('limpvix_complexity_thresholds', []));

        // Professional allocation
        $allocationConfig = array_merge([
            'base_duration_per_professional' => 300, 'large_area_threshold' => 150,
            'very_large_area_threshold' => 200, 'complex_min_professionals' => 2,
            'premium_min_professionals' => 2, 'max_professionals_allowed' => 5,
        ], get_option('limpvix_professional_allocation_config', []));

        // Dashboard stats
        $stats = $this->calculateBriefingStats();

        ?>
        <form method="post" action="">
            <?php wp_nonce_field('limpvix_briefing_settings'); ?>

            <!-- SECTION 1: Dashboard -->
            <div class="limpvix-card">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-chart-bar"></span>
                        Dashboard de Briefings
                    </h3>
                    <p>Visao geral do sistema de briefings</p>
                </div>
                <div class="limpvix-card-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;">
                        <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;padding:15px;text-align:center;">
                            <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo esc_html($stats['total']); ?></div>
                            <div style="font-size:12px;color:#646970;margin-top:4px;">Total de Briefings</div>
                        </div>
                        <?php foreach ($stats['by_status'] as $status => $count): ?>
                        <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:15px;text-align:center;">
                            <div style="font-size:28px;font-weight:700;color:#1d2327;"><?php echo esc_html($count); ?></div>
                            <div style="font-size:12px;color:#646970;margin-top:4px;"><?php echo esc_html(ucfirst(str_replace('_', ' ', $status))); ?></div>
                        </div>
                        <?php endforeach; ?>
                        <div style="background:#fcf9e8;border:1px solid #dba617;border-radius:4px;padding:15px;text-align:center;">
                            <div style="font-size:28px;font-weight:700;color:#996800;"><?php echo esc_html($stats['last_7_days']); ?></div>
                            <div style="font-size:12px;color:#646970;margin-top:4px;">Ultimos 7 dias</div>
                        </div>
                        <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-radius:4px;padding:15px;text-align:center;">
                            <div style="font-size:28px;font-weight:700;color:#2271b1;"><?php echo esc_html($stats['avg_m2']); ?></div>
                            <div style="font-size:12px;color:#646970;margin-top:4px;">Media m2 estimado</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: Tabela m2 (existing) -->
            <div class="limpvix-card" style="margin-top: 20px;">
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

            <!-- SECTION 3: Fatores de Tempo (existing) -->
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

            <!-- SECTION 4: Parametros de Precificacao (expanded) -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-money-alt"></span>
                        Parametros de Precificacao
                    </h3>
                    <p>Valores base e constantes usados pelo PricingEngine</p>
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
                                <p class="description">Valor base para calculo de preco (padrao: R$ 15,00)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Preco Minimo:</th>
                            <td>
                                <span style="background:#f0f6fc;border:1px solid #c3c4c7;padding:4px 12px;border-radius:3px;font-weight:600;">R$ 150,00</span>
                                <p class="description">Valor minimo cobrado por servico (constante do PricingEngine)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Multiplicador Comercial:</th>
                            <td>
                                <span style="background:#f0f6fc;border:1px solid #c3c4c7;padding:4px 12px;border-radius:3px;font-weight:600;">1.20x</span>
                                <p class="description">Margem comercial aplicada ao preco base (constante do PricingEngine)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Multiplicadores por Tipo:</th>
                            <td>
                                <span style="display:inline-block;background:#e7f5e7;border:1px solid #00a32a;padding:3px 8px;border-radius:3px;margin-right:8px;font-size:12px;">Standard: 1.0x</span>
                                <span style="display:inline-block;background:#fcf9e8;border:1px solid #dba617;padding:3px 8px;border-radius:3px;margin-right:8px;font-size:12px;">Pre-Mudanca: 1.3x</span>
                                <span style="display:inline-block;background:#fce4e4;border:1px solid #d63638;padding:3px 8px;border-radius:3px;font-size:12px;">Pos-Obra: 1.8x</span>
                                <p class="description">Multiplicadores de tipo de limpeza (constantes do PricingEngine)</p>
                            </td>
                        </tr>
                    </table>

                    <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin-top:15px;border-radius:0 4px 4px 0;">
                        <strong>Formula de Precificacao:</strong><br>
                        <code style="font-size:13px;">preco_base = m2 x R$/m2</code><br>
                        <code style="font-size:13px;">preco_ajustado = preco_base x tipo_limpeza x pacote x comercial(1.2) x geo_multiplier</code><br>
                        <code style="font-size:13px;">preco_final = max(preco_ajustado, R$ 150,00)</code><br>
                        <code style="font-size:13px;">payout_profissional = preco_final x (1 - fee_plataforma%)</code>
                    </div>
                </div>
            </div>

            <!-- SECTION 5: Pacotes de Servico (new) -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-archive"></span>
                        Pacotes de Servico
                    </h3>
                    <p>Pacotes disponiveis para clientes (leitura da tabela <code>wp_limpvix_package_configs</code>)</p>
                </div>
                <div class="limpvix-card-body">
                    <?php $this->renderPackagesFromDb($pricePerM2); ?>
                </div>
            </div>

            <!-- SECTION 6: Niveis de Complexidade (new) -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-performance"></span>
                        Niveis de Complexidade
                    </h3>
                    <p>Thresholds para deteccao automatica de complexidade e multiplicadores de tempo</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th colspan="2" style="background:#f0f0f1;padding:10px">
                                <strong>Thresholds de Deteccao</strong>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row">Simple maximo (m2):</th>
                            <td>
                                <input type="number" name="complexity_simple_max_m2" value="<?php echo esc_attr($complexityThresholds['simple_max_m2']); ?>" step="1" class="small-text"> m2
                                <span class="description">Ate este valor = Simple</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Simple maximo (duracao):</th>
                            <td>
                                <input type="number" name="complexity_simple_max_duration" value="<?php echo esc_attr($complexityThresholds['simple_max_duration']); ?>" step="1" class="small-text"> minutos
                                <span class="description">(<?php echo round($complexityThresholds['simple_max_duration'] / 60, 1); ?>h)</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Medium maximo (m2):</th>
                            <td>
                                <input type="number" name="complexity_medium_max_m2" value="<?php echo esc_attr($complexityThresholds['medium_max_m2']); ?>" step="1" class="small-text"> m2
                                <span class="description">Ate este valor = Medium</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Medium maximo (duracao):</th>
                            <td>
                                <input type="number" name="complexity_medium_max_duration" value="<?php echo esc_attr($complexityThresholds['medium_max_duration']); ?>" step="1" class="small-text"> minutos
                                <span class="description">(<?php echo round($complexityThresholds['medium_max_duration'] / 60, 1); ?>h)</span>
                            </td>
                        </tr>
                        <tr>
                            <th colspan="2" style="background:#f0f0f1;padding:10px;border-top:1px solid #ddd">
                                <strong>Multiplicadores de Tempo</strong>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row">Simple multiplier:</th>
                            <td>
                                <input type="number" name="complexity_simple_multiplier" value="<?php echo esc_attr($complexityThresholds['simple_multiplier']); ?>" step="0.1" min="0.5" max="3.0" class="small-text">x
                                <span class="description">Padrao: 1.0x (sem ajuste)</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Medium multiplier:</th>
                            <td>
                                <input type="number" name="complexity_medium_multiplier" value="<?php echo esc_attr($complexityThresholds['medium_multiplier']); ?>" step="0.1" min="0.5" max="3.0" class="small-text">x
                                <span class="description">Padrao: 1.3x (+30% no tempo)</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Complex multiplier:</th>
                            <td>
                                <input type="number" name="complexity_complex_multiplier" value="<?php echo esc_attr($complexityThresholds['complex_multiplier']); ?>" step="0.1" min="0.5" max="3.0" class="small-text">x
                                <span class="description">Padrao: 1.5x (+50% no tempo)</span>
                            </td>
                        </tr>
                    </table>

                    <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin-top:15px;border-radius:0 4px 4px 0;">
                        <strong>Regras de Deteccao Automatica:</strong><br>
                        <strong>Simple:</strong> Limpeza basica, &le; <?php echo esc_html($complexityThresholds['simple_max_m2']); ?>m2, &le; <?php echo round($complexityThresholds['simple_max_duration'] / 60, 1); ?>h &rarr; multiplier <?php echo esc_html($complexityThresholds['simple_multiplier']); ?>x<br>
                        <strong>Medium:</strong> Limpeza completa, <?php echo esc_html($complexityThresholds['simple_max_m2']); ?>-<?php echo esc_html($complexityThresholds['medium_max_m2']); ?>m2, <?php echo round($complexityThresholds['simple_max_duration'] / 60, 1); ?>-<?php echo round($complexityThresholds['medium_max_duration'] / 60, 1); ?>h &rarr; multiplier <?php echo esc_html($complexityThresholds['medium_multiplier']); ?>x<br>
                        <strong>Complex:</strong> Limpeza pesada/pos-obra, > <?php echo esc_html($complexityThresholds['medium_max_m2']); ?>m2, > <?php echo round($complexityThresholds['medium_max_duration'] / 60, 1); ?>h &rarr; multiplier <?php echo esc_html($complexityThresholds['complex_multiplier']); ?>x<br>
                        <em>Servicos "limpeza_pesada" ou "pos_obra" sao <strong>sempre</strong> Complex.</em><br>
                        <small>Persistido em: <code>limpvix_complexity_thresholds</code> &rarr; lido por <code>BriefingComplexityPolicy</code></small>
                    </div>
                </div>
            </div>

            <!-- SECTION 7: Alocacao de Profissionais (new) -->
            <div class="limpvix-card" style="margin-top: 20px;">
                <div class="limpvix-card-header">
                    <h3>
                        <span class="dashicons dashicons-groups"></span>
                        Alocacao de Profissionais
                    </h3>
                    <p>Thresholds para alocacao automatica de multiplos profissionais por briefing</p>
                </div>
                <div class="limpvix-card-body">
                    <table class="form-table">
                        <tr>
                            <th colspan="2" style="background:#f0f0f1;padding:10px">
                                <strong>Regras de Duracao</strong>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row">Duracao base por profissional:</th>
                            <td>
                                <input type="number" name="allocation_base_duration" value="<?php echo esc_attr($allocationConfig['base_duration_per_professional']); ?>" step="1" min="60" class="small-text"> minutos
                                <span class="description">(<?php echo round($allocationConfig['base_duration_per_professional'] / 60, 1); ?>h) — Acima disso, requer profissional adicional</span>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2" style="background:#f0f0f1;padding:10px;border-top:1px solid #ddd">
                                <strong>Regras de Area</strong>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row">Area grande (m2):</th>
                            <td>
                                <input type="number" name="allocation_large_area" value="<?php echo esc_attr($allocationConfig['large_area_threshold']); ?>" step="1" min="50" class="small-text"> m2
                                <span class="description">Usado com complexity Complex para min 2 profissionais</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Area muito grande (m2):</th>
                            <td>
                                <input type="number" name="allocation_very_large_area" value="<?php echo esc_attr($allocationConfig['very_large_area_threshold']); ?>" step="1" min="100" class="small-text"> m2
                                <span class="description">Sempre forca multiplos profissionais</span>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2" style="background:#f0f0f1;padding:10px;border-top:1px solid #ddd">
                                <strong>Regras de Complexidade e Pacote</strong>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row">Complexity Complex (minimo):</th>
                            <td>
                                <input type="number" name="allocation_complex_min" value="<?php echo esc_attr($allocationConfig['complex_min_professionals']); ?>" step="1" min="1" max="5" class="small-text"> profissionais
                                <span class="description">Quando complexity = Complex + area grande</span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Package Premium (minimo):</th>
                            <td>
                                <input type="number" name="allocation_premium_min" value="<?php echo esc_attr($allocationConfig['premium_min_professionals']); ?>" step="1" min="1" max="5" class="small-text"> profissionais
                                <span class="description">Pacote Premium sempre requer qualidade premium</span>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2" style="background:#f0f0f1;padding:10px;border-top:1px solid #ddd">
                                <strong>Limites Gerais</strong>
                            </th>
                        </tr>
                        <tr>
                            <th scope="row">Maximo de profissionais:</th>
                            <td>
                                <input type="number" name="allocation_max" value="<?php echo esc_attr($allocationConfig['max_professionals_allowed']); ?>" step="1" min="1" max="10" class="small-text"> profissionais
                                <span class="description">Limite absoluto de profissionais alocados por briefing</span>
                            </td>
                        </tr>
                    </table>

                    <div style="background:#f0f6fc;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin-top:15px;border-radius:0 4px 4px 0;">
                        <strong>Regras de Alocacao Automatica:</strong><br>
                        1. <strong>Duracao:</strong> > <?php echo round($allocationConfig['base_duration_per_professional'] / 60, 1); ?>h &rarr; 1 profissional adicional a cada <?php echo round($allocationConfig['base_duration_per_professional'] / 60, 1); ?>h<br>
                        2. <strong>Area muito grande:</strong> > <?php echo esc_html($allocationConfig['very_large_area_threshold']); ?>m2 &rarr; sempre multiplos (minimo 2)<br>
                        3. <strong>Complex + area grande:</strong> > <?php echo esc_html($allocationConfig['large_area_threshold']); ?>m2 &rarr; minimo <?php echo esc_html($allocationConfig['complex_min_professionals']); ?> profissionais<br>
                        4. <strong>Package Premium:</strong> &rarr; minimo <?php echo esc_html($allocationConfig['premium_min_professionals']); ?> profissionais<br>
                        5. <strong>Pos-obra:</strong> &rarr; sempre multiplos (minimo 2)<br>
                        6. <strong>Cap:</strong> Limitado a <?php echo esc_html($allocationConfig['max_professionals_allowed']); ?> profissionais<br>
                        <small>Persistido em: <code>limpvix_professional_allocation_config</code> &rarr; lido por <code>ProfessionalAllocationPolicy</code></small>
                    </div>
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

    private function calculateBriefingStats(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_briefings';

        $tableExists = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s", DB_NAME, $table)
        );

        if (!$tableExists) {
            return ['total' => 0, 'by_status' => [], 'last_7_days' => 0, 'avg_m2' => '0'];
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        $statusRows = $wpdb->get_results(
            "SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status ORDER BY cnt DESC",
            ARRAY_A
        );
        $byStatus = [];
        foreach ($statusRows as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }

        $last7 = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $avgM2 = $wpdb->get_var("SELECT AVG(estimated_m2) FROM {$table} WHERE estimated_m2 > 0");
        $avgM2 = $avgM2 ? number_format((float) $avgM2, 0) : '0';

        return [
            'total' => $total,
            'by_status' => $byStatus,
            'last_7_days' => $last7,
            'avg_m2' => $avgM2,
        ];
    }

    private function renderPackagesFromDb(float $pricePerM2): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_package_configs';

        $tableExists = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s", DB_NAME, $table)
        );

        if (!$tableExists) {
            echo '<p style="color:#646970;"><em>Tabela de pacotes nao encontrada. Execute as migrations.</em></p>';
            return;
        }

        $packages = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY percentage_increase ASC",
            ARRAY_A
        );

        if (empty($packages)) {
            echo '<p style="color:#646970;"><em>Nenhum pacote configurado na tabela.</em></p>';
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:5px">
            <thead>
                <tr>
                    <th style="width:12%">Tipo</th>
                    <th style="width:14%">Nome</th>
                    <th style="width:28%">Descricao</th>
                    <th style="width:10%">% Aumento</th>
                    <th style="width:12%">Profissionais</th>
                    <th style="width:12%">Skills</th>
                    <th style="width:12%">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $pkg): ?>
                    <?php
                    $skills = json_decode($pkg['required_skills'] ?? '[]', true) ?: [];
                    $percentage = (float) ($pkg['percentage_increase'] ?? 0) * 100;
                    $isActive = (bool) ($pkg['is_active'] ?? false);
                    ?>
                    <tr style="<?php echo $isActive ? '' : 'opacity:0.5;'; ?>">
                        <td><code><?php echo esc_html($pkg['package_type'] ?? ''); ?></code></td>
                        <td><strong><?php echo esc_html($pkg['display_name'] ?? ''); ?></strong></td>
                        <td><?php echo esc_html($pkg['description'] ?? ''); ?></td>
                        <td>
                            <?php if ($percentage > 0): ?>
                                <span style="color:#d63638;font-weight:600;">+<?php echo number_format($percentage, 0); ?>%</span>
                            <?php else: ?>
                                <span style="color:#2271b1;font-weight:600;">0%</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($pkg['min_professionals'] ?? 1); ?>-<?php echo esc_html($pkg['max_professionals'] ?? 1); ?></td>
                        <td>
                            <span title="<?php echo esc_attr(implode(', ', $skills)); ?>" style="cursor:help">
                                <?php echo count($skills); ?> skills
                            </span>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span style="color:#00a32a;font-weight:600;">Ativo</span>
                            <?php else: ?>
                                <span style="color:#dba617;">Inativo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div style="background:#fcf9e8;border:1px solid #dba617;border-left:4px solid #dba617;padding:12px 16px;margin-top:15px;border-radius:0 4px 4px 0;">
            <strong>Formula de Pacote:</strong> Preco Base x (1 + % Pacote)<br>
            <small>
                Exemplo com 100m2 x R$ <?php echo number_format($pricePerM2, 2, ',', '.'); ?>/m2 = R$ <?php echo number_format(100 * $pricePerM2, 2, ',', '.'); ?> (base):<br>
                Basico: R$ <?php echo number_format(100 * $pricePerM2 * 1.0, 2, ',', '.'); ?> (0%) |
                Padrao: R$ <?php echo number_format(100 * $pricePerM2 * 1.15, 2, ',', '.'); ?> (+15%) |
                Premium: R$ <?php echo number_format(100 * $pricePerM2 * 1.30, 2, ',', '.'); ?> (+30%)
            </small>
        </div>
        <?php
    }
}
