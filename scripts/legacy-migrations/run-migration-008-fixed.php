<?php
/**
 * Migration 008: Communication Tables
 */

// Definir WordPress root (para execução standalone)
if (!defined('ABSPATH')) {
    define('ABSPATH', '/var/www/html/');
    require_once ABSPATH . 'wp-load.php';
}

global $wpdb;

echo "Executando Migration 008: Communication Tables\n\n";

// =====================================================
// Tabela 1: wp_limpvix_message_templates
// =====================================================
$sql1 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}limpvix_message_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id VARCHAR(50) NOT NULL COMMENT 'ID único (T-BOOKING-01)',
    version VARCHAR(10) NOT NULL COMMENT 'Versão semântica (1.2)',
    content LONGTEXT NOT NULL COMMENT 'Conteúdo com placeholders {{var}}',
    required_variables JSON NOT NULL COMMENT 'Array de variáveis requeridas',
    channel VARCHAR(20) NOT NULL COMMENT 'whatsapp|sms|email|push',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Template ativo',
    created_at DATETIME NOT NULL COMMENT 'Data de criação',

    UNIQUE KEY unique_template_version (template_id, version),
    INDEX idx_template_id (template_id),
    INDEX idx_channel (channel),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Message Templates - Templates versionados';";

$result1 = $wpdb->query($sql1);
echo ($result1 !== false ? "✅" : "❌") . " wp_limpvix_message_templates\n";

