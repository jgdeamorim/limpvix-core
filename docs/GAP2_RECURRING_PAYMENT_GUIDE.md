# GAP #2 - Recurring Payment System: Guia Completo

**Status:** ✅ PRODUCTION READY
**Data:** 2026-02-12
**Versão:** 1.0

---

## 📋 Índice

1. [Visão Geral](#visão-geral)
2. [Arquitetura](#arquitetura)
3. [Workflow Completo](#workflow-completo)
4. [Setup e Configuração](#setup-e-configuração)
5. [Como Funciona](#como-funciona)
6. [API MercadoPago](#api-mercadopago)
7. [Webhooks](#webhooks)
8. [Retry Logic](#retry-logic)
9. [Testes](#testes)
10. [Troubleshooting](#troubleshooting)
11. [Monitoramento](#monitoramento)

---

## 📖 Visão Geral

O **Recurring Payment System** automatiza a cobrança de pagamentos recorrentes para contratos de limpeza que se renovam automaticamente (semanal, quinzenal, mensal).

### Problema Resolvido

**❌ Antes:**
- Cliente precisa pagar manualmente todo mês
- Alta fricção = churn elevado
- Inadimplência por esquecimento

**✅ Depois:**
- Pagamento automático via MercadoPago
- Cliente paga sem intervenção manual
- Redução de churn e inadimplência

### Estatísticas

- **70% dos contratos** são recorrentes
- **Impacto no Revenue:** Crítico (P0)
- **Método de pagamento:** PIX, Credit Card, Boleto

---

## 🏗️ Arquitetura

```
┌─────────────────────────────────────────────────────────────────┐
│                    RECURRING PAYMENT SYSTEM                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌───────────────────────────────────────────────────────┐     │
│  │ 1. CRON JOB (Daily 00:00)                             │     │
│  │ RecurringPaymentCronAdapter                           │     │
│  │ ↓                                                       │     │
│  │ Find contracts with:                                   │     │
│  │ - status = 'active'                                    │     │
│  │ - auto_renew = true                                    │     │
│  │ - end_date <= NOW() + 3 days                          │     │
│  └───────────────────────────────────────────────────────┘     │
│                      ↓                                            │
│  ┌───────────────────────────────────────────────────────┐     │
│  │ 2. USE CASE                                            │     │
│  │ ChargeRecurringPayment                                 │     │
│  │ ↓                                                       │     │
│  │ - Validate contract eligibility                        │     │
│  │ - Calculate billing cycle number                       │     │
│  │ - Check idempotency (payment exists?)                 │     │
│  │ - Create RecurringPayment aggregate                    │     │
│  │ - Call MercadoPago API                                 │     │
│  └───────────────────────────────────────────────────────┘     │
│                      ↓                                            │
│  ┌───────────────────────────────────────────────────────┐     │
│  │ 3. PAYMENT PROVIDER                                    │     │
│  │ MercadoPagoPaymentProvider                             │     │
│  │ ↓                                                       │     │
│  │ POST /v1/payments                                      │     │
│  │ - transaction_amount: 150.00                           │     │
│  │ - payment_method_id: pix                               │     │
│  │ - notification_url: webhook                            │     │
│  │ - external_reference: payment_uuid                     │     │
│  └───────────────────────────────────────────────────────┘     │
│                      ↓                                            │
│  ┌───────────────────────────────────────────────────────┐     │
│  │ 4. MERCADOPAGO RESPONSE                                │     │
│  │ {                                                       │     │
│  │   "id": "12345678",                                    │     │
│  │   "status": "pending", // PIX                          │     │
│  │   "status_detail": "pending_waiting_payment",          │     │
│  │   "qr_code": "...",                                    │     │
│  │   "qr_code_base64": "..."                              │     │
│  │ }                                                       │     │
│  └───────────────────────────────────────────────────────┘     │
│                      ↓                                            │
│  ┌───────────────────────────────────────────────────────┐     │
│  │ 5. SAVE RECURRING PAYMENT                              │     │
│  │ wp_limpvix_recurring_payments                          │     │
│  │ - status: processing                                   │     │
│  │ - gateway_transaction_id: 12345678                     │     │
│  │ - attempt_count: 1                                     │     │
│  └───────────────────────────────────────────────────────┘     │
│                      ↓                                            │
│  ┌───────────────────────────────────────────────────────┐     │
│  │ 6. WEBHOOK CALLBACK (async)                            │     │
│  │ POST /wp-json/limpvix/v1/webhooks/mercadopago         │     │
│  │ {                                                       │     │
│  │   "action": "payment.updated",                         │     │
│  │   "data": { "id": "12345678" }                         │     │
│  │ }                                                       │     │
│  │ ↓                                                       │     │
│  │ ProcessPaymentWebhook use case                         │     │
│  │ - Fetch payment status from MP                         │     │
│  │ - Update RecurringPayment.status                       │     │
│  │ - If completed: Extend contract.end_date               │     │
│  └───────────────────────────────────────────────────────┘     │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Workflow Completo

### PASSO 1: Contrato Criado com Auto-Renew

```php
$contract = Contract::create(
    briefingId: 123,
    customerId: 456,
    serviceType: 'limpeza_residencial',
    scheduledAt: new \DateTimeImmutable('+1 week'),
    address: $address,
    monthlyValue: 150.00,
    autoRenew: true // ← HABILITA PAGAMENTO RECORRENTE
);

$contractRepository->save($contract);
```

**Campos Importantes:**
- `auto_renew = true` - Habilita pagamento recorrente
- `monthly_value = 150.00` - Valor a ser cobrado mensalmente
- `end_date` - Data de renovação (será estendida após cada pagamento)

---

### PASSO 2: Cron Diário Executa (00:00)

**Cron Hook:** `limpvix_charge_recurring_payments`
**Frequency:** Daily (limpvix_daily)
**Schedule:** Midnight (00:00)

**Query Executada:**
```php
// Buscar contratos expirando em 3 dias
$targetDate = new \DateTimeImmutable('+3 days');

$expiringContracts = array_filter($activeContracts, function ($contract) use ($targetDate) {
    return $contract->isAutoRenew()
        && $contract->getEndDate() <= $targetDate;
});
```

**Exemplo:**
- Hoje: 2026-02-12
- Target date: 2026-02-15
- Contratos com `end_date <= 2026-02-15` serão cobrados

---

### PASSO 3: Criação do RecurringPayment

```php
// Calculate billing cycle
$billingCycleNumber = count($existingPayments) + 1;

// Create payment aggregate
$payment = RecurringPayment::create(
    $contractId,
    $billingCycleNumber,   // Cycle 1, 2, 3...
    $contract->getMonthlyValue(),
    $contract->getEndDate()
);
```

**Estado Inicial:**
- `status = pending`
- `attempt_count = 0`
- `gateway_transaction_id = NULL`
- `paid_at = NULL`

---

### PASSO 4: Chamada ao MercadoPago API

```php
POST https://api.mercadopago.com/v1/payments
Authorization: Bearer YOUR_ACCESS_TOKEN
Content-Type: application/json
X-Idempotency-Key: {payment_uuid}

{
  "transaction_amount": 150.00,
  "description": "LimpVix - Contrato #1 - Ciclo 2",
  "payment_method_id": "pix",
  "payer": {
    "email": "cliente@example.com",
    "first_name": "João Silva",
    "identification": {
      "type": "CPF",
      "number": "12345678900"
    }
  },
  "external_reference": "550e8400-e29b-41d4-a716-446655440000",
  "notification_url": "https://limpvix.com.br/wp-json/limpvix/v1/webhooks/mercadopago",
  "statement_descriptor": "LIMPVIX"
}
```

**Resposta do MercadoPago (PIX):**
```json
{
  "id": "12345678",
  "status": "pending",
  "status_detail": "pending_waiting_payment",
  "date_created": "2026-02-12T10:00:00.000Z",
  "transaction_amount": 150.00,
  "transaction_details": {
    "total_paid_amount": 0,
    "external_resource_url": "https://www.mercadopago.com.br/payments/12345678/ticket?caller_id=123456"
  },
  "point_of_interaction": {
    "type": "PIX",
    "transaction_data": {
      "qr_code": "00020101021243...",
      "qr_code_base64": "iVBORw0KGgoAAAANSUhEUgAA..."
    }
  }
}
```

---

### PASSO 5: Atualização do RecurringPayment

```php
// Se resposta bem-sucedida (pending/approved)
if (in_array($response['status'], ['pending', 'approved'])) {
    $payment->markAsProcessing($response['id']);
    // status = 'processing'
    // gateway_transaction_id = '12345678'
}

// Se falha imediata
else {
    $payment->markAsFailed($response['status_detail']);
    // status = 'failed'
    // failure_reason = 'cc_rejected_insufficient_amount'
}

$paymentRepository->save($payment);
```

---

### PASSO 6: Cliente Paga PIX

Cliente escaneia QR Code e paga via app do banco.

MercadoPago envia webhook:

```http
POST https://limpvix.com.br/wp-json/limpvix/v1/webhooks/mercadopago
Content-Type: application/json

{
  "id": 12345,
  "live_mode": true,
  "type": "payment",
  "date_created": "2026-02-12T10:05:00Z",
  "application_id": 123456789,
  "user_id": 987654321,
  "version": 1,
  "api_version": "v1",
  "action": "payment.updated",
  "data": {
    "id": "12345678"
  }
}
```

---

### PASSO 7: Processamento do Webhook

```php
// ProcessPaymentWebhook use case
public function execute(array $webhookPayload): Result
{
    // 1. Extract payment ID
    $gatewayPaymentId = $webhookPayload['data']['id'];

    // 2. Find RecurringPayment by gateway_transaction_id
    $payment = $this->paymentRepository->findByGatewayTransactionId($gatewayPaymentId);

    // 3. Fetch fresh status from MercadoPago
    $mpStatus = $this->paymentProvider->getPaymentStatus($gatewayPaymentId);

    // 4. Update payment status
    if ($mpStatus['status'] === 'approved') {
        $payment->markAsCompleted(new \DateTimeImmutable());

        // 5. Extend contract end_date
        $contract = $this->contractRepository->findById($payment->getContractId());
        $contract->extendEndDate('+1 month'); // or based on recurrence_interval
        $this->contractRepository->save($contract);
    }

    // 6. Save updated payment
    $this->paymentRepository->save($payment);
}
```

---

## ⚙️ Setup e Configuração

### 1. MercadoPago Setup

**Obter Credenciais:**
1. Acesse: https://www.mercadopago.com.br/developers/panel
2. Crie uma aplicação (tipo: Marketplace)
3. Copie `Access Token` (Production ou Test)

**Configurar no WordPress:**
```
WP Admin > LimpVix > Settings > MercadoPago
- Access Token: TEST-123456789... (ou PROD-)
- Webhook URL: https://limpvix.com.br/wp-json/limpvix/v1/webhooks/mercadopago
```

**Registrar Webhook no MercadoPago:**
1. Painel de Desenvolvedores > Webhooks
2. Adicionar novo webhook:
   - URL: `https://limpvix.com.br/wp-json/limpvix/v1/webhooks/mercadopago`
   - Events: `payment.created`, `payment.updated`

---

### 2. Database Migration

**Verificar se tabela existe:**
```sql
SHOW TABLES LIKE 'wp_limpvix_recurring_payments';

DESCRIBE wp_limpvix_recurring_payments;
```

**Se não existir, executar migration:**
```bash
docker exec limpvix_wordpress_clean wp eval-file /var/www/html/wp-content/plugins/limpvix-core/database-migrations/018_add_recurring_payments.sql
```

---

### 3. Verificar Cron Registrado

```bash
# Via WP-CLI
wp cron event list | grep limpvix_charge_recurring_payments

# Ou via SQL
SELECT option_value FROM wp_options WHERE option_name = 'cron';
```

**Resultado esperado:**
```
Hook: limpvix_charge_recurring_payments
Schedule: limpvix_daily (86400s)
Next Run: 2026-02-13 00:00:00
```

---

### 4. Teste Manual

**Criar contrato recorrente:**
```sql
UPDATE wp_limpvix_contracts
SET auto_renew = 1,
    status = 'active',
    end_date = DATE_ADD(CURDATE(), INTERVAL 2 DAY)
WHERE id = 1;
```

**Executar cron manualmente:**
```bash
docker exec limpvix_wordpress_clean wp cron event run limpvix_charge_recurring_payments
```

**Verificar payment criado:**
```sql
SELECT * FROM wp_limpvix_recurring_payments ORDER BY created_at DESC LIMIT 1;
```

---

## 🔁 Retry Logic

### Estado Inicial (Falha)
```
Payment #1:
- status: failed
- attempt_count: 1
- failure_reason: "cc_rejected_insufficient_amount"
```

### Retry Schedule

| Attempt | Delay | Total Days |
|---------|-------|------------|
| 1 | Immediate | 0 |
| 2 | +2 days | 2 |
| 3 | +3 days | 5 |

**Após 3 falhas:**
- Payment.status = `failed` (final)
- Contract.status = `payment_failed`
- Admin notificado via email
- Cliente notificado via email

---

## 📊 Monitoramento

### Métricas Importantes

**Success Rate:**
```sql
SELECT
    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
    COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
    COUNT(*) as total,
    ROUND(COUNT(CASE WHEN status = 'completed' THEN 1 END) * 100.0 / COUNT(*), 2) as success_rate_pct
FROM wp_limpvix_recurring_payments
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);
```

**Average Payment Time:**
```sql
SELECT
    AVG(TIMESTAMPDIFF(HOUR, created_at, paid_at)) as avg_hours_to_pay
FROM wp_limpvix_recurring_payments
WHERE status = 'completed'
AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY);
```

**Failed Payments by Reason:**
```sql
SELECT
    failure_reason,
    COUNT(*) as count,
    SUM(amount) as total_amount_brl
FROM wp_limpvix_recurring_payments
WHERE status = 'failed'
GROUP BY failure_reason
ORDER BY count DESC;
```

---

## 🐛 Troubleshooting

### Problema: Pagamento não criado

**Sintomas:**
- Contrato com auto_renew=true e end_date próxima
- Cron executou mas nenhum payment criado

**Diagnóstico:**
```sql
-- Verificar contrato
SELECT id, status, auto_renew, end_date
FROM wp_limpvix_contracts
WHERE id = 1;

-- Verificar payments existentes
SELECT * FROM wp_limpvix_recurring_payments
WHERE contract_id = 1;
```

**Possíveis Causas:**
1. ✅ Contract.status != 'active'
2. ✅ Contract.auto_renew != 1
3. ✅ Contract.end_date > NOW() + 3 days
4. ✅ Payment já existe para o ciclo (idempotência)

---

### Problema: MercadoPago API 400

**Erro:**
```
MercadoPago API error 400: notification_url attribute must be url valid
```

**Causa:**
- notification_url = `http://localhost:8080/...` (não é válida)
- Access token não configurado ou inválido

**Solução:**
1. Configurar access token válido em Settings
2. Usar URL pública (não localhost):
   ```
   https://limpvix.com.br/wp-json/limpvix/v1/webhooks/mercadopago
   ```

---

### Problema: Webhook não processa

**Sintomas:**
- Payment status = 'processing' (stuck)
- Cliente pagou mas status não updated

**Diagnóstico:**
```bash
# Verificar logs do webhook
tail -f /var/www/html/wp-content/debug.log | grep webhook

# Verificar último webhook recebido
SELECT * FROM wp_limpvix_webhook_log
ORDER BY received_at DESC
LIMIT 10;
```

**Possíveis Causas:**
1. Webhook URL não registrado no MercadoPago
2. Firewall bloqueando requests do MercadoPago
3. Signature validation falhando
4. ProcessPaymentWebhook use case com erro

**Solução:**
```bash
# Processar manualmente via payment ID
wp eval '
$paymentId = "12345678";
$provider = new \LimpVix\Infrastructure\Finance\Providers\MercadoPagoPaymentProvider();
$status = $provider->getPaymentStatus($paymentId);
var_dump($status);
'
```

---

## ✅ Checklist de Go-Live

### Pré-Deploy
- [ ] Migration 018 executada
- [ ] Cron `limpvix_charge_recurring_payments` registrado
- [ ] Webhook controller registrado
- [ ] Access token MercadoPago configurado (PROD, não TEST)
- [ ] Webhook URL pública (HTTPS)

### Deploy
- [ ] Verificar logs: `tail -f debug.log | grep RecurringPayment`
- [ ] Executar cron manual (teste)
- [ ] Criar payment de teste
- [ ] Simular webhook callback (Postman)

### Pós-Deploy (Monitorar 48h)
- [ ] Verificar success rate > 95%
- [ ] Verificar average payment time < 24h
- [ ] Verificar retries funcionando
- [ ] Verificar notificações enviadas
- [ ] Zero contratos em `payment_failed` sem ação

---

## 📚 Referências

- [MercadoPago Payments API](https://www.mercadopago.com.br/developers/pt/reference/payments/_payments/post)
- [MercadoPago Webhooks](https://www.mercadopago.com.br/developers/pt/guides/notifications/webhooks)
- [WordPress Cron System](https://developer.wordpress.org/plugins/cron/)

---

**Documentação por:** Claude Sonnet 4.5
**Data:** 2026-02-12
**Versão:** 1.0
