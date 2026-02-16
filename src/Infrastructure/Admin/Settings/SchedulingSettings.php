<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Admin\Settings;

/**
 * Settings: Scheduling
 *
 * Configurações do módulo de agendamento:
 * - Raio de geofence
 * - Tolerância de horário
 * - Duração mínima para múltiplos profissionais
 * - Pesos do algoritmo de alocação
 */
final class SchedulingSettings
{
    private const OPTION_GROUP = 'limpvix_scheduling_settings';
    private const OPTION_NAME = 'limpvix_scheduling_config';

    public static function init(): void
    {
        $instance = new self();
        add_action('admin_menu', [$instance, 'registerMenu']);
        add_action('admin_init', [$instance, 'registerSettings']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'limpvix-core',
            'Configurações de Agendamento',
            'Config. Agendamento',
            'manage_options',
            'limpvix-scheduling-settings',
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitizeSettings'],
                'default' => $this->getDefaults(),
            ]
        );

        add_settings_section(
            'limpvix_scheduling_geofence',
            'Geolocalização e Check-in',
            null,
            self::OPTION_GROUP
        );

        add_settings_field(
            'geofence_radius',
            'Raio de Geofence (metros)',
            [$this, 'renderGeofenceRadius'],
            self::OPTION_GROUP,
            'limpvix_scheduling_geofence'
        );

        add_settings_field(
            'time_tolerance',
            'Tolerância de Horário (minutos)',
            [$this, 'renderTimeTolerance'],
            self::OPTION_GROUP,
            'limpvix_scheduling_geofence'
        );

        add_settings_section(
            'limpvix_scheduling_allocation',
            'Alocação de Profissionais',
            null,
            self::OPTION_GROUP
        );

        add_settings_field(
            'min_duration_multiple_professionals',
            'Duração Mínima para Múltiplos Profissionais (horas)',
            [$this, 'renderMinDurationMultiple'],
            self::OPTION_GROUP,
            'limpvix_scheduling_allocation'
        );

        add_settings_section(
            'limpvix_scheduling_score_weights',
            'Pesos do Algoritmo de Score',
            function () {
                echo '<p>Defina os pesos para o cálculo do score de alocação (total deve ser 100%).</p>';
            },
            self::OPTION_GROUP
        );

        add_settings_field(
            'score_proximity_weight',
            'Peso Proximidade (%)',
            [$this, 'renderProximityWeight'],
            self::OPTION_GROUP,
            'limpvix_scheduling_score_weights'
        );

        add_settings_field(
            'score_availability_weight',
            'Peso Disponibilidade (%)',
            [$this, 'renderAvailabilityWeight'],
            self::OPTION_GROUP,
            'limpvix_scheduling_score_weights'
        );

        add_settings_field(
            'score_rating_weight',
            'Peso Rating (%)',
            [$this, 'renderRatingWeight'],
            self::OPTION_GROUP,
            'limpvix_scheduling_score_weights'
        );

