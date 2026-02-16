# LimpVix REST API - React Native Reference

**Versão:** 1.0.0  
**Data:** 2026-02-13  
**Base URL:** https://api.limpvix.com/wp-json/limpvix/v1

## Documentação Completa

Este é um guia de referência rápida. Para documentação detalhada com todos os schemas, validações e exemplos de código React Native, consulte o mapeamento completo realizado pelo agente Explore.

## Principais Endpoints

### Autenticação
- POST /auth/login - Login e obtenção de JWT tokens
- POST /auth/refresh - Renovar access token
- GET /auth/me - Dados do usuário autenticado

### Briefing (Solicitação)
- POST /briefing - Criar novo briefing
- GET /briefing/{uuid} - Buscar briefing
- POST /briefing/{uuid}/step - Atualizar step individual
- GET /briefing/schema - Schema dinâmico dos steps
- POST /briefing/{uuid}/verify-phone - Verificar telefone via Firebase
- POST /briefing/{uuid}/package - Selecionar pacote
- POST /briefing/{uuid}/additionals - Adicionar extras

### Profissional
- GET /professionals - Listar profissionais (Admin)
- POST /professionals - Registrar profissional (Admin)
- GET /professionals/{id} - Detalhes
- PATCH /professionals/{id} - Atualizar
- GET /professionals/{id}/offers - Listar ofertas
- POST /professionals/{id}/offers/{offer_id}/accept - Aceitar oferta
- POST /professionals/{id}/offers/{offer_id}/reject - Rejeitar oferta
- PATCH /professionals/{id}/availability - Atualizar disponibilidade

### Contratos
- GET /contracts - Listar contratos
- POST /contracts - Criar contrato
- POST /contracts/{id}/activate - Ativar
- POST /contracts/{id}/pause - Pausar
- POST /contracts/{id}/cancel - Cancelar

### Execução
- GET /executions - Listar execuções
- GET /executions/{id} - Detalhes
- POST /executions - Criar execução
- POST /executions/{id}/start - Iniciar
- POST /executions/{id}/complete - Completar
- POST /executions/{id}/cancel - Cancelar
- POST /executions/{id}/evidence - Adicionar foto/vídeo
- GET /executions/{id}/evidence - Listar evidências

### Utilitários
- GET /cep/{cep} - Consultar CEP (ViaCEP)
- GET /packages - Listar pacotes de serviço
- GET /services - Listar serviços disponíveis
- GET /additionals - Listar adicionais
- GET /health/cron - Health check

## Autenticação JWT em React Native

```typescript
import axios from "axios";
import AsyncStorage from "@react-native-async-storage/async-storage";

const api = axios.create({
  baseURL: "https://api.limpvix.com/wp-json/limpvix/v1"
});

api.interceptors.request.use(async (config) => {
  const token = await AsyncStorage.getItem("access_token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config;
    if (error.response?.status === 401 && !originalRequest._retry) {
      originalRequest._retry = true;
      const refreshToken = await AsyncStorage.getItem("refresh_token");
      const { data } = await axios.post("/auth/refresh", { refresh_token: refreshToken });
      await AsyncStorage.setItem("access_token", data.data.tokens.access_token);
      originalRequest.headers.Authorization = `Bearer ${data.data.tokens.access_token}`;
      return api(originalRequest);
    }
    return Promise.reject(error);
  }
);
```

## Mapeamento de Tipos para Componentes RN

| Tipo | Componente | Propriedades |
|------|-----------|-------------|
| string | TextInput | placeholder, maxLength |
| number | TextInput | keyboardType="number-pad" |
| boolean | Switch, CheckBox | value, onValueChange |
| select | Picker, RNPickerSelect | items, onValueChange |
| date | DateTimePicker | mode="date", minimumDate |
| time | DateTimePicker | mode="time" |
| phone | TextInput + mask | mask="(XX) 9XXXX-XXXX" |
| cpf | TextInput + mask | mask="XXX.XXX.XXX-XX" |
| cep | TextInput + lookup | mask="XXXXX-XXX", onChangeText |
| image | ImagePicker | multiple, maxSize |

## Schemas Principais

### Briefing Structure
```json
{
  "bedrooms": "number (1-20)",
  "bathrooms": "number (1-10)",
  "has_living_room": "boolean",
  "has_kitchen": "boolean"
}
```

### Briefing Frequency
```json
{
  "type": "avulso|weekly|monthly",
  "day_of_week": "monday|...|sunday",
  "recurrence_pattern": "weekly|every_2_weeks|..."
}
```

### Professional Availability
```json
{
  "monday": [{"start": "08:00", "end": "18:00"}],
  "tuesday": []
}
```

## Status de Retorno HTTP

- 200: Success
- 201: Created
- 400: Bad Request (validação)
- 401: Unauthorized (sem auth ou token expirado)
- 403: Forbidden (sem permissão)
- 404: Not Found
- 422: Validation Error
- 429: Rate Limit Exceeded
- 500: Server Error

## Para Documentação Completa

