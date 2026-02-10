# Cron Job Monitoring - Guia de Configuração

**SPRINT 7 - Item 1.9 - Cron Job Monitoring**

## 📋 Visão Geral

O LimpVix agora possui um sistema completo de monitoramento de cron jobs que permite:

1. **Visibilidade Interna**: Widget no dashboard do WordPress Admin
2. **Monitoring Externo**: Health check endpoint público para serviços como UptimeRobot/Pingdom
3. **Alertas Automáticos**: Notificações quando cron jobs param de executar

---

## 🔧 Componentes do Sistema

### 1. CronMonitor Service
**Arquivo:** `src/Application/Services/CronMonitor.php`

- Registra início/fim de execução de cada cron job
- Armazena dados em `wp_options` (chave: `limpvix_cron_last_run_{job_name}`)
- Calcula health status baseado em idade da última execução
- Fornece dados para endpoint e widget

### 2. Health Check REST API
**Arquivo:** `src/Infrastructure/API/HealthController.php`

**Endpoints:**
- `GET /wp-json/limpvix/v1/health/cron` - Status dos cron jobs
- `GET /wp-json/limpvix/v1/health` - Alias (backward compatibility)

**Características:**
- Público (sem autenticação) por design
- Não expõe dados sensíveis
- Retorna HTTP 200 se healthy, HTTP 503 se degraded

### 3. Admin Dashboard Widget
**Arquivo:** `src/Infrastructure/Admin/Widgets/CronHealthWidget.php`

- Widget visual no dashboard admin
- Indicadores coloridos (verde/amarelo/vermelho)
- Mostra última execução, tempo decorrido, status, erros
- Visível apenas para administradores

---

## 🎯 Cron Jobs Monitorados

### check_contract_expiration
**Frequência:** Diário (daily)
**Max Age:** 25 horas
**Descrição:** Expira contratos que atingiram end_date

**Health States:**
- ✅ **healthy**: Executou com sucesso nas últimas 25h
- ⚠️ **warning**: Executou nas últimas 25h mas teve erro
- 🔴 **critical**: Não executou há mais de 25h
- ❓ **unknown**: Nunca executou

---

## 🚀 Configuração de Monitoring Externo

### Opção 1: UptimeRobot (Recomendado - Free)

**Passos:**

1. **Criar conta:** https://uptimerobot.com (plano Free disponível)

2. **Adicionar Monitor:**
   - Type: **HTTP(s)**
   - Friendly Name: `LimpVix - Cron Jobs Health`
   - URL: `https://seudominio.com.br/wp-json/limpvix/v1/health/cron`
   - Monitoring Interval: **5 minutes** (Free) ou **1 minute** (Paid)

3. **Configurar Alertas:**
   - Alert Contacts: adicionar email, SMS, Slack, etc.
   - Alert When: **Down** (HTTP status != 200)

4. **Advanced Settings:**
   - Custom HTTP Headers: (vazio - não precisa autenticação)
   - Keyword Monitoring: (opcional) procurar por `"status":"healthy"`

**Exemplo de Resposta Esperada (HTTP 200):**
```json
{
  "status": "healthy",
  "timestamp": "2026-02-10 12:34:56",
  "jobs": [
    {
      "job_name": "check_contract_expiration",
      "health": "healthy",
      "last_run": "2026-02-10 00:00:12",
      "age_hours": 12.5,
      "status": "success",
      "duration_ms": 1234
    }
  ],
  "metadata": {
    "plugin_version": "0.7.0",
    "wp_version": "6.5",
    "php_version": "8.2.15"
  }
}
```

**Exemplo de Resposta Degraded (HTTP 503):**
```json
{
  "status": "degraded",
  "timestamp": "2026-02-10 12:34:56",
  "jobs": [
    {
      "job_name": "check_contract_expiration",
      "health": "critical",
      "message": "Last run 30.5 hours ago (threshold: 25 hours)",
      "last_run": "2026-02-09 06:00:00",
      "age_hours": 30.5,
      "status": "success"
    }
  ]
}
```

