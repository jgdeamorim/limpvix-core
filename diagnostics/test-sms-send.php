#!/usr/bin/env php
<?php
/**
 * test-sms-send.php
 *
 * Script CLI para enviar SMS de teste via Twilio
 * Uso: docker exec limpvix_wordpress php /var/www/html/wp-content/plugins/limpvix-core/diagnostics/test-sms-send.php
 */

// Bootstrap WordPress
define('ABSPATH', dirname(dirname(dirname(dirname(__DIR__)))) . '/');
require_once ABSPATH . 'wp-load.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║            TESTE DE ENVIO DE SMS - TWILIO                  ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// Número de destino
$to = '+5527999652302';

// Verificar se TwilioSmsProvider existe
if (!class_exists('LimpVix\\Infrastructure\\Communication\\Providers\\TwilioSmsProvider')) {
    echo "❌ ERRO: TwilioSmsProvider não encontrado\n";
    exit(1);
}

// Inicializar provider
$provider = new \LimpVix\Infrastructure\Communication\Providers\TwilioSmsProvider();

if (!$provider->isAvailable()) {
    echo "❌ ERRO: Twilio não está configurado\n";
    exit(1);
}

echo "📱 Destinatário: $to\n\n";

// ============================================================
// TESTE 1: Enviar como CLIENTE (Implixia)
// ============================================================
echo "[1/2] Enviar como CLIENTE (Implixia)\n";
echo str_repeat("-", 60) . "\n";

$message_client = "Olá! Este é um teste de SMS do sistema LimpVix.\n\n" .
                   "Você está recebendo como CLIENTE (Implixia).\n\n" .
                   "Teste realizado em: " . date('d/m/Y H:i:s');

echo "Mensagem:\n";
echo "  " . str_replace("\n", "\n  ", $message_client) . "\n\n";

echo "Enviando... ";

$result1 = $provider->send($to, $message_client, [
    'recipient_type' => 'client',
    'template_id' => 'test_client',
    'flow_id' => 'manual_test'
]);

if ($result1) {
    echo "✅ SMS ENVIADO COM SUCESSO\n\n";
} else {
    echo "❌ FALHOU AO ENVIAR\n\n";
}

// Aguardar 2 segundos entre envios
sleep(2);

// ============================================================
// TESTE 2: Enviar como PROFISSIONAL
// ============================================================
echo "[2/2] Enviar como PROFISSIONAL\n";
echo str_repeat("-", 60) . "\n";

$message_professional = "Olá! Este é um teste de SMS do sistema LimpVix.\n\n" .
                        "Você está recebendo como PROFISSIONAL.\n\n" .
                        "Teste realizado em: " . date('d/m/Y H:i:s');

echo "Mensagem:\n";
echo "  " . str_replace("\n", "\n  ", $message_professional) . "\n\n";

echo "Enviando... ";

$result2 = $provider->send($to, $message_professional, [
    'recipient_type' => 'professional',
    'template_id' => 'test_professional',
    'flow_id' => 'manual_test'
]);

if ($result2) {
    echo "✅ SMS ENVIADO COM SUCESSO\n\n";
} else {
    echo "❌ FALHOU AO ENVIAR\n\n";
}

// ============================================================
// RESUMO FINAL
// ============================================================
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                      RESUMO FINAL                          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$success_count = ($result1 ? 1 : 0) + ($result2 ? 1 : 0);

echo "  Teste 1 (Cliente):      " . ($result1 ? "✅ OK" : "❌ FALHOU") . "\n";
echo "  Teste 2 (Profissional): " . ($result2 ? "✅ OK" : "❌ FALHOU") . "\n\n";

echo str_repeat("=", 60) . "\n";
echo sprintf("  RESULTADO: %d/2 SMS enviados com sucesso\n", $success_count);
echo str_repeat("=", 60) . "\n";

exit($success_count === 2 ? 0 : 1);
