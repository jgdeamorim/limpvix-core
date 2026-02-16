# LimpVix API Documentation

**Base URL:** `https://seu-site.com/wp-json/limpvix/v1`  
**Version:** 1.0  
**Last Updated:** 2026-02-13

## Authentication Methods

### 1. JWT (Recommended for Apps)
- Login returns access + refresh tokens
- Access token valid for 1 hour
- Refresh token valid for 30 days

### 2. API Keys
- Long-lived tokens for external integrations
- Scope-based permissions
- Revocable instantly

### 3. Application Passwords
- WordPress native (WP 5.6+)
- Available in user profile
- Basic authentication

## Quick Start

### Login with JWT
```bash
curl -X POST https://site.com/wp-json/limpvix/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"user","password":"pass"}'
```

### Use Token
```bash
curl https://site.com/wp-json/limpvix/v1/briefings \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## Rate Limiting

- Public: 60 req/min
- Authenticated: 300 req/min
- Admin: 1000 req/min

## CORS Enabled

Cross-origin requests supported for web/mobile apps.

For complete documentation, see full API guide.
