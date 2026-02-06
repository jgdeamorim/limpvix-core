# 🔬 Diagnóstico de Integrações LimpVix

## 📋 Ferramentas Disponíveis

### test-integrations.php
**Script HTML interativo para diagnóstico completo de todas as integrações**

**Acesso via navegador:**
```
http://localhost/wp-content/plugins/limpvix-core/diagnostics/test-integrations.php
```

**O que testa:**
- ✅ Twilio SMS (credenciais + API)
- ✅ Google Meu Negócio (Place ID + URL)
- ✅ Mercado Pago Payouts (credenciais + API /v1/users/me)
- ✅ 360Dialog WhatsApp (API Key + endpoint)
- ✅ WooCommerce (instalação + versão)
- ✅ WooCommerce Mercado Pago (plugin ativo + credenciais)

**Requisitos:**
- Login como administrador do WordPress
- Permissão `manage_options`

**Interface:**
- Status visual com cores
- Testes de API em tempo real
- Máscara de credenciais sensíveis
- Resumo final com % de sucesso