---

### Opção 2: Pingdom

**Passos:**

1. **Criar conta:** https://www.pingdom.com (pago, mas mais recursos)

2. **Add New Check:**
   - Check Type: **Uptime**
   - Name: `LimpVix Cron Health`
   - URL: `https://seudominio.com.br/wp-json/limpvix/v1/health/cron`
   - Check interval: **1 minute** (recomendado)

3. **Alerting:**
   - Down alerts: ativar
   - When down for: **5 minutes** (evitar falsos positivos)
   - Alert via: email, SMS, Slack, PagerDuty, etc.

4. **Advanced:**
   - Response time alert: (opcional) alertar se > 5s
   - Keyword check: (opcional) `"status":"healthy"`

---

### Opção 3: Healthchecks.io (Simples e Free)

**Passos:**

1. **Criar conta:** https://healthchecks.io (Free para 20 checks)

2. **Add Check:**
   - Name: `LimpVix - check_contract_expiration`
   - Schedule: **Daily** (1440 minutes)
   - Grace Time: **1 hour** (60 minutes)

3. **Integrations:**
   - Email, Slack, Discord, Telegram, etc.

4. **Ping URL:**
   - Usar webhook para chamar o ping URL **depois** do cron executar
   - Adicionar no `CronMonitor::recordEnd()`:

```php
// Em CronMonitor.php, após recordEnd() com success:
if ($status === self::STATUS_SUCCESS) {
    // Ping Healthchecks.io
    $healthchecksUrl = get_option('limpvix_healthchecks_ping_url');
    if ($healthchecksUrl) {
        wp_remote_get($healthchecksUrl);
    }
}
```

**Configuração no WordPress:**
```bash
wp option add limpvix_healthchecks_ping_url 'https://hc-ping.com/your-unique-uuid'
```

---

### Opção 4: Custom Monitoring (Zabbix, Nagios, Prometheus)

Para setups corporativos com monitoring próprio:

**HTTP Endpoint Check:**
```bash
# Exemplo com curl
curl -f https://seudominio.com.br/wp-json/limpvix/v1/health/cron

# Retorna exit code 0 se healthy (HTTP 200)
# Retorna exit code 22 se degraded (HTTP 503)
```

**Exemplo de script para Zabbix UserParameter:**
```bash
#!/bin/bash
# limpvix_cron_health.sh

ENDPOINT="https://seudominio.com.br/wp-json/limpvix/v1/health/cron"
RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" "$ENDPOINT")

if [ "$RESPONSE" -eq 200 ]; then
    echo 1  # Healthy
else
    echo 0  # Degraded or Down
fi
```

**Zabbix Item Configuration:**
- Type: External check
- Key: `limpvix.cron.health`
- Type of information: Numeric (unsigned)
- Trigger: `{Template:limpvix.cron.health.last()}=0`

---

## 📊 Dashboard Admin Widget

### Acesso
1. Login no WordPress Admin
2. Ir para Dashboard principal (`/wp-admin/`)
3. Widget "🔧 LimpVix - Cron Jobs Status" aparece automaticamente

### Informações Exibidas
- **Job Name:** Nome amigável do cron job
- **Health Badge:** Status visual (healthy/warning/critical)
- **Última Execução:** Data/hora da última execução
- **Age:** Tempo decorrido desde última execução
- **Status:** success/failure/timeout
- **Duração:** Tempo de execução em segundos
- **Erro:** Mensagem de erro (se houver)

### Ações Disponíveis
- **Ver Health Check JSON:** Link para endpoint REST (debug)
- **Alertas:** Se há cron jobs críticos, exibe mensagem de atenção

---

## 🔍 Troubleshooting

### Cron Job Não Está Executando

**1. Verificar se WP Cron está ativo:**
```bash
wp cron event list
```

