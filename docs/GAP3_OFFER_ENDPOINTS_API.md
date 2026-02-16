# GAP #3 - Offer Management REST API Documentation

**Data:** 2026-02-13
**Sprint:** Sprint 8
**Status:** ✅ **IMPLEMENTADO**

---

## 📋 Visão Geral

Esta documentação descreve os **6 endpoints REST** implementados no `OfferController` para gerenciamento completo do ciclo de vida de offers (propostas de trabalho) no marketplace LimpVix.

### Endpoints Implementados

1. **POST** `/limpvix/v1/contracts/{id}/send-offers` - Enviar offers para profissionais
2. **GET** `/limpvix/v1/contracts/{id}/offers` - Listar offers de um contrato
3. **GET** `/limpvix/v1/offers/{id}` - Detalhes de uma offer específica
4. **POST** `/limpvix/v1/offers/{id}/accept` - Aceitar offer (Professional)
5. **POST** `/limpvix/v1/offers/{id}/reject` - Rejeitar offer (Professional)
6. **GET** `/limpvix/v1/professionals/{id}/offers` - Listar offers do profissional

### Autenticação

Todos os endpoints requerem autenticação WordPress (`is_user_logged_in()`) ou JWT token.

**Headers Obrigatórios:**
```http
Authorization: Bearer {jwt_token}
Content-Type: application/json
X-WP-Nonce: {wp_rest_nonce}
```

---

## 1. Enviar Offers para Profissionais

### `POST /limpvix/v1/contracts/{id}/send-offers`

**Descrição:** Executa o algoritmo de matching e envia offers para os profissionais mais qualificados.

**Permissões:**
- ✅ Admin (manage_options)
- ✅ Customer (owner do contrato)
- ❌ Professional (não pode enviar offers)

**Parâmetros URL:**
- `id` (integer, required): ID do contrato

**Body Parameters:**
```json
{
  "offer_count": 10
}
```

| Campo | Tipo | Obrigatório | Default | Validação | Descrição |
|-------|------|-------------|---------|-----------|-----------|
| offer_count | integer | Não | 10 | min: 1, max: 50 | Número de offers a enviar |

**Exemplo de Request:**
```bash
curl -X POST \
  'https://limpvix.com/wp-json/limpvix/v1/contracts/123/send-offers' \
  -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...' \
  -H 'Content-Type: application/json' \
  -d '{
    "offer_count": 15
  }'
```

**Resposta de Sucesso (200 OK):**
```json
{
  "success": true,
  "data": {
    "contract_id": 123,
    "offers_sent": 15,
    "professionals_notified": 15,
    "offers": [
      {
        "id": 456,
        "professional_id": 78,
        "contract_id": 123,
        "amount": 150.00,
        "status": "pending",
        "expires_at": "2026-02-14 15:30:00",
        "created_at": "2026-02-13 15:30:00"
      }
      // ... mais 14 offers
    ]
  },
  "message": "Successfully sent 15 offers to professionals"
}
```

**Erros Comuns:**

| Status Code | Código de Erro | Mensagem | Causa |
|-------------|----------------|----------|-------|
| 400 | business_rule_violation | Contract already has allocated professional | Contrato já tem profissional alocado |
| 400 | business_rule_violation | Contract status must be active | Contrato não está ativo |
| 403 | forbidden | You don't have permission | User não é admin nem owner |
| 404 | not_found | Contract not found | ID do contrato inválido |
| 500 | internal_error | SendOffers use case not available | Use case não registrado (config error) |

---

## 2. Listar Offers de um Contrato

### `GET /limpvix/v1/contracts/{id}/offers`

**Descrição:** Lista todas as offers enviadas para um contrato específico.

**Permissões:**
- ✅ Admin
- ✅ Customer (owner do contrato)

**Parâmetros URL:**
- `id` (integer, required): ID do contrato

**Query Parameters:**
```
?status=pending&limit=20&offset=0
```

| Parâmetro | Tipo | Obrigatório | Default | Validação | Descrição |
|-----------|------|-------------|---------|-----------|-----------|
| status | string | Não | - | enum: pending, accepted, rejected, expired | Filtrar por status |

