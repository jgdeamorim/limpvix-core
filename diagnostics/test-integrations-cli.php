#!/usr/bin/env php
<?php
/**
 * Test Integrations CLI - Diagnóstico via linha de comando
 */

// Carregar WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

// Usar CredentialProvider
use LimpVix\Infrastructure\Providers\WordPressCredentialProvider;

$credentials = new WordPressCredentialProvider();

echo "=============================================================\n";
echo "LIMPVIX - DIAGNÓSTICO DE INTEGRAÇÕES\n";
echo "=============================================================\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

$results = [];
$totalTests = 0;
$passedTests = 0;

// 1. TWILIO SMS
echo "1. TWILIO SMS\n";
echo "-------------------------------------------------------------\n";
$totalTests++;

if ($credentials->has('twilio.account_sid') &&
    $credentials->has('twilio.auth_token') &&
    $credentials->has('twilio.from_number')) {

    $twilioSid = $credentials->get('twilio.account_sid');
    $twilioToken = $credentials->get('twilio.auth_token');
    $twilioFrom = $credentials->get('twilio.from_number');

    echo "✅ Credenciais configuradas\n";
    echo "   SID: " . substr($twilioSid, 0, 10) . "...\n";
    $passedTests++;
    $results['twilio'] = 'OK';
} else {
    echo "❌ Credenciais NÃO configuradas\n";
    $results['twilio'] = 'NOT_CONFIGURED';
}
echo "\n";

// 2. 360DIALOG
echo "2. 360DIALOG WHATSAPP\n";
echo "-------------------------------------------------------------\n";
$totalTests++;

if ($credentials->has('whatsapp.api_key')) {
    echo "✅ API Key configurada\n";
    $passedTests++;
    $results['360dialog'] = 'OK';
} else {
    echo "❌ API Key NÃO configurada\n";
    $results['360dialog'] = 'NOT_CONFIGURED';
}
echo "\n";

// 3. MERCADO PAGO
echo "3. MERCADO PAGO PAYOUTS\n";
echo "-------------------------------------------------------------\n";
$totalTests++;

if ($credentials->has('mercadopago.access_token')) {
    $mpToken = $credentials->get('mercadopago.access_token');
    echo "✅ Access Token configurado\n";
    echo "   Token: " . substr($mpToken, 0, 15) . "...\n";
    echo "   Fonte: WooCommerce Mercado Pago\n";
    $passedTests++;
    $results['mercadopago'] = 'OK';
} else {
    echo "❌ Access Token NÃO configurado\n";
    $results['mercadopago'] = 'NOT_CONFIGURED';
}
echo "\n";

// 4. GOOGLE MEU NEGÓCIO
echo "4. GOOGLE MEU NEGÓCIO\n";
echo "-------------------------------------------------------------\n";
$totalTests++;

if ($credentials->has('gmb.place_id') && $credentials->has('gmb.review_url')) {
    echo "✅ Configurado\n";
    $passedTests++;
    $results['gmb'] = 'OK';
} else {
    echo "❌ NÃO configurado\n";
    $results['gmb'] = 'NOT_CONFIGURED';
}
echo "\n";

// 5. WOOCOMMERCE
echo "5. WOOCOMMERCE\n";
echo "-------------------------------------------------------------\n";
$totalTests++;

if (class_exists('WooCommerce')) {
    echo "✅ Ativo (v" . WC()->version . ")\n";
    $passedTests++;
    $results['woocommerce'] = 'OK';
} else {
    echo "❌ NÃO instalado\n";
    $results['woocommerce'] = 'NOT_INSTALLED';
}
echo "\n";

// 6. BOOKNETIC
echo "6. BOOKNETIC\n";
echo "-------------------------------------------------------------\n";
$totalTests++;

if (is_plugin_active('booknetic/init.php')) {
    echo "✅ Ativo\n";
    $passedTests++;
    $results['booknetic'] = 'OK';
} else {
    echo "❌ NÃO ativo\n";
    $results['booknetic'] = 'NOT_ACTIVE';
}
echo "\n";

// RESUMO
echo "=============================================================\n";
echo "RESUMO FINAL\n";
echo "=============================================================\n";
echo "Total: $totalTests | Sucesso: $passedTests | Falhas: " . ($totalTests - $passedTests) . "\n";
echo "Taxa: " . round(($passedTests / $totalTests) * 100) . "%\n\n";

foreach ($results as $integration => $status) {
    $icon = $status === 'OK' ? '✅' : '❌';
    echo sprintf("%-25s %s %s\n", strtoupper($integration), $icon, $status);
}
echo "\n";

if ($passedTests === $totalTests) {
    echo "🎉 VEREDITO: TODAS INTEGRAÇÕES OK\n";
    exit(0);
} else {
    echo "⚠️ VEREDITO: REVISAR FALHAS\n";
    exit(1);
}