A documentação completa com todos os endpoints, request/response schemas detalhados, exemplos de código React Native e fluxos de trabalho está disponível na saída do agente Explore executado em 2026-02-13.

Inclui:
- 50+ endpoints mapeados
- Request/Response schemas completos
- Validações e regras de negócio
- Exemplos de implementação React Native
- Componentes reutilizáveis
- Fluxos end-to-end (Briefing, Professional, Execution)
- Error handling patterns
- Authentication flows

**Desenvolvedor:** Consulte a mensagem do agente Explore para schema completo.

---

## 👤 CUSTOMER API (NEW)

### Listar Clientes (Admin Only)
```
GET /wp-json/limpvix/v1/customers
Auth: Admin (manage_options)
Query: ?search=nome&status=active|inactive&min_spent=100&per_page=20&page=1

Response:
{
  "customers": [{
    "id": 123,
    "name": "João Silva",
    "email": "joao@example.com",
    "phone": "(27) 99999-9999",
    "total_contracts": 3,
    "active_contracts": 2,
    "total_spent": 1500.00,
    "lifetime_value": 5000.00,
    "created_at": "2025-01-15"
  }],
  "total": 150,
  "page": 1,
  "per_page": 20,
  "total_pages": 8
}
```

### Perfil do Cliente Autenticado
```
GET /wp-json/limpvix/v1/customers/me
Auth: JWT ou Session (qualquer usuário autenticado)

Response:
{
  "success": true,
  "data": {
    "id": 123,
    "name": "João Silva",
    "email": "joao@example.com",
    "phone": "(27) 99999-9999",
    "address": {
      "street": "Rua Exemplo, 123",
      "city": "Vitória",
      "state": "ES",
      "zip_code": "29050-385"
    },
    "role": "limpvix_customer",
    "statistics": {
      "total_contracts": 3,
      "active_contracts": 2,
      "total_spent": 1500.00,
      "lifetime_value": 5000.00,
      "is_high_value": true,
      "has_active_contracts": true
    },
    "created_at": "2025-01-15 10:30:00"
  }
}
```

### Detalhes de um Cliente
```
GET /wp-json/limpvix/v1/customers/{id}
Auth: Admin ou Own Profile

Response: Igual ao /customers/me
```

### Atualizar Perfil do Cliente
```
PUT /wp-json/limpvix/v1/customers/{id}
Auth: Admin ou Own Profile
Body:
{
  "name": "João Silva Santos",
  "phone": "(27) 98888-8888",
  "email": "joao.new@example.com",
  "address": {
    "street": "Rua Nova, 456",
    "number": "789",
    "complement": "Apt 12",
    "neighborhood": "Centro",
    "city": "Vitória",
    "state": "ES",
    "zip_code": "29050-100"
  }
}

Response:
{
  "success": true,
  "message": "Customer updated successfully",
  "data": { ... }
}
```

### Contratos do Cliente
```
GET /wp-json/limpvix/v1/customers/{id}/contracts
Auth: Admin ou Own Profile

Response:
{
  "success": true,
  "data": {
    "customer_id": 123,
    "contracts": [{
      "id": 1001,
      "contract_type": "monthly",
      "status": "active",
      "monthly_value": 500.00,
      "start_date": "2026-01-01"
    }],
    "total": 3
  }
}
```

### Briefings do Cliente
```
GET /wp-json/limpvix/v1/customers/{id}/briefings
Auth: Admin ou Own Profile

Response:
{
  "success": true,
  "data": {
    "customer_id": 123,
    "briefings": [{
      "uuid": "550e8400-...",
      "status": "completed",
      "property_type": "residential",
      "created_at": "2026-02-10 14:30:00"
    }],
    "total": 5
  }
}
```

---

## 🔐 CUSTOM USER ROLES

O plugin agora registra dois custom roles no WordPress:

### limpvix_customer (Cliente)
**Capabilities:**
- create_limpvix_briefings
- edit_own_limpvix_briefings
- view_own_limpvix_briefings
- view_own_limpvix_contracts
- view_own_limpvix_executions
- provide_feedback_limpvix_executions
- edit_limpvix_customer_profile

### limpvix_professional (Profissional)
**Capabilities:**
- view_limpvix_offers
- accept_limpvix_offers
- reject_limpvix_offers
- view_assigned_limpvix_contracts
- start_limpvix_executions
- complete_limpvix_executions
- upload_limpvix_evidence
- edit_limpvix_professional_availability
- view_own_limpvix_payouts

**Helpers:**
```php
use LimpVix\Core\UserRoles;

// Verificar role
UserRoles::isCustomer($userId);
UserRoles::isProfessional($userId);
UserRoles::isAdmin($userId);

// Atribuir role
UserRoles::assignCustomerRole($userId);
UserRoles::assignProfessionalRole($userId);

// Obter role
$role = UserRoles::getUserRole($userId); // "customer", "professional", "admin" ou null
```

---

**Última atualização:** 2026-02-13
**Versão da API:** v1
