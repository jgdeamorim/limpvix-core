<?php
/**
 * Limpar cache do WordPress
 */

// Carregar WordPress
require_once(__DIR__ . '/../../../wp-load.php');

if (!defined('ABSPATH')) {
    die('WordPress não carregado');
}

echo "Limpando cache do WordPress...\n\n";

// Limpar object cache
wp_cache_flush();
echo "✅ Object cache limpo\n";

// Limpar transients expirados
delete_expired_transients();
echo "✅ Transients expirados removidos\n";

// Limpar opcache do PHP se disponível
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✅ OPcache do PHP limpo\n";
} else {
    echo "⚠️ OPcache não disponível\n";
}

echo "\n✅ Cache limpo com sucesso!\n";
echo "\nAgora acesse: http://localhost:8080/wp-admin/admin.php?page=limpvix-settings&tab=fluxos\n";
echo "E faça um HARD REFRESH (Ctrl+F5 ou Cmd+Shift+R)\n";
