<?php
/**
 * DIAGNÓSTICO - Templates de Mensagens
 * Acessar: http://localhost/wp-content/plugins/limpvix-core/diagnostico-templates.php
 */

define('WP_USE_THEMES', false);
require_once '../../../../wp-load.php';

if (!current_user_can('manage_options')) {
    wp_die('Acesso negado - faça login como administrador');
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Diagnóstico - Templates</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #f0f0f1; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        h1 { color: #1d2327; border-bottom: 3px solid #2271b1; padding-bottom: 10px; }
        .check { margin: 10px 0; padding: 10px; background: #f6f7f7; border-left: 4px solid #00a32a; }
        .check.error { border-left-color: #d63638; background: #fcf0f1; }
        .check.warning { border-left-color: #dba617; background: #fcf9e8; }
        code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 4px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f6f7f7; font-weight: 600; }
        .badge { padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; display: inline-block; }
        .badge-success { background: #00a32a; color: white; }
        .badge-error { background: #d63638; color: white; }
        .section { margin: 30px 0; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Diagnóstico - Templates de Mensagens</h1>

    <div class="section">
        <h2>1. Verificação de Classes</h2>
        <?php
        $classes = [
            'LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesPage',
            'LimpVix\\Domain\\Communication\\MessageTemplates',
            'LimpVix\\Infrastructure\\Admin\\UI\\UIComponents',
        ];

        foreach ($classes as $class) {
            $exists = class_exists($class);
            echo '<div class="check ' . ($exists ? '' : 'error') . '">';
            echo ($exists ? '✅' : '❌') . ' <code>' . esc_html($class) . '</code>';
            echo '</div>';
        }
        ?>
    </div>

    <div class="section">
        <h2>2. Verificação de Arquivos</h2>
        <?php
        $files = [
            'MessageTemplatesPage.php' => LIMPVIX_PLUGIN_DIR . '/src/Infrastructure/Admin/Pages/MessageTemplatesPage.php',
            'message-templates.js' => LIMPVIX_PLUGIN_DIR . '/assets/js/message-templates.js',
            'limpvix-admin-modern.css' => LIMPVIX_PLUGIN_DIR . '/assets/css/limpvix-admin-modern.css',
        ];

        foreach ($files as $name => $path) {
            $exists = file_exists($path);
            $size = $exists ? filesize($path) : 0;
            echo '<div class="check ' . ($exists ? '' : 'error') . '">';
            echo ($exists ? '✅' : '❌') . ' <strong>' . esc_html($name) . '</strong><br>';
            if ($exists) {
                echo 'Tamanho: ' . number_format($size) . ' bytes | Path: <code>' . esc_html($path) . '</code>';
            }
            echo '</div>';
        }
        ?>
    </div>

    <div class="section">
        <h2>3. Templates Canônicos Disponíveis</h2>
        <?php
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesPage')) {
            $reflection = new ReflectionClass('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesPage');
            $method = $reflection->getMethod('getCanonicalTemplatesInfo');
            $method->setAccessible(true);
            $templates = $method->invoke(null);

            echo '<p>Total: <strong>' . count($templates) . ' templates</strong></p>';
            echo '<table>';
            echo '<thead><tr><th>Fluxo</th><th>Nome</th><th>Tipo</th><th>Canal</th><th>Variáveis</th></tr></thead>';
            echo '<tbody>';
            foreach ($templates as $tpl) {
                echo '<tr>';
                echo '<td><span class="badge badge-success">' . esc_html($tpl['flow_id']) . '</span></td>';
                echo '<td><strong>' . esc_html($tpl['name']) . '</strong><br><small>' . esc_html($tpl['description']) . '</small></td>';
                echo '<td>' . esc_html($tpl['template_type']) . '</td>';
                echo '<td>' . esc_html($tpl['channel']) . '</td>';
                echo '<td><code>' . esc_html(implode(', ', $tpl['variables'])) . '</code></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<div class="check error">❌ Classe não encontrada!</div>';
        }
        ?>
    </div>

    <div class="section">
        <h2>4. Teste de Renderização</h2>
        <?php
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\MessageTemplatesPage')) {
            $_GET['tab'] = 'canonical';
            ob_start();
            try {
                LimpVix\Infrastructure\Admin\Pages\MessageTemplatesPage::render();
                $html = ob_get_clean();
                $size = strlen($html);
                $has_table = strpos($html, 'limpvix-table') !== false;
                $has_tabs = strpos($html, 'limpvix-tabs') !== false;

                echo '<div class="check">';
                echo '✅ Renderização concluída<br>';
                echo 'Tamanho do HTML: <strong>' . number_format($size) . ' bytes</strong><br>';
                echo 'Tem tabela: ' . ($has_table ? '✅' : '❌') . '<br>';
                echo 'Tem tabs: ' . ($has_tabs ? '✅' : '❌');
                echo '</div>';

                echo '<h3>Preview do HTML gerado:</h3>';
                echo '<pre>' . esc_html(substr($html, 0, 1000)) . '...</pre>';

            } catch (Exception $e) {
                ob_end_clean();
                echo '<div class="check error">❌ Erro: ' . esc_html($e->getMessage()) . '</div>';
            }
        }
        ?>
    </div>

    <div class="section">
        <h2>5. Assets Enfileirados</h2>
        <?php
        global $wp_styles, $wp_scripts;

        echo '<h3>CSS:</h3>';
        $limpvix_styles = array_filter($wp_styles->registered, function($handle) {
            return strpos($handle, 'limpvix') !== false;
        }, ARRAY_FILTER_USE_KEY);

        if (empty($limpvix_styles)) {
            echo '<div class="check warning">⚠️ Nenhum CSS do LimpVix enfileirado (normal, são carregados apenas nas páginas admin)</div>';
        } else {
            foreach ($limpvix_styles as $handle => $style) {
                echo '<div class="check">';
                echo '✅ <code>' . esc_html($handle) . '</code><br>';
                echo 'Src: ' . esc_html($style->src);
                echo '</div>';
            }
        }

        echo '<h3>JavaScript:</h3>';
        $limpvix_scripts = array_filter($wp_scripts->registered, function($handle) {
            return strpos($handle, 'limpvix') !== false;
        }, ARRAY_FILTER_USE_KEY);

        if (empty($limpvix_scripts)) {
            echo '<div class="check warning">⚠️ Nenhum JS do LimpVix enfileirado (normal, são carregados apenas nas páginas admin)</div>';
        } else {
            foreach ($limpvix_scripts as $handle => $script) {
                echo '<div class="check">';
                echo '✅ <code>' . esc_html($handle) . '</code><br>';
                echo 'Src: ' . esc_html($script->src);
                echo '</div>';
            }
        }
        ?>
    </div>

    <div class="section">
        <h2>6. Ações Recomendadas</h2>
        <ol>
            <li><strong>Limpar cache do navegador:</strong> Ctrl+Shift+Del (Chrome/Edge) ou Cmd+Shift+Del (Mac)</li>
            <li><strong>Abrir DevTools:</strong> F12 → Aba Console → Verificar erros JavaScript</li>
            <li><strong>Verificar Network:</strong> F12 → Aba Network → Recarregar página → Ver se CSS/JS carregam</li>
            <li><strong>Testar em modo anônimo:</strong> Ctrl+Shift+N (Chrome) sem extensões</li>
            <li><strong>Acessar diretamente:</strong> <a href="<?php echo admin_url('admin.php?page=limpvix-templates&tab=canonical'); ?>" target="_blank">
                <?php echo admin_url('admin.php?page=limpvix-templates&tab=canonical'); ?>
            </a></li>
        </ol>
    </div>

    <div class="section">
        <p style="color: #666; font-size: 13px;">
            <strong>Data/Hora:</strong> <?php echo date('Y-m-d H:i:s'); ?><br>
            <strong>WordPress:</strong> <?php echo get_bloginfo('version'); ?><br>
            <strong>PHP:</strong> <?php echo PHP_VERSION; ?><br>
            <strong>Plugin LimpVix:</strong> 0.1.0
        </p>
    </div>
</div>
</body>
</html>
