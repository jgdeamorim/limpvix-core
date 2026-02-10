# GAP #2: Recurring Payment System - Deployment Guide

**Version:** 1.0.0
**Date:** 2026-02-10
**Target Environment:** Production
**Estimated Time:** 30-45 minutes

---

## Table of Contents

1. [Pre-Deployment Checklist](#pre-deployment-checklist)
2. [Deployment Steps](#deployment-steps)
3. [MercadoPago Configuration](#mercadopago-configuration)
4. [Post-Deployment Verification](#post-deployment-verification)
5. [Monitoring Setup](#monitoring-setup)
6. [Troubleshooting](#troubleshooting)
7. [Rollback Procedure](#rollback-procedure)

---

## Pre-Deployment Checklist

### ✅ Prerequisites

- [ ] Backup database before deployment
- [ ] Backup plugin code before deployment
- [ ] MercadoPago production credentials ready
- [ ] Access to MercadoPago dashboard
- [ ] WP-CLI available on server
- [ ] SSH/terminal access to production server
- [ ] Git repository up to date with latest code
- [ ] Staging environment tested successfully (recommended)

### ✅ MercadoPago Requirements

- [ ] MercadoPago account verified (production mode)
- [ ] Access Token (production) obtained
- [ ] Webhook Secret generated
- [ ] Webhook URL configured in MercadoPago dashboard
- [ ] Payment methods enabled: PIX, credit card, boleto

### ✅ Server Requirements

- [ ] PHP 7.4+ with JSON extension
- [ ] MySQL 8.0+ or MariaDB 10.5+
- [ ] WordPress 6.0+
- [ ] WordPress Cron enabled
- [ ] HTTPS/SSL certificate active (required for webhooks)
- [ ] Firewall allows inbound HTTPS from MercadoPago IPs

---

## Deployment Steps

### Step 1: Code Deployment

```bash
# 1. SSH into production server
ssh user@limpvix-production

# 2. Navigate to plugin directory
cd /var/www/html/wp-content/plugins/limpvix-core

# 3. Backup current code
tar -czf ~/limpvix-core-backup-$(date +%Y%m%d-%H%M%S).tar.gz .

# 4. Pull latest code from repository
git fetch origin
git checkout main
git pull origin main

# 5. Verify code deployed
git log -1 --oneline
# Should show: feat(GAP#2): Implement Fase 5 - Documentation (or similar)
```

### Step 2: Database Migration

```bash
# 1. Backup database
mysqldump -u root -p limpvix_db > ~/limpvix-db-backup-$(date +%Y%m%d-%H%M%S).sql

# 2. Verify migration file exists
ls -lh database-migrations/016_add_recurring_payments.sql

# 3. Execute migration
wp db query < database-migrations/016_add_recurring_payments.sql

# 4. Verify table created
wp db query "SHOW TABLES LIKE 'wp_limpvix_recurring_payments';"

# Expected output: wp_limpvix_recurring_payments

# 5. Verify table structure
wp db query "DESCRIBE wp_limpvix_recurring_payments;"

# Expected columns: id, payment_uuid, contract_id, billing_cycle_number,
#                   amount, status, due_date, gateway_transaction_id,
#                   attempt_count, paid_at, failure_reason, created_at, updated_at
```

### Step 3: WordPress Configuration

```bash
# 1. Deactivate plugin (to trigger uninstall hooks)
wp plugin deactivate limpvix-core

# 2. Reactivate plugin (to register new cron jobs and hooks)
wp plugin activate limpvix-core

# 3. Verify plugin activated
wp plugin list | grep limpvix-core
# Expected: limpvix-core | active

# 4. Flush rewrite rules (for new REST API endpoints)
wp rewrite flush

# 5. Verify REST API routes registered
wp rest-api list --path=/limpvix/v1 | grep webhooks
# Expected: /limpvix/v1/webhooks/mercadopago

# 6. Clear object cache (if using Redis/Memcached)
wp cache flush
```

### Step 4: MercadoPago Settings

```bash
# Configure MercadoPago credentials in WordPress options

# 1. Set Access Token (production)
wp option update limpvix_mercadopago_access_token "YOUR_PRODUCTION_ACCESS_TOKEN"

# 2. Set Webhook Secret
wp option update limpvix_mercadopago_webhook_secret "YOUR_WEBHOOK_SECRET"

# 3. Verify settings saved
wp option get limpvix_mercadopago_access_token
wp option get limpvix_mercadopago_webhook_secret

# IMPORTANT: Never commit these values to git!
```

### Step 5: Cron Job Verification

```bash
# 1. List all LimpVix cron jobs
wp cron event list | grep limpvix

# Expected output:
# limpvix_charge_recurring_payments    <timestamp>    limpvix_daily
# limpvix_check_contract_expiration    <timestamp>    daily

# 2. If cron not scheduled, schedule manually
wp cron event schedule limpvix_charge_recurring_payments "tomorrow midnight" limpvix_daily

# 3. Verify next execution time
wp cron event list | grep limpvix_charge_recurring_payments

# 4. Test cron execution (dry run)
wp cron event run limpvix_charge_recurring_payments --dry-run

# 5. Check logs for execution
tail -f /var/log/wordpress/debug.log | grep "RecurringPaymentCronAdapter"
```

---

## MercadoPago Configuration

### Webhook Setup

1. **Access MercadoPago Dashboard:**
   - Go to: https://www.mercadopago.com.br/developers/panel
   - Navigate to: Your Applications → Your Application → Webhooks

2. **Register Webhook URL:**
   ```
   URL: https://limpvix.com.br/wp-json/limpvix/v1/webhooks/mercadopago
   Events: payment (all payment events)
   ```

3. **Generate Webhook Secret:**
   - Copy the webhook secret provided by MercadoPago
   - Save it in WordPress options (see Step 4 above)

4. **Test Webhook:**
   ```bash
   # Send test webhook from MercadoPago dashboard
   # Or use curl to test locally:

   curl -X POST https://limpvix.com.br/wp-json/limpvix/v1/webhooks/mercadopago \
     -H "Content-Type: application/json" \
     -H "x-signature: ts=1234567890,v1=test" \
     -H "x-request-id: test-request-id" \
     -d '{
       "type": "payment",
       "action": "payment.updated",
       "data": {
         "id": "12345678"
       }
     }'

   # Expected response: 200 OK or 403 Forbidden (signature invalid)
   ```

### Payment Methods

Enable payment methods in MercadoPago dashboard:
- ✅ PIX (instant, QR code) - **RECOMMENDED**
- ✅ Credit Card (Visa, Mastercard, Elo)
- ✅ Boleto Bancário (bank slip)

---

## Post-Deployment Verification

### ✅ Database Verification

```bash
# 1. Check table exists
wp db query "SELECT COUNT(*) FROM wp_limpvix_recurring_payments;"
# Expected: 0 (or existing records if migrating data)

# 2. Verify indexes
wp db query "SHOW INDEX FROM wp_limpvix_recurring_payments;"
# Expected: 8 indexes including uk_payment_uuid, idx_contract_cycle

# 3. Verify foreign key constraint
wp db query "
  SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
  FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE TABLE_NAME = 'wp_limpvix_recurring_payments';
"
# Expected: fk_recurring_payment_contract → wp_limpvix_contracts
```

### ✅ Code Verification

```bash
# 1. Verify classes autoload
wp eval "
  \$classes = [
    'LimpVix\Domain\Finance\RecurringPayment',
    'LimpVix\Application\UseCases\Finance\ChargeRecurringPayment',
    'LimpVix\Infrastructure\Persistence\Finance\WpRecurringPaymentRepository',
    'LimpVix\Infrastructure\Cron\RecurringPaymentCronAdapter',
  ];

  foreach (\$classes as \$class) {
    if (class_exists(\$class)) {
      echo \"✓ \$class\n\";
    } else {
      echo \"✗ \$class NOT FOUND\n\";
    }
  }
"

# Expected: All classes with ✓
```

### ✅ Integration Verification

```bash
# 1. Verify ContractBootstrap registered components
wp eval "
  \$useCases = \$GLOBALS['limpvix_recurring_payment_use_cases'] ?? [];
  echo 'Recurring Payment Use Cases: ' . count(\$useCases) . \"\n\";
  print_r(array_keys(\$useCases));
"

# Expected output:
# Recurring Payment Use Cases: 3
# Array (
#   [0] => charge
#   [1] => process_webhook
#   [2] => retry
# )

# 2. Verify REST API endpoint accessible
curl -I https://limpvix.com.br/wp-json/limpvix/v1/webhooks/mercadopago

# Expected: HTTP/1.1 405 Method Not Allowed (POST only endpoint)
```

### ✅ Manual Test: Create Test Payment

```bash
# Create a test contract with auto_renew=true and end_date=today
# Then manually trigger cron:

wp cron event run limpvix_charge_recurring_payments

# Check logs:
tail -f /var/log/wordpress/debug.log | grep -E "(RecurringPayment|ChargeRecurring)"

# Expected log entries:
# [LimpVix] RecurringPaymentCronAdapter: Starting execution...
# [LimpVix] Found X expiring contracts to charge
# [LimpVix] Successfully charged contract Y: {...}
```

---

## Monitoring Setup

### ✅ Enable Debug Logging

```php
// In wp-config.php, ensure:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### ✅ Monitor Cron Execution

```bash
# Add to crontab (system cron, not WP cron):
# Every hour, check if WP cron is running

0 * * * * wp cron event list | grep limpvix_charge_recurring_payments || echo "Cron not scheduled!"
```

### ✅ Monitor Payment Statistics

```bash
# Create daily report script:
# /usr/local/bin/limpvix-payment-report.sh

#!/bin/bash
wp eval "
  \$repo = new \LimpVix\Infrastructure\Persistence\Finance\WpRecurringPaymentRepository();
  \$stats = \$repo->getStatistics();

  echo \"=== LimpVix Payment Statistics ===\n\";
  echo \"Total Payments: {\$stats['total']}\n\";
  echo \"Pending: {\$stats['pending']}\n\";
  echo \"Processing: {\$stats['processing']}\n\";
  echo \"Completed: {\$stats['completed']}\n\";
  echo \"Failed: {\$stats['failed']}\n\";
  echo \"Total Revenue: R$ \" . number_format(\$stats['total_revenue'], 2) . \"\n\";
"

# Run daily via cron:
# 0 8 * * * /usr/local/bin/limpvix-payment-report.sh | mail -s "LimpVix Payment Report" admin@limpvix.com.br
```

### ✅ Alert on Stuck Payments

```bash
# Create alert script:
# /usr/local/bin/limpvix-stuck-payments-alert.sh

#!/bin/bash
STUCK=$(wp eval "
  \$repo = new \LimpVix\Infrastructure\Persistence\Finance\WpRecurringPaymentRepository();
  \$stuck = \$repo->findStuckPayments();
  echo count(\$stuck);
")

if [ "$STUCK" -gt 0 ]; then
  echo "WARNING: $STUCK payments stuck in processing for >24h" | \
    mail -s "LimpVix ALERT: Stuck Payments" admin@limpvix.com.br
fi

# Run every 6 hours via cron:
# 0 */6 * * * /usr/local/bin/limpvix-stuck-payments-alert.sh
```

---

## Troubleshooting

### Issue 1: Webhook Returns 403 Forbidden

**Symptom:** MercadoPago webhook calls return 403 error

**Solution:**
1. Verify webhook secret is configured correctly:
   ```bash
   wp option get limpvix_mercadopago_webhook_secret
   ```

2. Check webhook signature format in MercadoPago dashboard

3. Enable debug logging and check signature validation:
   ```bash
   tail -f /var/log/wordpress/debug.log | grep "webhook"
   ```

### Issue 2: Cron Not Executing

**Symptom:** No payments being charged automatically

**Solution:**
1. Verify WP Cron is enabled in wp-config.php:
   ```php
   // Should NOT be set to true
   define('DISABLE_WP_CRON', false);
   ```

2. Manually trigger cron:
   ```bash
   wp cron event run limpvix_charge_recurring_payments
   ```

3. Check if system cron is calling WP Cron:
   ```bash
   # Add to system crontab if not present:
   */15 * * * * wget -q -O - https://limpvix.com.br/wp-cron.php?doing_wp_cron >/dev/null 2>&1
   ```

### Issue 3: Payments Not Renewing Contracts

**Symptom:** Payments marked as completed but contracts not renewed

**Solution:**
1. Check if ContractRenewed event is being dispatched:
   ```bash
   tail -f /var/log/wordpress/debug.log | grep "ContractRenewed"
   ```

2. Verify Contract::renewWithPayment() is being called:
   ```bash
   tail -f /var/log/wordpress/debug.log | grep "auto-renewed"
   ```

3. Check if email confirmation is being sent:
   ```bash
   tail -f /var/mail/root | grep "Contrato Renovado"
   ```

### Issue 4: Duplicate Payments

**Symptom:** Same contract charged twice for same cycle

**Solution:**
1. This should be prevented by UNIQUE constraint, check database:
   ```bash
   wp db query "
     SELECT contract_id, billing_cycle_number, COUNT(*) as count
     FROM wp_limpvix_recurring_payments
     GROUP BY contract_id, billing_cycle_number
     HAVING count > 1;
   "
   ```

2. If duplicates exist, contact support to resolve manually

### Issue 5: MercadoPago API Errors

**Symptom:** Payments failing with API errors

**Solution:**
1. Verify access token is valid:
   ```bash
   wp option get limpvix_mercadopago_access_token
   ```

2. Test MercadoPago API connectivity:
   ```bash
   curl -X GET https://api.mercadopago.com/v1/payment_methods \
     -H "Authorization: Bearer YOUR_ACCESS_TOKEN"
   ```

3. Check MercadoPago status page: https://status.mercadopago.com/

---

## Rollback Procedure

### If Critical Issues Occur

**IMPORTANT:** Only rollback if critical issues affect production revenue.

### Step 1: Disable Cron

```bash
# Prevent new payments from being charged
wp cron event delete limpvix_charge_recurring_payments
```

### Step 2: Deactivate Plugin

```bash
# This will stop webhook processing
wp plugin deactivate limpvix-core
```

### Step 3: Rollback Database

```bash
# ONLY if payment data is corrupted
# This will DELETE all recurring payment records!

wp db query "DROP TABLE IF EXISTS wp_limpvix_recurring_payments;"

# Restore from backup if needed:
mysql -u root -p limpvix_db < ~/limpvix-db-backup-YYYYMMDD-HHMMSS.sql
```

### Step 4: Rollback Code

```bash
cd /var/www/html/wp-content/plugins/limpvix-core

# Find commit before GAP #2
git log --oneline | grep -B1 "feat(GAP#2)"

# Revert to previous commit
git revert e54591a  # Replace with actual commit hash
git revert 69433ed
git revert b5cc51f
git revert aef307d

# Or hard reset (destructive):
git reset --hard <commit-before-gap2>
```

### Step 5: Reactivate Plugin

```bash
# Reactivate with old code
wp plugin activate limpvix-core

# Verify old functionality restored
wp plugin list | grep limpvix-core
```

### Step 6: Notify Stakeholders

- Send email to admin team about rollback
- Update status page if applicable
- Schedule post-mortem meeting

---

## Success Criteria

Deployment is considered successful when:

- ✅ Database migration executed without errors
- ✅ All 17 new files deployed and loading correctly
- ✅ Cron job scheduled and executing
- ✅ Webhook endpoint returning 200 OK for valid signatures
- ✅ Test payment successfully charged
- ✅ Test payment successfully confirmed via webhook
- ✅ Test contract successfully renewed
- ✅ Confirmation email sent to customer
- ✅ No errors in debug.log
- ✅ MercadoPago dashboard shows webhook active

---

## Post-Deployment Checklist

### Day 1 (Deployment Day)

- [ ] Monitor cron execution every hour
- [ ] Check payment statistics: `wp eval "...getStatistics()"`
- [ ] Verify webhook calls from MercadoPago
- [ ] Check email delivery (confirmation emails)
- [ ] Monitor error logs continuously

### Week 1

- [ ] Daily payment statistics report
- [ ] Check for stuck payments
- [ ] Verify retry logic working (if any failures)
- [ ] Monitor revenue trends
- [ ] Customer support tickets related to payments

### Month 1

- [ ] Review payment success rate (target: ≥95%)
- [ ] Review retry resolution rate (target: ≥80%)
- [ ] Review contract renewal rate (target: ≥90%)
- [ ] Optimize retry schedule if needed
- [ ] Plan for future enhancements

---

## Support Contacts

**Technical Issues:**
- Email: dev@limpvix.com.br
- Slack: #limpvix-dev

**MercadoPago Support:**
- Email: developers@mercadopago.com
- Phone: 0800 275 0505
- Docs: https://www.mercadopago.com.br/developers

**Database Issues:**
- DBA: dba@limpvix.com.br

---

**Deployment Guide Version:** 1.0.0
**Last Updated:** 2026-02-10
**Next Review:** 2026-03-10