**Exemplo de Request:**
```bash
curl -X GET \
  'https://limpvix.com/wp-json/limpvix/v1/contracts/123/offers?status=pending' \
  -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...'
```

**Resposta de Sucesso (200 OK):**
```json
{
  "success": true,
  "data": {
    "contract_id": 123,
    "total": 15,
    "offers": [
      {
        "id": 456,
        "professional_id": 78,
        "professional_name": "João Silva",
        "contract_id": 123,
        "amount": 150.00,
        "status": "pending",
        "expires_at": "2026-02-14 15:30:00",
        "created_at": "2026-02-13 15:30:00",
        "updated_at": "2026-02-13 15:30:00"
      },
      {
        "id": 457,
        "professional_id": 82,
        "professional_name": "Maria Santos",
        "contract_id": 123,
        "amount": 150.00,
        "status": "rejected",
        "rejection_reason": "Agenda lotada",
        "expires_at": "2026-02-14 15:30:00",
        "created_at": "2026-02-13 15:30:00",
        "updated_at": "2026-02-13 16:45:00"
      }
    ]
  }
}
```

**Erros Comuns:**

| Status Code | Mensagem |
|-------------|----------|
| 403 | You don't have permission to view this contract |
| 404 | Contract not found |

---

## 3. Detalhes de uma Offer

### `GET /limpvix/v1/offers/{id}`

**Descrição:** Retorna detalhes completos de uma offer específica.

**Permissões:**
- ✅ Admin
- ✅ Customer (owner do contrato)
- ✅ Professional (owner da offer)

**Parâmetros URL:**
- `id` (integer, required): ID da offer

**Exemplo de Request:**
```bash
curl -X GET \
  'https://limpvix.com/wp-json/limpvix/v1/offers/456' \
  -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...'
```

**Resposta de Sucesso (200 OK):**
```json
{
  "success": true,
  "data": {
    "id": 456,
    "professional_id": 78,
    "contract_id": 123,
    "amount": 150.00,
    "status": "pending",
    "expires_at": "2026-02-14 15:30:00",
    "accepted_at": null,
    "rejected_at": null,
    "rejection_reason": null,
    "created_at": "2026-02-13 15:30:00",
    "updated_at": "2026-02-13 15:30:00",
    "contract": {
      "id": 123,
      "client_name": "Empresa ABC",
      "service_type": "Limpeza Comercial",
      "address": "Av. Paulista, 1000 - São Paulo/SP",
      "start_date": "2026-02-20",
      "frequency": "weekly"
    },
    "professional": {
      "id": 78,
      "name": "João Silva",
      "score": 4.8,
      "completed_services": 142,
      "acceptance_rate": 0.92
    }
  }
}
```

**Erros Comuns:**

| Status Code | Mensagem |
|-------------|----------|
| 403 | You don't have permission to view this offer |
| 404 | Offer not found |

---

## 4. Aceitar Offer (Professional)

### `POST /limpvix/v1/offers/{id}/accept`

**Descrição:** Professional aceita uma offer. Primeira offer aceita expira todas as outras e aloca o profissional ao contrato.

**Permissões:**
- ✅ Professional (owner da offer)
- ❌ Admin (não pode aceitar em nome do profissional)
- ❌ Customer

**Parâmetros URL:**
- `id` (integer, required): ID da offer

**Exemplo de Request:**
```bash
curl -X POST \
  'https://limpvix.com/wp-json/limpvix/v1/offers/456/accept' \
  -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...' \
  -H 'Content-Type: application/json'
```

**Resposta de Sucesso (200 OK):**
```json
{
  "success": true,
  "data": {
    "offer_id": 456,
    "contract_id": 123,
    "professional_id": 78,
    "status": "accepted",
    "accepted_at": "2026-02-13 16:30:00",
    "contract_status": "active",
    "allocated_professional_id": 78
  },
  "message": "Offer accepted successfully"
}
```

**Erros Comuns:**

| Status Code | Código | Mensagem | Causa |
|-------------|--------|----------|-------|
| 400 | invalid_status | Oferta não está mais disponível (status: accepted) | Offer já foi aceita |
| 400 | expired | Oferta expirada | Offer passou da data de expiração |
| 400 | contract_allocated | Contrato já possui profissional alocado | Outra offer foi aceita primeiro (race condition) |
| 403 | forbidden | Professional not found | User atual não é professional |
| 403 | forbidden | This offer belongs to another professional | Tentando aceitar offer de outro profissional |
| 404 | not_found | Offer not found | ID da offer inválido |

