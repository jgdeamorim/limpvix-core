#!/usr/bin/env php
<?php
/**
 * test-integrations-cli.php
 *
 * Script CLI para testar integrações das APIs
 * Uso: docker exec limpvix_wordpress php /var/www/html/wp-content/plugins/limpvix-core/diagnostics/test-integrations-cli.php
 */

// Bootstrap WordPress
define('ABSPATH', dirname(dirname(dirname(dirname(__DIR__)))) . '/');
require_once ABSPATH . 'wp-load.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║          TESTE DE INTEGRAÇÕES - LIMPVIX CORE v1.0         ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$results = [];
$total_tests = 0;
$passed_tests = 0;

// ============================================================
// 1. TWILIO SMS
// ============================================================
echo "[1/6] TWILIO SMS\n";
echo str_repeat("-", 60) . "\n";

$twilio_settings = get_option('limpvix_twilio_settings', []);

if (empty($twilio_settings['account_sid']) || empty($twilio_settings['auth_token'])) {
    echo "  Status: ❌ NÃO CONFIGURADO\n";
    echo "  Erro: Credenciais ausentes\n\n";
    $results[] = ['name' => 'Twilio SMS', 'status' => 'failed', 'error' => 'Credenciais ausentes'];
} else {
    echo "  Account SID: " . substr($twilio_settings['account_sid'], 0, 10) . "...\n";
    echo "  From Number: " . ($twilio_settings['from_number'] ?? 'N/A') . "\n";

    // Testar API
    $test_url = sprintf(
        'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json',
        $twilio_settings['account_sid']
    );

    $response = wp_remote_get($test_url, [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(
                $twilio_settings['account_sid'] . ':' . $twilio_settings['auth_token']
            )
        ],
        'timeout' => 10
    ]);

    if (is_wp_error($response)) {
        echo "  API Test: ❌ FALHOU\n";
        echo "  Erro: " . $response->get_error_message() . "\n\n";
        $results[] = ['name' => 'Twilio SMS', 'status' => 'failed', 'error' => $response->get_error_message()];
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            echo "  API Test: ✅ CONECTADO\n\n";
            $results[] = ['name' => 'Twilio SMS', 'status' => 'success'];
            $passed_tests++;
        } else {
            echo "  API Test: ❌ FALHOU (HTTP $code)\n\n";
            $results[] = ['name' => 'Twilio SMS', 'status' => 'failed', 'error' => "HTTP $code"];
        }
    }
}
$total_tests++;

// ============================================================
// 2. GOOGLE MEU NEGÓCIO
// ============================================================
echo "[2/6] GOOGLE MEU NEGÓCIO\n";
echo str_repeat("-", 60) . "\n";

$gmb_settings = get_option('limpvix_google_business_settings', []);

if (empty($gmb_settings['place_id'])) {
    echo "  Status: ❌ NÃO CONFIGURADO\n";
    echo "  Erro: Place ID ausente\n\n";
    $results[] = ['name' => 'Google Meu Negócio', 'status' => 'failed', 'error' => 'Place ID ausente'];
} else {
    echo "  Place ID: " . $gmb_settings['place_id'] . "\n";
    echo "  Business Name: " . ($gmb_settings['business_name'] ?? 'N/A') . "\n";
    echo "  Enabled: " . (($gmb_settings['enabled'] ?? false) ? 'Sim' : 'Não') . "\n";

    // Google Business não tem API direta - validamos apenas configuração
    $review_url = 'https://search.google.com/local/writereview?placeid=' . $gmb_settings['place_id'];
    echo "  Review URL: " . $review_url . "\n";
    echo "  Status: ✅ CONFIGURADO\n\n";
    $results[] = ['name' => 'Google Meu Negócio', 'status' => 'success'];
    $passed_tests++;
}
$total_tests++;

// ============================================================
// 3. MERCADO PAGO PAYOUTS
// ============================================================
echo "[3/6] MERCADO PAGO PAYOUTS\n";
echo str_repeat("-", 60) . "\n";

$mp_status = get_option('limpvix_mp_status', []);
$mp_env = $mp_status['environment'] ?? 'production';
$access_token_key = ($mp_env === 'sandbox') ? 'limpvix_mp_access_token_test' : 'limpvix_mp_access_token_prod';
$mp_access_token = get_option($access_token_key);

if (empty($mp_access_token)) {
    echo "  Status: ❌ NÃO CONFIGURADO\n";
    echo "  Erro: Access Token ausente\n\n";
    $results[] = ['name' => 'Mercado Pago Payouts', 'status' => 'failed', 'error' => 'Access Token ausente'];
} else {
    echo "  Environment: " . $mp_env . "\n";
    echo "  Access Token: " . substr($mp_access_token, 0, 20) . "...\n";

    // Testar API /users/me (endpoint correto da API v2)
    $test_url = 'https://api.mercadopago.com/users/me';

    $response = wp_remote_get($test_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $mp_access_token
        ],
        'timeout' => 10
    ]);

    if (is_wp_error($response)) {
        echo "  API Test: ❌ FALHOU\n";
        echo "  Erro: " . $response->get_error_message() . "\n\n";
        $results[] = ['name' => 'Mercado Pago Payouts', 'status' => 'failed', 'error' => $response->get_error_message()];
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && isset($body['id'])) {
            echo "  API Test: ✅ CONECTADO\n";
            echo "  User ID: " . $body['id'] . "\n";
            echo "  Email: " . ($body['email'] ?? 'N/A') . "\n\n";
            $results[] = ['name' => 'Mercado Pago Payouts', 'status' => 'success'];
            $passed_tests++;
        } else {
            echo "  API Test: ❌ FALHOU (HTTP $code)\n";
            if (isset($body['message'])) {
                echo "  Erro: " . $body['message'] . "\n";
            }
            echo "\n";
            $results[] = ['name' => 'Mercado Pago Payouts', 'status' => 'failed', 'error' => "HTTP $code"];
        }
    }
}
$total_tests++;