**Saída esperada:**
```
hook                               next_run_gmt          next_run_relative
limpvix_check_contract_expiration  2026-02-11 00:00:00   in 12 hours
```

**2. Se não aparece, agendar manualmente:**
```bash
wp cron event schedule limpvix_check_contract_expiration now daily
```

**3. Executar manualmente (teste):**
```bash
wp cron event run limpvix_check_contract_expiration
```

**4. Verificar logs:**
```bash
# Se WP_DEBUG ativo, ver error_log
tail -f /var/log/wordpress/debug.log | grep -i "cron"
```

---

### Cron Job Executando Mas Health Check Retorna "Unknown"

**Causa:** CronMonitor não está registrando execuções

**Solução:**
1. Verificar se `ContractBootstrap::onCheckContractExpiration()` está usando CronMonitor
2. Verificar logs:
```bash
tail -f /var/log/wordpress/debug.log | grep -i "CronMonitor"
```

**Saída esperada:**
```
[CronMonitor] ℹ️ Cron job 'check_contract_expiration' started
[CronMonitor] ✅ Cron job 'check_contract_expiration' completed: success (duration: 1.23s)
```

---

### Health Check Retorna HTTP 503 Mas Cron Está OK

**Causa:** Max age threshold muito baixo

**Solução:**
1. Ajustar threshold em `HealthController.php`:
```php
private const MONITORED_JOBS = [
    'check_contract_expiration' => 25, // Aumentar para 48 horas
];
```

2. Ou adicionar grace period no monitoring externo (UptimeRobot: 5 minutos de downtime antes de alertar)

---

### Widget Não Aparece no Dashboard

**Causa:** Widget não registrado ou permissões incorretas

**Solução:**
1. Verificar se `ContractBootstrap::registerAdminWidgets()` foi chamado:
```bash
wp eval "var_dump(class_exists('LimpVix\\Infrastructure\\Admin\\Widgets\\CronHealthWidget'));"
```

2. Limpar cache do WordPress:
```bash
wp cache flush
```

3. Verificar permissões do usuário (precisa ser Administrator)

---

### WP Cron Não Executa em Produção

**Causa:** Servidor desabilita WP Cron (comum em hosts compartilhados)

**Solução:**
1. Desabilitar WP Cron interno no `wp-config.php`:
```php
define('DISABLE_WP_CRON', true);
```

2. Configurar system cron (server-level):
```bash
# Editar crontab
crontab -e

# Adicionar linha (executa a cada 5 minutos):
*/5 * * * * curl https://seudominio.com.br/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

3. Ou usar WP-CLI:
```bash
*/5 * * * * cd /var/www/html && wp cron event run --due-now > /dev/null 2>&1
```

---

## 🧪 Testes de Validação

### Teste 1: Verificar Endpoint Está Acessível
```bash
curl -i https://seudominio.com.br/wp-json/limpvix/v1/health/cron
```

**Output esperado:**
```
HTTP/2 200
content-type: application/json

{"status":"healthy","timestamp":"2026-02-10 12:34:56",...}
```

### Teste 2: Simular Cron Job Failure
```php
// Em wp-admin ou wp shell
wp eval '
$monitor = new \LimpVix\Application\Services\CronMonitor();
$monitor->recordStart("check_contract_expiration");
$monitor->recordEnd("check_contract_expiration", "failure", "Simulated error for testing");
'

// Agora verificar endpoint
curl https://seudominio.com.br/wp-json/limpvix/v1/health/cron
```

**Output esperado:** HTTP 200 mas status "warning"

### Teste 3: Simular Cron Job Timeout (Não Completou)
```php
wp eval '
$monitor = new \LimpVix\Application\Services\CronMonitor();
$monitor->recordStart("check_contract_expiration");
// Não chamar recordEnd() - simula timeout
'