**Regras de Negócio:**

1. **First-to-Accept:** A primeira offer aceita vence. Todas as outras são expiradas automaticamente.
2. **Transação:** Aceitação, expiração de outras offers e alocação ao contrato acontecem em transação.
3. **Status da Offer:** `pending` → `accepted`
4. **Status do Contract:** `active` (se não estava já)
5. **Allocated Professional:** Contract recebe `allocated_professional_id` = professional ID

---

## 5. Rejeitar Offer (Professional)

### `POST /limpvix/v1/offers/{id}/reject`

**Descrição:** Professional rejeita uma offer com motivo opcional.

**Permissões:**
- ✅ Professional (owner da offer)

**Parâmetros URL:**
- `id` (integer, required): ID da offer

**Body Parameters:**
```json
{
  "reason": "Agenda lotada"
}
```

| Campo | Tipo | Obrigatório | Validação | Descrição |
|-------|------|-------------|-----------|-----------|
| reason | string | Não | max: 500 chars | Motivo da rejeição |

**Exemplo de Request:**
```bash
curl -X POST \
  'https://limpvix.com/wp-json/limpvix/v1/offers/456/reject' \
  -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...' \
  -H 'Content-Type: application/json' \
  -d '{
    "reason": "Não tenho disponibilidade neste horário"
  }'
```

**Resposta de Sucesso (200 OK):**
```json
{
  "success": true,
  "data": {
    "offer_id": 456,
    "status": "rejected",
    "rejected_at": "2026-02-13 16:45:00",
    "rejection_reason": "Não tenho disponibilidade neste horário"
  },
  "message": "Offer rejected successfully"
}
```

**Erros Comuns:**

| Status Code | Mensagem | Causa |
|-------------|----------|-------|
| 400 | Oferta não está mais disponível | Offer já foi aceita/rejeitada |
| 403 | Professional not found | User não é professional |
| 404 | Offer not found | ID inválido |

**Regras de Negócio:**

1. **Status da Offer:** `pending` → `rejected`
2. **Rejection Reason:** Armazenado para analytics
3. **Contract:** Não é afetado (outras offers ainda podem ser aceitas)
4. **Acceptance Rate:** Professional pode ter acceptance rate reduzida se rejeitar muitas offers

---

## 6. Listar Offers do Professional

### `GET /limpvix/v1/professionals/{id}/offers`

**Descrição:** Lista todas as offers recebidas por um profissional.

**Permissões:**
- ✅ Admin
- ✅ Professional (apenas suas próprias offers)

**Parâmetros URL:**
- `id` (integer, required): ID do profissional

**Query Parameters:**
```
?status=pending&limit=20&offset=0
```

| Parâmetro | Tipo | Obrigatório | Default | Validação | Descrição |
|-----------|------|-------------|---------|-----------|-----------|
| status | string | Não | - | enum: pending, accepted, rejected, expired | Filtrar por status |
| limit | integer | Não | 20 | min: 1, max: 100 | Número de resultados |

**Exemplo de Request:**
```bash
curl -X GET \
  'https://limpvix.com/wp-json/limpvix/v1/professionals/78/offers?status=pending&limit=10' \
  -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...'
```

**Resposta de Sucesso (200 OK):**
```json
{
  "success": true,
  "data": {
    "professional_id": 78,
    "total": 5,
    "offers": [
      {
        "id": 456,
        "contract_id": 123,
        "amount": 150.00,
        "status": "pending",
        "expires_at": "2026-02-14 15:30:00",
        "created_at": "2026-02-13 15:30:00",
        "contract": {
          "client_name": "Empresa ABC",
          "service_type": "Limpeza Comercial",
          "address": "Av. Paulista, 1000 - São Paulo/SP",
          "start_date": "2026-02-20",
          "frequency": "weekly"
        }
      },
      {
        "id": 458,
        "contract_id": 125,
        "amount": 200.00,
        "status": "pending",
        "expires_at": "2026-02-14 18:00:00",
        "created_at": "2026-02-13 18:00:00",
        "contract": {
          "client_name": "Condomínio XYZ",
          "service_type": "Limpeza Residencial",
          "address": "Rua das Flores, 50 - São Paulo/SP",
          "start_date": "2026-02-21",
          "frequency": "monthly"
        }
      }
    ]
  }
}
```