        add_settings_field(
            'score_load_weight',
            'Peso Carga (%)',
            [$this, 'renderLoadWeight'],
            self::OPTION_GROUP,
            'limpvix_scheduling_score_weights'
        );
    }

    public function render(): void
    {
        ?>
        <div class="wrap">
            <h1>Configurações de Agendamento</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::OPTION_GROUP);
                submit_button();
                ?>
            </form>

            <div class="notice notice-info">
                <p><strong>Dica:</strong> Os pesos do algoritmo de score devem somar 100%. O sistema validará automaticamente.</p>
            </div>
        </div>
        <?php
    }

    public function renderGeofenceRadius(): void
    {
        $config = $this->getConfig();
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[geofence_radius]"
               value="<?php echo esc_attr($config['geofence_radius']); ?>"
               min="50" max="500" step="10" class="regular-text">
        <p class="description">
            Raio máximo em metros que o profissional pode estar do endereço para fazer check-in.
            Padrão: 150m.
        </p>
        <?php
    }

    public function renderTimeTolerance(): void
    {
        $config = $this->getConfig();
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[time_tolerance]"
               value="<?php echo esc_attr($config['time_tolerance']); ?>"
               min="30" max="120" step="15" class="regular-text">
        <p class="description">
            Tolerância em minutos antes/depois do horário solicitado.
            Padrão: 60 minutos (±1h).
        </p>
        <?php
    }

    public function renderMinDurationMultiple(): void
    {
        $config = $this->getConfig();
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[min_duration_multiple_professionals]"
               value="<?php echo esc_attr($config['min_duration_multiple_professionals']); ?>"
               min="3" max="10" step="1" class="regular-text">
        <p class="description">
            Duração mínima em horas para que o sistema aloque múltiplos profissionais.
            Padrão: 5 horas.
        </p>
        <?php
    }

    public function renderProximityWeight(): void
    {
        $config = $this->getConfig();
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[score_proximity_weight]"
               value="<?php echo esc_attr($config['score_proximity_weight']); ?>"
               min="0" max="100" step="5" class="regular-text">
        <p class="description">Peso da proximidade geográfica. Padrão: 40%</p>
        <?php
    }

    public function renderAvailabilityWeight(): void
    {
        $config = $this->getConfig();
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[score_availability_weight]"
               value="<?php echo esc_attr($config['score_availability_weight']); ?>"
               min="0" max="100" step="5" class="regular-text">
        <p class="description">Peso da disponibilidade. Padrão: 30%</p>
        <?php
    }

    public function renderRatingWeight(): void
    {
        $config = $this->getConfig();
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[score_rating_weight]"
               value="<?php echo esc_attr($config['score_rating_weight']); ?>"
               min="0" max="100" step="5" class="regular-text">
        <p class="description">Peso do rating do profissional. Padrão: 20%</p>
        <?php
    }

    public function renderLoadWeight(): void
    {
        $config = $this->getConfig();
        ?>
        <input type="number" name="<?php echo esc_attr(self::OPTION_NAME); ?>[score_load_weight]"
               value="<?php echo esc_attr($config['score_load_weight']); ?>"
               min="0" max="100" step="5" class="regular-text">
        <p class="description">Peso da carga diária atual. Padrão: 10%</p>
        <?php
    }

    public function sanitizeSettings(array $input): array
    {
        $sanitized = [];

        // Geofence
        $sanitized['geofence_radius'] = max(50, min(500, (int) ($input['geofence_radius'] ?? 150)));
        $sanitized['time_tolerance'] = max(30, min(120, (int) ($input['time_tolerance'] ?? 60)));
        $sanitized['min_duration_multiple_professionals'] = max(3, min(10, (int) ($input['min_duration_multiple_professionals'] ?? 5)));

        // Score Weights
        $sanitized['score_proximity_weight'] = max(0, min(100, (int) ($input['score_proximity_weight'] ?? 40)));
        $sanitized['score_availability_weight'] = max(0, min(100, (int) ($input['score_availability_weight'] ?? 30)));
        $sanitized['score_rating_weight'] = max(0, min(100, (int) ($input['score_rating_weight'] ?? 20)));
        $sanitized['score_load_weight'] = max(0, min(100, (int) ($input['score_load_weight'] ?? 10)));

        // Validar que pesos somam 100%
        $totalWeight = $sanitized['score_proximity_weight'] +
                      $sanitized['score_availability_weight'] +
                      $sanitized['score_rating_weight'] +
                      $sanitized['score_load_weight'];

        if ($totalWeight !== 100) {
            add_settings_error(
                self::OPTION_NAME,
                'invalid_weights',
                sprintf(
                    'Os pesos do algoritmo devem somar 100%%. Soma atual: %d%%',
                    $totalWeight
                ),
                'error'
            );

            // Retornar defaults
            return $this->getDefaults();
        }

        return $sanitized;
    }

    public static function getConfig(): array
    {
        $config = get_option(self::OPTION_NAME, []);
        return array_merge(self::getDefaults(), $config);
    }

    public static function get(string $key, $default = null)
    {
        $config = self::getConfig();
        return $config[$key] ?? $default;
    }

    private static function getDefaults(): array
    {
        return [
            'geofence_radius' => 150,
            'time_tolerance' => 60,
            'min_duration_multiple_professionals' => 5,
            'score_proximity_weight' => 40,
            'score_availability_weight' => 30,
            'score_rating_weight' => 20,
            'score_load_weight' => 10,
        ];
    }
}
