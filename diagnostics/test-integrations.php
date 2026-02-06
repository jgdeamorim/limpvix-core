<?php
/**
 * Test Integrations - Diagnóstico de APIs
 *
 * Script para testar todas as integrações:
 * - Twilio SMS
 * - Google My Business
 * - Mercado Pago (Payouts)
 * - 360Dialog WhatsApp
 * - WooCommerce
 * - WooCommerce Mercado Pago
 *
 * Uso: Acessar via browser: /wp-content/plugins/limpvix-core/diagnostics/test-integrations.php
 */

// Carregar WordPress
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

// Verificar permissões
if (!current_user_can('manage_options')) {
    wp_die('Acesso negado. Faça login como administrador.');
}

// Header HTML
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>LimpVix - Diagnóstico de Integrações</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .test-section {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .test-section h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }
        .status.success { background: #d1fae5; color: #065f46; }
        .status.warning { background: #fef3c7; color: #92400e; }
        .status.error { background: #fee2e2; color: #991b1b; }
        .status.info { background: #dbeafe; color: #1e40af; }
        .test-result {
            margin: 15px 0;
            padding: 15px;
            background: #f9fafb;
            border-left: 4px solid #d1d5db;
            border-radius: 4px;
        }
        .test-result.success { border-left-color: #10b981; }
        .test-result.error { border-left-color: #ef4444; }
        .test-result.warning { border-left-color: #f59e0b; }
        pre {
            background: #1f2937;
            color: #f3f4f6;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        .metric {
            display: inline-block;
            margin: 10px 10px 10px 0;
            padding: 10px 15px;
            background: #f3f4f6;
            border-radius: 4px;
        }
        .metric strong { color: #667eea; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔬 LimpVix - Diagnóstico de Integrações</h1>
        <p>Testando todas as APIs e integrações do sistema</p>
        <p style="font-size: 14px; opacity: 0.9;">Data: <?php echo date('d/m/Y H:i:s'); ?></p>
    </div>

<?php

// ============================================================================
// TESTE 1: TWILIO SMS
// ============================================================================
echo '<div class="test-section">';
echo '<h2>📱 1. Twilio SMS</h2>';

$twilio_settings = get_option('limpvix_twilio_settings', []);
$twilio_configured = !empty($twilio_settings['account_sid'])
    && !empty($twilio_settings['auth_token'])
    && !empty($twilio_settings['from_number'])
    && !empty($twilio_settings['enabled']);

if ($twilio_configured) {
    echo '<div class="test-result success">';
    echo '<span class="status success">✅ Configurado</span><br><br>';
    echo '<div class="metric"><strong>Account SID:</strong> ' . substr($twilio_settings['account_sid'], 0, 8) . '***</div>';
    echo '<div class="metric"><strong>From Number:</strong> ' . esc_html($twilio_settings['from_number']) . '</div>';
    echo '<div class="metric"><strong>Status:</strong> ' . ($twilio_settings['enabled'] ? 'Habilitado' : 'Desabilitado') . '</div>';

    // Testar API Twilio
    echo '<br><strong>Testando API Twilio...</strong><br>';

    $auth = base64_encode($twilio_settings['account_sid'] . ':' . $twilio_settings['auth_token']);
    $apiUrl = "https://api.twilio.com/2010-04-01/Accounts/{$twilio_settings['account_sid']}.json";

    $response = wp_remote_get($apiUrl, [
        'headers' => [
            'Authorization' => 'Basic ' . $auth
        ],
        'timeout' => 15
    ]);

    if (is_wp_error($response)) {
        echo '<div class="test-result error">';
        echo '<span class="status error">❌ Erro de Conexão</span><br>';
        echo 'Erro: ' . $response->get_error_message();
        echo '</div>';
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code === 200) {
            echo '<div class="test-result success">';
            echo '<span class="status success">✅ API Conectada</span><br><br>';
            echo '<div class="metric"><strong>Status HTTP:</strong> 200 OK</div>';
            echo '<div class="metric"><strong>Account Name:</strong> ' . esc_html($data['friendly_name'] ?? 'N/A') . '</div>';
            echo '<div class="metric"><strong>Type:</strong> ' . esc_html($data['type'] ?? 'N/A') . '</div>';
            echo '<div class="metric"><strong>Status:</strong> ' . esc_html($data['status'] ?? 'N/A') . '</div>';
            echo '</div>';
        } else {
            echo '<div class="test-result error">';
            echo '<span class="status error">❌ Erro API</span><br>';
            echo 'Status HTTP: ' . $status_code . '<br>';
            echo '<pre>' . esc_html(substr($body, 0, 500)) . '</pre>';
            echo '</div>';
        }
    }

    echo '</div>';
} else {
    echo '<div class="test-result warning">';
    echo '<span class="status warning">⚠️ Não Configurado</span><br>';
    echo 'Configure as credenciais Twilio em LimpVix → Configurações';
    echo '</div>';
}

echo '</div>';

// ============================================================================
// TESTE 2: GOOGLE MEU NEGÓCIO
// ============================================================================
echo '<div class="test-section">';
echo '<h2>🌍 2. Google Meu Negócio</h2>';

$google_settings = get_option('limpvix_google_business_settings', []);
$google_configured = !empty($google_settings['place_id']) && !empty($google_settings['enabled']);

if ($google_configured) {
    echo '<div class="test-result success">';
    echo '<span class="status success">✅ Configurado</span><br><br>';
    echo '<div class="metric"><strong>Place ID:</strong> ' . esc_html($google_settings['place_id']) . '</div>';
    echo '<div class="metric"><strong>Business Name:</strong> ' . esc_html($google_settings['business_name'] ?? 'N/A') . '</div>';
    echo '<div class="metric"><strong>Status:</strong> ' . ($google_settings['enabled'] ? 'Habilitado' : 'Desabilitado') . '</div>';

    // Gerar URL de teste
    $review_url = 'https://search.google.com/local/writereview?placeid=' . $google_settings['place_id'];
    echo '<br><strong>URL de Review:</strong><br>';
    echo '<pre>' . esc_html($review_url) . '</pre>';
    echo '<a href="' . esc_url($review_url) . '" target="_blank" class="status info">🔗 Testar Link</a>';

    echo '</div>';
} else {
    echo '<div class="test-result warning">';
    echo '<span class="status warning">⚠️ Não Configurado</span><br>';
    echo 'Configure o Place ID em LimpVix → Configurações';
    echo '</div>';
}

echo '</div>';

// ============================================================================
// TESTE 3: MERCADO PAGO (PAYOUTS)
// ============================================================================
echo '<div class="test-section">';
echo '<h2>💳 3. Mercado Pago (Payouts)</h2>';

$mp_status = get_option('limpvix_mp_status', []);
$environment = $mp_status['environment'] ?? 'production';
$mp_connected = !empty($mp_status['connected']);

if ($mp_connected) {
    echo '<div class="test-result success">';
    echo '<span class="status success">✅ Conectado</span><br><br>';
    echo '<div class="metric"><strong>Environment:</strong> ' . esc_html($environment) . '</div>';
    echo '<div class="metric"><strong>Source:</strong> ' . esc_html($mp_status['source'] ?? 'N/A') . '</div>';
    echo '<div class="metric"><strong>Last Sync:</strong> ' . date('d/m/Y H:i:s', $mp_status['last_sync'] ?? 0) . '</div>';

    // Buscar access token
    $access_token_key = ($environment === 'sandbox') ? 'limpvix_mp_access_token_test' : 'limpvix_mp_access_token_prod';
    $access_token = get_option($access_token_key);

    if ($access_token) {
        echo '<div class="metric"><strong>Access Token:</strong> ' . substr($access_token, 0, 12) . '***</div>';

        // Testar API Mercado Pago
        echo '<br><strong>Testando API Mercado Pago...</strong><br>';

        $api_url = 'https://api.mercadopago.com/v1/users/me';
        $response = wp_remote_get($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            echo '<div class="test-result error">';
            echo '<span class="status error">❌ Erro de Conexão</span><br>';
            echo 'Erro: ' . $response->get_error_message();
            echo '</div>';
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($status_code === 200) {
                echo '<div class="test-result success">';
                echo '<span class="status success">✅ API Conectada</span><br><br>';
                echo '<div class="metric"><strong>User ID:</strong> ' . esc_html($data['id'] ?? 'N/A') . '</div>';
                echo '<div class="metric"><strong>Email:</strong> ' . esc_html($data['email'] ?? 'N/A') . '</div>';
                echo '<div class="metric"><strong>Site ID:</strong> ' . esc_html($data['site_id'] ?? 'N/A') . '</div>';
                echo '<div class="metric"><strong>Nickname:</strong> ' . esc_html($data['nickname'] ?? 'N/A') . '</div>';
                echo '</div>';
            } else {
                echo '<div class="test-result error">';
                echo '<span class="status error">❌ Erro API</span><br>';
                echo 'Status HTTP: ' . $status_code . '<br>';
                echo '<pre>' . esc_html(substr($body, 0, 500)) . '</pre>';
                echo '</div>';
            }
        }
    } else {
        echo '<div class="test-result warning">';
        echo '<span class="status warning">⚠️ Access Token não encontrado</span>';
        echo '</div>';
    }

    echo '</div>';
} else {
    echo '<div class="test-result warning">';
    echo '<span class="status warning">⚠️ Não Conectado</span><br>';
    echo 'Configure o plugin WooCommerce Mercado Pago';
    echo '</div>';
}

echo '</div>';

// ============================================================================
// TESTE 4: 360DIALOG WHATSAPP
// ============================================================================
echo '<div class="test-section">';
echo '<h2>💬 4. 360Dialog WhatsApp</h2>';

$dialog_settings = get_option('limpvix_360dialog_settings', []);
$dialog_configured = !empty($dialog_settings['api_key']) && !empty($dialog_settings['enabled']);

if ($dialog_configured) {
    echo '<div class="test-result success">';
    echo '<span class="status success">✅ Configurado</span><br><br>';
    echo '<div class="metric"><strong>API Key:</strong> ' . substr($dialog_settings['api_key'], 0, 12) . '***</div>';
    if (!empty($dialog_settings['namespace'])) {
        echo '<div class="metric"><strong>Namespace:</strong> ' . esc_html($dialog_settings['namespace']) . '</div>';
    }
    echo '<div class="metric"><strong>Status:</strong> ' . ($dialog_settings['enabled'] ? 'Habilitado' : 'Desabilitado') . '</div>';

    // Testar API 360Dialog
    echo '<br><strong>Testando API 360Dialog...</strong><br>';

    $api_url = 'https://waba.360dialog.io/v1/configs/webhook';
    $response = wp_remote_get($api_url, [
        'headers' => [
            'D360-API-KEY' => $dialog_settings['api_key'],
            'Content-Type' => 'application/json'
        ],
        'timeout' => 15
    ]);

    if (is_wp_error($response)) {
        echo '<div class="test-result error">';
        echo '<span class="status error">❌ Erro de Conexão</span><br>';
        echo 'Erro: ' . $response->get_error_message();
        echo '</div>';
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($status_code === 200) {
            echo '<div class="test-result success">';
            echo '<span class="status success">✅ API Conectada</span><br><br>';
            echo '<div class="metric"><strong>Status HTTP:</strong> 200 OK</div>';
            if (!empty($data['url'])) {
                echo '<div class="metric"><strong>Webhook URL:</strong> ' . esc_html($data['url']) . '</div>';
            }
            echo '</div>';
        } else {
            echo '<div class="test-result error">';
            echo '<span class="status error">❌ Erro API</span><br>';
            echo 'Status HTTP: ' . $status_code . '<br>';
            echo '<pre>' . esc_html(substr($body, 0, 500)) . '</pre>';
            echo '</div>';
        }
    }

    echo '</div>';
} else {
    echo '<div class="test-result warning">';
    echo '<span class="status warning">⚠️ Não Configurado</span><br>';
    echo 'Configure a API Key do 360Dialog em LimpVix → Configurações';
    echo '</div>';
}

echo '</div>';

// ============================================================================
// TESTE 5: WOOCOMMERCE
// ============================================================================
echo '<div class="test-section">';
echo '<h2>🛒 5. WooCommerce</h2>';

if (class_exists('WooCommerce')) {
    echo '<div class="test-result success">';
    echo '<span class="status success">✅ Instalado e Ativo</span><br><br>';

    global $woocommerce;
    echo '<div class="metric"><strong>Versão:</strong> ' . esc_html($woocommerce->version) . '</div>';
    echo '<div class="metric"><strong>DB Versão:</strong> ' . get_option('woocommerce_db_version') . '</div>';

    // Contar produtos e pedidos
    $product_count = wp_count_posts('product');
    $order_count = wp_count_posts('shop_order');

    echo '<div class="metric"><strong>Produtos:</strong> ' . ($product_count->publish ?? 0) . '</div>';
    echo '<div class="metric"><strong>Pedidos:</strong> ' . array_sum((array)$order_count) . '</div>';

    echo '</div>';
} else {
    echo '<div class="test-result error">';
    echo '<span class="status error">❌ Não Instalado</span><br>';
    echo 'WooCommerce não está instalado ou ativo';
    echo '</div>';
}

echo '</div>';

// ============================================================================
// TESTE 6: WOOCOMMERCE MERCADO PAGO
// ============================================================================
echo '<div class="test-section">';
echo '<h2>💰 6. WooCommerce Mercado Pago</h2>';

// Verificar se plugin está ativo
$mp_plugin_active = is_plugin_active('woocommerce-mercadopago/woocommerce-mercadopago.php');

if ($mp_plugin_active) {
    echo '<div class="test-result success">';
    echo '<span class="status success">✅ Instalado e Ativo</span><br><br>';

    // Buscar credenciais do plugin oficial
    $mp_prod_access = get_option('_mp_access_token_prod');
    $mp_test_access = get_option('_mp_access_token_test');
    $mp_site_id = get_option('_site_id_v1');
    $mp_public_prod = get_option('_mp_public_key_prod');
    $mp_public_test = get_option('_mp_public_key_test');

    echo '<div class="metric"><strong>Access Token Prod:</strong> ' . (!empty($mp_prod_access) ? substr($mp_prod_access, 0, 12) . '***' : 'Não configurado') . '</div>';
    echo '<div class="metric"><strong>Access Token Test:</strong> ' . (!empty($mp_test_access) ? substr($mp_test_access, 0, 12) . '***' : 'Não configurado') . '</div>';
    echo '<div class="metric"><strong>Public Key Prod:</strong> ' . (!empty($mp_public_prod) ? substr($mp_public_prod, 0, 12) . '***' : 'Não configurado') . '</div>';
    echo '<div class="metric"><strong>Public Key Test:</strong> ' . (!empty($mp_public_test) ? substr($mp_public_test, 0, 12) . '***' : 'Não configurado') . '</div>';
    echo '<div class="metric"><strong>Site ID:</strong> ' . esc_html($mp_site_id ?: 'N/A') . '</div>';

    echo '</div>';
} else {
    echo '<div class="test-result warning">';
    echo '<span class="status warning">⚠️ Não Instalado/Ativo</span><br>';
    echo 'Plugin WooCommerce Mercado Pago não está instalado ou ativo';
    echo '</div>';
}

echo '</div>';

// ============================================================================
// RESUMO FINAL
// ============================================================================
echo '<div class="test-section" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">';
echo '<h2 style="color: white; border-bottom-color: white;">📊 Resumo Final</h2>';

$total_tests = 6;
$passed_tests = 0;

if ($twilio_configured) $passed_tests++;
if ($google_configured) $passed_tests++;
if ($mp_connected) $passed_tests++;
if ($dialog_configured) $passed_tests++;
if (class_exists('WooCommerce')) $passed_tests++;
if ($mp_plugin_active) $passed_tests++;

$percentage = round(($passed_tests / $total_tests) * 100);

echo '<div style="font-size: 48px; text-align: center; margin: 20px 0;">';
echo $passed_tests . ' / ' . $total_tests;
echo '</div>';
echo '<div style="text-align: center; font-size: 18px; margin: 10px 0;">';
echo $percentage . '% das integrações configuradas';
echo '</div>';

echo '<div style="margin-top: 30px;">';
echo '<strong>Status Individual:</strong><br><br>';
echo '📱 Twilio SMS: ' . ($twilio_configured ? '✅ OK' : '❌ Pendente') . '<br>';
echo '🌍 Google Business: ' . ($google_configured ? '✅ OK' : '❌ Pendente') . '<br>';
echo '💳 Mercado Pago: ' . ($mp_connected ? '✅ OK' : '❌ Pendente') . '<br>';
echo '💬 360Dialog: ' . ($dialog_configured ? '✅ OK' : '❌ Pendente') . '<br>';
echo '🛒 WooCommerce: ' . (class_exists('WooCommerce') ? '✅ OK' : '❌ Pendente') . '<br>';
echo '💰 WC Mercado Pago: ' . ($mp_plugin_active ? '✅ OK' : '❌ Pendente') . '<br>';
echo '</div>';

echo '</div>';

?>

<div style="text-align: center; margin-top: 30px; padding: 20px; background: white; border-radius: 8px;">
    <p><strong>🔄 Atualizar Diagnóstico</strong></p>
    <button onclick="location.reload()" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
        Recarregar Página
    </button>
</div>

</body>
</html>