**Erros Comuns:**

| Status Code | Mensagem |
|-------------|----------|
| 403 | You don't have permission to view this professional's offers |
| 404 | Professional not found |

---

## 📊 Database Schema - wp_limpvix_contract_offers

```sql
CREATE TABLE wp_limpvix_contract_offers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    professional_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    rejected_at DATETIME NULL,
    rejection_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX idx_contract_id (contract_id),
    INDEX idx_professional_id (professional_id),
    INDEX idx_status (status),
    INDEX idx_expires_at (expires_at),

    FOREIGN KEY (contract_id) REFERENCES wp_limpvix_contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (professional_id) REFERENCES wp_limpvix_professionals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
```

**Campos:**

- `id`: Primary Key
- `contract_id`: Contrato para o qual a offer foi enviada
- `professional_id`: Profissional que recebeu a offer
- `amount`: Valor da proposta (pode ser diferente do valor do contrato)
- `status`: `pending`, `accepted`, `rejected`, `expired`
- `expires_at`: Data/hora de expiração (tipicamente 24-48h após criação)
- `accepted_at`: Timestamp de aceitação (se aceita)
- `rejected_at`: Timestamp de rejeição (se rejeitada)
- `rejection_reason`: Motivo da rejeição (opcional)
- `created_at`: Data de criação
- `updated_at`: Data de última atualização

---

## 🔒 Authorization & Security

### Permission Callbacks

| Endpoint | Permission Callback | Lógica |
|----------|---------------------|--------|
| `/contracts/{id}/send-offers` | `canManageContract()` | Admin OU customer owner |
| `/contracts/{id}/offers` | `canViewContract()` | Admin OU customer owner |
| `/offers/{id}` | `canViewOffer()` | Admin OU customer owner OU professional owner |
| `/offers/{id}/accept` | `canRespondToOffer()` | Professional owner |
| `/offers/{id}/reject` | `canRespondToOffer()` | Professional owner |
| `/professionals/{id}/offers` | `canViewProfessionalOffers()` | Admin OU professional owner |

### Exemplo de Authorization Check:

```php
public function canViewOffer(WP_REST_Request $request): bool
{
    // Admin sempre pode ver
    if (current_user_can('manage_options')) return true;

    $offerId = (int) $request['id'];
    $currentUserId = get_current_user_id();

    // Buscar offer
    global $wpdb;
    $table = $wpdb->prefix . 'limpvix_contract_offers';
    $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $offerId), ARRAY_A);

    if (!$offer) return false;

    // Professional owner pode ver
    $professionalId = $this->getProfessionalIdFromUser($currentUserId);
    if ($professionalId && $offer['professional_id'] == $professionalId) return true;

    // Customer owner do contrato pode ver
    if ($this->contractRepository) {
        $contract = $this->contractRepository->findById($offer['contract_id']);
        if ($contract && $contract->getClientUserId() === $currentUserId) return true;
    }

    return false;
}
```

---

## 🧪 Testes de Endpoints

### Teste 1: Enviar Offers (Admin)

```bash
# Login como admin
TOKEN=$(curl -X POST https://limpvix.com/wp-json/jwt-auth/v1/token \
  -d '{"username":"admin","password":"admin"}' | jq -r '.token')

# Enviar 10 offers para contrato #123
curl -X POST \
  'https://limpvix.com/wp-json/limpvix/v1/contracts/123/send-offers' \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"offer_count": 10}'
```

**Resultado Esperado:**
- Status: 200 OK
- `success`: true
- `data.offers_sent`: 10
- 10 offers criadas na tabela `wp_limpvix_contract_offers`

### Teste 2: Listar Offers Pendentes (Professional)

```bash
# Login como professional (ID 78)
TOKEN=$(curl -X POST https://limpvix.com/wp-json/jwt-auth/v1/token \
  -d '{"username":"professional78","password":"senha"}' | jq -r '.token')

# Listar minhas offers pendentes
curl -X GET \
  'https://limpvix.com/wp-json/limpvix/v1/professionals/78/offers?status=pending' \
  -H "Authorization: Bearer $TOKEN"
```

