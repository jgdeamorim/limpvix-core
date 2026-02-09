<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Infrastructure\Persistence\WpProfessionalRepository;
use LimpVix\Infrastructure\Persistence\WpAvailabilityRepository;

/**
 * Admin Page: Professional Availability Management
 *
 * Gerencia disponibilidade de profissionais:
 * - Horários semanais
 * - Região de atuação
 * - Skills e limitações
 * - Carga diária máxima
 */
final class ProfessionalAvailabilityPage
{
    private WpProfessionalRepository $professionalRepository;
    private WpAvailabilityRepository $availabilityRepository;

    public function __construct()
    {
        $this->professionalRepository = new WpProfessionalRepository();
        $this->availabilityRepository = new WpAvailabilityRepository();
    }

    public static function init(): void
    {
        $instance = new self();
        add_action('admin_menu', [$instance, 'registerMenu']);
        add_action('admin_post_limpvix_update_availability', [$instance, 'handleUpdate']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'limpvix-core',
            'Disponibilidade de Profissionais',
            'Disponibilidade',
            'manage_options',
            'limpvix-professional-availability',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $action = $_GET['action'] ?? 'list';
        $professionalId = isset($_GET['professional_id']) ? (int) $_GET['professional_id'] : null;

        if ($action === 'edit' && $professionalId) {
            $this->renderEditForm($professionalId);
        } else {
            $this->renderList();
        }
    }

    private function renderList(): void
    {
        $professionals = $this->professionalRepository->findAllActive();

        ?>
        <div class="wrap">
            <h1>Disponibilidade de Profissionais</h1>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Profissional</th>
                        <th>Dias Disponíveis</th>
                        <th>Horário Típico</th>
                        <th>Região</th>
                        <th>Skills</th>
                        <th>Carga Máxima/Dia</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($professionals)): ?>
                        <tr>
                            <td colspan="7">Nenhum profissional encontrado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($professionals as $professional): ?>
                            <?php
                            $availability = $professional->getAvailability();
                            $region = $professional->getServiceRegion();
                            $skills = $professional->getSkills();
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($professional->getName()); ?></strong>
                                </td>
                                <td>
                                    <?php
                                    $days = $availability->getAvailableDays();
                                    $dayNames = [
                                        'monday' => 'Seg',
                                        'tuesday' => 'Ter',
                                        'wednesday' => 'Qua',
                                        'thursday' => 'Qui',
                                        'friday' => 'Sex',
                                        'saturday' => 'Sáb',
                                        'sunday' => 'Dom',
                                    ];
                                    $displayDays = array_map(fn($d) => $dayNames[$d] ?? $d, $days);
                                    echo esc_html(implode(', ', $displayDays));
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($days)) {
                                        $firstDay = $days[0];
                                        $slots = $availability->getSlotsFor($firstDay);
                                        if (!empty($slots)) {
                                            $firstSlot = $slots[0];
                                            echo esc_html(
                                                $firstSlot->getStart()->format('H:i') . ' - ' .
                                                $firstSlot->getEnd()->format('H:i')
                                            );
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo esc_html($region->getRadiusKm()); ?> km
                                </td>
                                <td>
                                    <?php
                                    $skillList = $skills->toArray()['skills'] ?? [];
                                    if (empty($skillList)) {
                                        echo '<em>Nenhuma skill configurada</em>';
                                    } else {
                                        echo esc_html(implode(', ', array_slice($skillList, 0, 3)));
                                        if (count($skillList) > 3) {
                                            echo ' <em>(+' . (count($skillList) - 3) . ')</em>';
                                        }
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php echo esc_html($professional->getMaxDailyHours()); ?>h
                                </td>
                                <td>
                                    <a href="?page=limpvix-professional-availability&action=edit&professional_id=<?php echo esc_attr($professional->getStaffId()); ?>" class="button button-small">
                                        Editar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function renderEditForm(int $professionalId): void
    {
        $professional = $this->professionalRepository->findByStaffId($professionalId);

        if (!$professional) {
            wp_die('Profissional não encontrado.');
        }

        $availability = $professional->getAvailability();
        $region = $professional->getServiceRegion();
        $skills = $professional->getSkills();

        ?>
        <div class="wrap">
            <h1>Editar Disponibilidade: <?php echo esc_html($professional->getName()); ?></h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="limpvix_update_availability">
                <input type="hidden" name="professional_id" value="<?php echo esc_attr($professionalId); ?>">
                <?php wp_nonce_field('limpvix_update_availability'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Carga Diária Máxima (horas)</label>
                        </th>
                        <td>
                            <input type="number" name="max_daily_hours" min="1" max="12" step="1"
                                   value="<?php echo esc_attr($professional->getMaxDailyHours()); ?>" required>
                            <p class="description">Número máximo de horas que o profissional pode trabalhar por dia.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label>Região de Atuação (raio em km)</label>
                        </th>
                        <td>
                            <input type="number" name="service_radius" min="1" max="50" step="1"
                                   value="<?php echo esc_attr($region->getRadiusKm()); ?>" required>
                            <p class="description">Raio em quilômetros a partir do centro de Vitória/ES.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label>Horário Semanal</label>
                        </th>
                        <td>
                            <?php
                            $weekDays = [
                                'monday' => 'Segunda-feira',
                                'tuesday' => 'Terça-feira',
                                'wednesday' => 'Quarta-feira',
                                'thursday' => 'Quinta-feira',
                                'friday' => 'Sexta-feira',
                                'saturday' => 'Sábado',
                                'sunday' => 'Domingo',
                            ];

                            foreach ($weekDays as $dayKey => $dayLabel):
                                $slots = $availability->getSlotsFor($dayKey);
                                $isAvailable = !empty($slots);
                                $startTime = $isAvailable ? $slots[0]->getStart()->format('H:i') : '08:00';
                                $endTime = $isAvailable ? $slots[0]->getEnd()->format('H:i') : '18:00';
                                ?>
                                <div class="availability-day">
                                    <label>
                                        <input type="checkbox" name="days[<?php echo esc_attr($dayKey); ?>][enabled]"
                                               value="1" <?php checked($isAvailable); ?>>
                                        <?php echo esc_html($dayLabel); ?>
                                    </label>
                                    <input type="time" name="days[<?php echo esc_attr($dayKey); ?>][start]"
                                           value="<?php echo esc_attr($startTime); ?>">
                                    até
                                    <input type="time" name="days[<?php echo esc_attr($dayKey); ?>][end]"
                                           value="<?php echo esc_attr($endTime); ?>">
                                </div>
                            <?php endforeach; ?>
                            <p class="description">Marque os dias em que o profissional está disponível e defina os horários.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label>Skills</label>
                        </th>
                        <td>
                            <?php
                            $allSkills = [
                                'limpeza_basica' => 'Limpeza Básica',
                                'limpeza_profunda' => 'Limpeza Profunda',
                                'pos_obra' => 'Pós-Obra',
                                'limpeza_teto' => 'Limpeza de Teto',
                                'esquadrias_vidros' => 'Esquadrias e Vidros',
                                'jardim_areas_externas' => 'Jardim e Áreas Externas',
                            ];

                            $currentSkills = $skills->toArray()['skills'] ?? [];

                            foreach ($allSkills as $skillKey => $skillLabel):
                                ?>
                                <label>
                                    <input type="checkbox" name="skills[]" value="<?php echo esc_attr($skillKey); ?>"
                                           <?php checked(in_array($skillKey, $currentSkills)); ?>>
                                    <?php echo esc_html($skillLabel); ?>
                                </label><br>
                            <?php endforeach; ?>
                            <p class="description">Selecione as habilidades do profissional.</p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">Salvar Alterações</button>
                    <a href="?page=limpvix-professional-availability" class="button">Cancelar</a>
                </p>
            </form>
        </div>

        <style>
            .availability-day {
                margin-bottom: 8px;
            }
            .availability-day label {
                display: inline-block;
                min-width: 150px;
            }
            .availability-day input[type="time"] {
                margin: 0 5px;
            }
        </style>
        <?php
    }

    public function handleUpdate(): void
    {
        check_admin_referer('limpvix_update_availability');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.');
        }

        $professionalId = (int) ($_POST['professional_id'] ?? 0);
        $professional = $this->professionalRepository->findByStaffId($professionalId);

        if (!$professional) {
            wp_die('Profissional não encontrado.');
        }

        // Construir nova availability
        $schedule = [];
        $days = $_POST['days'] ?? [];

        foreach ($days as $dayKey => $dayData) {
            if (!empty($dayData['enabled'])) {
                $schedule[$dayKey] = [
                    \LimpVix\Domain\Scheduling\ValueObjects\TimeSlot::fromStrings(
                        $dayData['start'],
                        $dayData['end']
                    )
                ];
            }
        }

        $newAvailability = \LimpVix\Domain\Scheduling\ValueObjects\WeeklyAvailability::create($schedule);

        // Atualizar usando Reflection (Professional tem private properties)
        $reflection = new \ReflectionClass($professional);

        $availabilityProp = $reflection->getProperty('availability');
        $availabilityProp->setAccessible(true);
        $availabilityProp->setValue($professional, $newAvailability);

        $maxHoursProp = $reflection->getProperty('maxDailyHours');
        $maxHoursProp->setAccessible(true);
        $maxHoursProp->setValue($professional, (int) $_POST['max_daily_hours']);

        $skillsProp = $reflection->getProperty('skills');
        $skillsProp->setAccessible(true);
        $skillsProp->setValue(
            $professional,
            \LimpVix\Domain\Scheduling\ValueObjects\ProfessionalSkills::fromArray([
                'skills' => $_POST['skills'] ?? []
            ])
        );

        // Atualizar ServiceRegion
        $regionProp = $reflection->getProperty('serviceRegion');
        $regionProp->setAccessible(true);
        $currentRegion = $regionProp->getValue($professional);
        $newRegion = \LimpVix\Domain\Scheduling\ValueObjects\ServiceRegion::create(
            $currentRegion->getCenter(),
            (int) $_POST['service_radius']
        );
        $regionProp->setValue($professional, $newRegion);

        // Salvar
        $this->professionalRepository->save($professional);

        // Redirect
        wp_redirect(add_query_arg([
            'page' => 'limpvix-professional-availability',
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }
}