// Aguardar 1 minuto e verificar endpoint
sleep 60
curl https://seudominio.com.br/wp-json/limpvix/v1/health/cron
```

**Output esperado:** health = "critical", status = "timeout"

### Teste 4: Simular Cron Não Executou Há Muito Tempo
```php
// Deletar dados de execução
wp eval '
delete_option("limpvix_cron_last_run_check_contract_expiration");
'

// Verificar endpoint
curl https://seudominio.com.br/wp-json/limpvix/v1/health/cron
```

**Output esperado:** HTTP 503, health = "unknown", message = "Never executed"

---

## 📈 Métricas e KPIs

### O Que Monitorar

**1. Uptime do Endpoint:**
- Target: **99.9%** (excluindo manutenções planejadas)
- Alert: Se down por > 5 minutos

**2. Cron Job Execution Frequency:**
- Target: **Daily** (check_contract_expiration)
- Alert: Se não executar por > 25 horas

**3. Cron Job Success Rate:**
- Target: **100%** success (nenhum failure)
- Alert: Se > 2 failures consecutivos

**4. Cron Job Duration:**
- Baseline: ~1-2 segundos (com 10 contratos)
- Warning: > 5 segundos (pode indicar problema de performance)
- Critical: > 30 segundos (timeout risk)

**5. Response Time do Health Endpoint:**
- Target: < 500ms
- Warning: > 1 segundo
- Critical: > 3 segundos

---

## 🔐 Segurança

### Endpoint Público - É Seguro?

**Sim, por design:**

1. **Não expõe dados sensíveis:**
   - Não mostra IDs de contratos
   - Não mostra dados de clientes/profissionais
   - Apenas status agregado dos cron jobs

2. **Informações expostas são genéricas:**
   - Nome do job (público)
   - Última execução (timestamp)
   - Status (success/failure/timeout)
   - Idade da última execução (hours)

3. **Não permite ações:**
   - Endpoint é **read-only** (GET)
   - Não permite POST/PUT/DELETE
   - Não permite executar cron jobs manualmente

4. **Rate Limiting (Future):**
   - Implementar via plugin (WP Limit Login Attempts)
   - Ou via server-level (Nginx limit_req)

### Recomendações Adicionais

1. **Usar HTTPS:** Sempre use SSL/TLS em produção
2. **Firewall:** Não necessário, mas pode restringir por IP (se monitoring privado)
3. **Logs:** Monitorar access logs para abusos (muitos requests/segundo)

---

## 🚦 Próximos Passos (Future Enhancements)

### Sprint 8+:
1. **Alertas por Email:** Enviar email para admins se cron crítico
2. **Slack Integration:** Webhook para canal #limpvix-ops
3. **Múltiplos Cron Jobs:** Adicionar outros jobs ao monitoring
4. **Historical Data:** Guardar histórico de execuções (últimas 30 dias)
5. **Performance Metrics:** Gráficos de duração ao longo do tempo
6. **Auto-Recovery:** Tentar re-executar cron se falhar

---

## 📚 Referências

- **WordPress Cron Documentation:** https://developer.wordpress.org/plugins/cron/
- **REST API Handbook:** https://developer.wordpress.org/rest-api/
- **UptimeRobot API:** https://uptimerobot.com/api/
- **Healthchecks.io Documentation:** https://healthchecks.io/docs/

---

## ✅ Checklist de Go-Live

Antes de ir para produção, verificar:

- [ ] Health check endpoint acessível publicamente
- [ ] Monitoring externo configurado (UptimeRobot/Pingdom/outro)
- [ ] Alertas configurados (email/SMS/Slack)
- [ ] Widget aparece no dashboard admin
- [ ] WP Cron executando corretamente (ou system cron configurado)
- [ ] Testes de validação passando (4 testes acima)
- [ ] SSL/HTTPS ativo
- [ ] Logs de debug desativados em produção (WP_DEBUG = false)

---

**Fim do Guia de Configuração**

**SPRINT 7 - Item 1.9 Completo ✅**
**Data:** 2026-02-10
**Autor:** Claude Code + LimpVix Development Team