**Resultado Esperado:**
- Status: 200 OK
- Lista de offers com `status = 'pending'`
- Apenas offers do professional #78

### Teste 3: Aceitar Offer (Professional)

```bash
# Aceitar offer #456
curl -X POST \
  'https://limpvix.com/wp-json/limpvix/v1/offers/456/accept' \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json'
```

**Resultado Esperado:**
- Status: 200 OK
- Offer #456: `status = 'accepted'`, `accepted_at` preenchido
- Contrato #123: `allocated_professional_id = 78`
- Outras offers do contrato #123: `status = 'expired'`

### Teste 4: Rejeitar Offer (Professional)

```bash
# Rejeitar offer #458 com motivo
curl -X POST \
  'https://limpvix.com/wp-json/limpvix/v1/offers/458/reject' \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"reason": "Agenda lotada neste período"}'
```

**Resultado Esperado:**
- Status: 200 OK
- Offer #458: `status = 'rejected'`, `rejection_reason` preenchido

### Teste 5: Race Condition - Duas Offers Aceitas Simultâneas

```bash
# Simular 2 profissionais aceitando ao mesmo tempo
curl -X POST 'https://limpvix.com/wp-json/limpvix/v1/offers/456/accept' \
  -H "Authorization: Bearer $TOKEN_PROF_78" &
curl -X POST 'https://limpvix.com/wp-json/limpvix/v1/offers/457/accept' \
  -H "Authorization: Bearer $TOKEN_PROF_82" &
wait
```

**Resultado Esperado:**
- Primeira request: Status 200 OK (venceu)
- Segunda request: Status 400 Bad Request (contrato já alocado)
- Apenas UMA offer aceita, outras expiradas

---

## 📝 Notas de Implementação

### Fallback Pattern

O OfferController usa **fallback pattern** para instanciar use cases caso não estejam registrados no container global:

```php
$sendOffersUseCase = $this->useCases['send_offers'] ?? $this->createSendOffersUseCase();

if (!$sendOffersUseCase) {
    return new WP_REST_Response(['success' => false, 'message' => 'SendOffers use case not available'], 500);
}
```

**Ordem de Resolução:**
1. Tentar usar use case do container global (`$GLOBALS['limpvix_contract_use_cases']['send_offers']`)
2. Se não encontrar, instanciar manualmente usando `createSendOffersUseCase()`
3. Se falhar (repositórios não disponíveis), retornar erro 500

### Performance Considerations

**Paginação:**
- Endpoint `/professionals/{id}/offers` suporta `limit` e `offset`
- Default: 20 results per page
- Maximum: 100 results per page

**Índices de Database:**
- `idx_contract_id`: Performance para `/contracts/{id}/offers`
- `idx_professional_id`: Performance para `/professionals/{id}/offers`
- `idx_status`: Performance para filtros `?status=pending`
- `idx_expires_at`: Performance para cron job que expira offers

### Idempotência

**Enviar Offers:**
- Idempotente: Se executar `send-offers` múltiplas vezes, não cria offers duplicadas
- Validação: Verifica se já existem offers `pending` para o contrato antes de criar novas

**Aceitar Offer:**
- Não idempotente: Segunda aceitação retorna erro 400 (status != pending)
- Transação garante que apenas UMA offer seja aceita (race condition protegida)

---

## ✅ Conclusão

**Status:** ✅ **GAP #3 Endpoints REST - COMPLETO**

**Implementado:**
- ✅ 6 endpoints REST funcionais
- ✅ Authorization completa (admin, customer, professional)
- ✅ Fallback pattern para use cases
- ✅ Error handling robusto
- ✅ Race condition protection (first-to-accept)
- ✅ Documentação OpenAPI completa

**Próximos Passos:**
1. ✅ Testar endpoints com Postman
2. ✅ Commit final Sprint 8
3. ➡️ Sprint 9: OTP Verification + OAuth Token Refresh

---

**Documentado por:** Claude Sonnet 4.5
**Data:** 2026-02-13
**Versão:** 1.0 - Sprint 8 Final
