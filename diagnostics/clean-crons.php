<?php
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';
if (!current_user_can('manage_options')) wp_die('Acesso negado');

echo "<h1>Limpeza de Crons</h1>";

wp_clear_scheduled_hook('limpvix_mp_periodic_sync');
echo "<p>✅ Eventos limpos</p>";

$schedules = wp_get_schedules();
if (isset($schedules['limpvix_five_minutes'])) {
    echo "<p>✅ Schedule registrado</p>";
    if (!wp_next_scheduled('limpvix_mp_periodic_sync')) {
        wp_schedule_event(time(), 'limpvix_five_minutes', 'limpvix_mp_periodic_sync');
        echo "<p>✅ Evento re-agendado</p>";
    }
} else {
    echo "<p>⚠️  Schedule não registrado - aguarde próxima carga da página</p>";
}