// =====================================================
// Tabela 2: wp_limpvix_message_log
// =====================================================
$sql2 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}limpvix_message_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(100) NOT NULL UNIQUE COMMENT 'ID único da mensagem',
    template_id VARCHAR(50) NOT NULL COMMENT 'Template usado',
    template_version VARCHAR(10) NOT NULL COMMENT 'Versão do template',
    recipient VARCHAR(255) NOT NULL COMMENT 'Destinatário (phone/email)',
    channel VARCHAR(20) NOT NULL COMMENT 'whatsapp|sms|email|push',
    content LONGTEXT NULL COMMENT 'Mensagem renderizada',

    status VARCHAR(20) NOT NULL COMMENT 'pending|sent|delivered|read|failed',
    retry_count INT DEFAULT 0 COMMENT 'Número de tentativas',
    sent_at DATETIME NULL COMMENT 'Timestamp de envio',
    delivered_at DATETIME NULL COMMENT 'Timestamp de entrega',
    read_at DATETIME NULL COMMENT 'Timestamp de leitura',
    failed_at DATETIME NULL COMMENT 'Timestamp de falha',

    event_id VARCHAR(100) NULL COMMENT 'ID do evento de domínio que disparou',
    event_type VARCHAR(100) NULL COMMENT 'Tipo do evento',
    user_id BIGINT UNSIGNED NULL COMMENT 'User ID relacionado',

    error_type VARCHAR(100) NULL COMMENT 'Tipo do erro',
    error_message TEXT NULL COMMENT 'Mensagem de erro',

    provider_response JSON NULL COMMENT 'Resposta do provider',

    created_at DATETIME NOT NULL COMMENT 'Data de criação',

    INDEX idx_message_id (message_id),
    INDEX idx_template_id (template_id),
    INDEX idx_recipient (recipient),
    INDEX idx_status (status),
    INDEX idx_event_id (event_id),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_failed_at (failed_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Message Log - Histórico de envios (append-only)';";

$result2 = $wpdb->query($sql2);
echo ($result2 !== false ? "✅" : "❌") . " wp_limpvix_message_log\n";

// =====================================================
// Tabela 3: wp_limpvix_message_queue
// =====================================================
$sql3 = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}limpvix_message_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id VARCHAR(100) NOT NULL COMMENT 'ID da mensagem original',
    template_id VARCHAR(50) NOT NULL COMMENT 'Template para retry',
    recipient VARCHAR(255) NOT NULL COMMENT 'Destinatário',
    variables JSON NOT NULL COMMENT 'Variáveis do template',

    event_id VARCHAR(100) NULL COMMENT 'Evento original',
    event_type VARCHAR(100) NULL COMMENT 'Tipo do evento',
    user_id BIGINT UNSIGNED NULL COMMENT 'User ID',

    retry_count INT NOT NULL COMMENT 'Número da tentativa (1, 2, 3)',
    scheduled_at DATETIME NOT NULL COMMENT 'Quando deve ser processado',
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|completed|failed',

    created_at DATETIME NOT NULL COMMENT 'Data de criação',
    processed_at DATETIME NULL COMMENT 'Data de processamento',

    INDEX idx_message_id (message_id),
    INDEX idx_template_id (template_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_at (scheduled_at),
    INDEX idx_status_scheduled (status, scheduled_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Message Queue - Fila de retry';";

$result3 = $wpdb->query($sql3);
echo ($result3 !== false ? "✅" : "❌") . " wp_limpvix_message_queue\n";

// =====================================================
// Seed: Popular templates iniciais
// =====================================================
echo "\nPopulando templates iniciais:\n";

$templates = [
    ['T-BOOKING-01', '1.0', 'Olá {{client_name}}! 👋\n\nSeu serviço foi agendado com sucesso para {{appointment_date}} às {{appointment_time}}.\n\nProfissional: {{professional_name}}\n\nEquipe Limpvix', '["client_name", "appointment_date", "appointment_time", "professional_name"]', 'whatsapp'],
    ['T-REMINDER-24H', '1.0', 'Lembrete: Seu serviço está agendado para amanhã, {{appointment_date}}, entre {{window_start}} e {{window_end}}.\n\nProfissional {{professional_name}} chegará nesse horário.\n\nEquipe Limpvix', '["appointment_date", "window_start", "window_end", "professional_name"]', 'whatsapp'],
    ['T-ON-THE-WAY', '1.0', 'Olá {{client_name}}! 👋\n\nO profissional {{professional_name}} está a caminho.\n\nChegada prevista entre {{window_start}} e {{window_end}}.\n\nDúvidas? Responda esta mensagem.', '["client_name", "professional_name", "window_start", "window_end"]', 'whatsapp'],
    ['T-CHECKIN', '1.0', 'Serviço iniciado! ✅\n\nProfissional {{professional_name}} fez check-in às {{checkin_time}}.\n\nVocê pode acompanhar o andamento pelo app.\n\nEquipe Limpvix', '["professional_name", "checkin_time"]', 'whatsapp'],
    ['T-CHECKOUT', '1.0', 'Serviço concluído! 🎉\n\nProfissional {{professional_name}} finalizou o serviço às {{checkout_time}}.\n\nComo foi a experiência? Sua avaliação é muito importante:\n\n👉 {{feedback_url}}\n\nObrigado!', '["professional_name", "checkout_time", "feedback_url"]', 'whatsapp'],
    ['T-FEEDBACK-D1', '1.0', 'Olá {{client_name}}! 👋\n\nO serviço realizado ontem atendeu suas expectativas?\n\nSua avaliação ajuda a Limpvix a manter a qualidade ⭐\n\n👉 Avaliar agora: {{feedback_url}}\n\nEquipe Limpvix', '["client_name", "feedback_url"]', 'whatsapp'],
    ['T-SLA-ISSUE', '1.0', 'ALERTA: Violação de SLA detectada\n\nOrder: {{order_uuid}}\nTipo: {{violation_type}}\nProfissional: {{professional_name}}\nTimestamp: {{occurred_at}}', '["order_uuid", "violation_type", "professional_name", "occurred_at"]', 'email'],
];

$inserted = 0;
foreach ($templates as $tpl) {
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_message_templates WHERE template_id = %s AND version = %s",
        $tpl[0],
        $tpl[1]
    ));

    if ($exists > 0) {
        echo "  ⏭️  {$tpl[0]} (já existe)\n";
        continue;
    }

    $result = $wpdb->insert(
        "{$wpdb->prefix}limpvix_message_templates",
        [
            'template_id' => $tpl[0],
            'version' => $tpl[1],
            'content' => $tpl[2],
            'required_variables' => $tpl[3],
            'channel' => $tpl[4],
            'is_active' => 1,
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%s', '%s', '%d', '%s']
    );

    echo ($result !== false ? "  ✅ " : "  ❌ ") . "{$tpl[0]}\n";
    if ($result !== false) $inserted++;
}

echo "\n✅ Migration 008 completa!\n";
echo "Templates inseridos: $inserted\n";