// ============================================================
// 4. 360DIALOG WHATSAPP
// ============================================================
echo "[4/6] 360DIALOG WHATSAPP\n";
echo str_repeat("-", 60) . "\n";

$dialog_settings = get_option('limpvix_360dialog_settings', []);

if (empty($dialog_settings['api_key'])) {
    echo "  Status: ❌ NÃO CONFIGURADO\n";
    echo "  Erro: API Key ausente\n\n";
    $results[] = ['name' => '360Dialog WhatsApp', 'status' => 'failed', 'error' => 'API Key ausente'];
} else {
    echo "  API Key: " . substr($dialog_settings['api_key'], 0, 20) . "...\n";
    echo "  Namespace: " . ($dialog_settings['namespace'] ?? 'N/A') . "\n";

    // 360Dialog não tem endpoint de health check simples
    // Validamos apenas configuração
    echo "  Status: ✅ CONFIGURADO (validação de API requer envio de mensagem)\n\n";
    $results[] = ['name' => '360Dialog WhatsApp', 'status' => 'success'];
    $passed_tests++;
}
$total_tests++;

// ============================================================
// 5. WOOCOMMERCE
// ============================================================
echo "[5/6] WOOCOMMERCE\n";
echo str_repeat("-", 60) . "\n";

if (!class_exists('WooCommerce')) {
    echo "  Status: ❌ NÃO INSTALADO\n\n";
    $results[] = ['name' => 'WooCommerce', 'status' => 'failed', 'error' => 'Plugin não instalado'];
} else {
    $wc_version = WC()->version;
    echo "  Versão: " . $wc_version . "\n";
    echo "  Status: ✅ ATIVO\n\n";
    $results[] = ['name' => 'WooCommerce', 'status' => 'success'];
    $passed_tests++;
}
$total_tests++;

// ============================================================
// 6. WOOCOMMERCE MERCADO PAGO
// ============================================================
echo "[6/6] WOOCOMMERCE MERCADO PAGO\n";
echo str_repeat("-", 60) . "\n";

$mp_plugin_active = is_plugin_active('woocommerce-mercadopago/woocommerce-mercadopago.php');

if (!$mp_plugin_active) {
    echo "  Status: ❌ NÃO INSTALADO/ATIVO\n\n";
    $results[] = ['name' => 'WooCommerce Mercado Pago', 'status' => 'failed', 'error' => 'Plugin não ativo'];
} else {
    echo "  Plugin: ✅ ATIVO\n";

    // Verificar credenciais do plugin oficial
    $mp_access_token_prod = get_option('_mp_access_token_prod');
    $mp_access_token_test = get_option('_mp_access_token_test');

    if (!empty($mp_access_token_prod) || !empty($mp_access_token_test)) {
        echo "  Credenciais: ✅ CONFIGURADAS\n";
        echo "  Produção: " . (!empty($mp_access_token_prod) ? 'Sim' : 'Não') . "\n";
        echo "  Teste: " . (!empty($mp_access_token_test) ? 'Sim' : 'Não') . "\n\n";
        $results[] = ['name' => 'WooCommerce Mercado Pago', 'status' => 'success'];
        $passed_tests++;
    } else {
        echo "  Credenciais: ❌ AUSENTES\n\n";
        $results[] = ['name' => 'WooCommerce Mercado Pago', 'status' => 'failed', 'error' => 'Credenciais ausentes'];
    }
}
$total_tests++;

// ============================================================
// RESUMO FINAL
// ============================================================
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                      RESUMO FINAL                          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

foreach ($results as $result) {
    $status_icon = ($result['status'] === 'success') ? '✅' : '❌';
    $status_text = ($result['status'] === 'success') ? 'OK' : 'FALHOU';

    echo sprintf("  %-30s %s %s\n",
        $result['name'],
        $status_icon,
        $status_text
    );

    if (isset($result['error'])) {
        echo "    └─ Erro: " . $result['error'] . "\n";
    }
}

echo "\n";
echo str_repeat("=", 60) . "\n";
$percentage = ($total_tests > 0) ? round(($passed_tests / $total_tests) * 100) : 0;
echo sprintf("  RESULTADO: %d/%d testes passaram (%.0f%%)\n",
    $passed_tests,
    $total_tests,
    $percentage
);
echo str_repeat("=", 60) . "\n";

exit($passed_tests === $total_tests ? 0 : 1);
