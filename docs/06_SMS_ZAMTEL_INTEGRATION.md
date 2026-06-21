# Zamtel Bulk SMS Integration

## Overview

ZAQA sends production SMS through a queued pipeline backed by an internal SMS balance ledger. Delivery failures never break application workflows.

Central components:

| Component | Purpose |
|-----------|---------|
| `OutboundSmsService::queueTemplate()` | Validate, log, and queue SMS jobs (all outbound SMS including OTP) |
| `SendSmsJob` | Provider HTTP call, balance debit, log finalization |
| `SmsBalanceService` | Credits, debits, thresholds, dashboard stats |
| `ZamtelBulkSmsProvider` | Zamtel Bulk SMS HTTP integration |

## Environment variables

### Local / development (safe defaults)

Use the log provider until you are ready to test Zamtel. The admin SMS Balance page will show **Provider: log** and **SMS sending: Disabled**.

```env
SMS_ENABLED=false
SMS_PROVIDER=log
SMS_FROM=ZAQA
SMS_MAX_LENGTH=159
SMS_LOW_BALANCE_THRESHOLD=100
SMS_CRITICAL_BALANCE_THRESHOLD=10

# Optional — leave empty to skip balance alert emails locally
SMS_ALERT_EMAILS=

NOTIFICATIONS_MAIL_QUEUE=notifications
NOTIFICATIONS_SMS_QUEUE=
NOTIFICATIONS_LISTENER_QUEUE=default

ZAMTEL_SMS_BASE_URL=https://bulksms.zamtel.co.zm
ZAMTEL_SMS_API_KEY=
ZAMTEL_SMS_SENDER_ID=ZAQA
ZAMTEL_SMS_TIMEOUT=30
ZAMTEL_SMS_CONNECT_TIMEOUT=10
ZAMTEL_SMS_VERIFY_SSL=true
```

### Production (Zamtel live sending)

**Required changes** from local defaults:

| Variable | Production value | Notes |
|----------|------------------|-------|
| `SMS_ENABLED` | `true` | If `false`, template SMS are skipped (`disabled`) |
| `SMS_PROVIDER` | `zamtel` | If `log`, no HTTP call is made to Zamtel |
| `ZAMTEL_SMS_API_KEY` | Your Zamtel API key | From Zamtel Bulk SMS portal |
| `ZAMTEL_SMS_SENDER_ID` | Approved sender ID | Must be registered with Zamtel (e.g. `ZAQA`) |
| `QUEUE_CONNECTION` | `redis` | Required for production; Horizon manages workers |

```env
# --- Enable Zamtel SMS ---
SMS_ENABLED=true
SMS_PROVIDER=zamtel
SMS_FROM=ZAQA
SMS_MAX_LENGTH=159
SMS_LOW_BALANCE_THRESHOLD=100
SMS_CRITICAL_BALANCE_THRESHOLD=10

# Comma-separated recipients for low / critical / zero balance emails
SMS_ALERT_EMAILS=ict@zaqa.gov.zm,finance@zaqa.gov.zm

# --- Notification queues ---
QUEUE_CONNECTION=redis
NOTIFICATIONS_MAIL_QUEUE=notifications
NOTIFICATIONS_SMS_QUEUE=sms
NOTIFICATIONS_LISTENER_QUEUE=default

# --- Zamtel Bulk SMS API ---
ZAMTEL_SMS_BASE_URL=https://bulksms.zamtel.co.zm
ZAMTEL_SMS_API_KEY=your_zamtel_api_key_here
ZAMTEL_SMS_SENDER_ID=ZAQA
ZAMTEL_SMS_TIMEOUT=30
ZAMTEL_SMS_CONNECT_TIMEOUT=10
ZAMTEL_SMS_VERIFY_SSL=true
```

If `NOTIFICATIONS_SMS_QUEUE` is empty, SMS jobs use the default queue connection queue name (`default`).

**Important:** Define each SMS variable **once** in `.env`. Duplicate keys (e.g. two `SMS_ENABLED=` lines) can cause confusing behaviour — Laravel may not read the value you expect.

After any `.env` change on the server:

```bash
php artisan config:clear
php artisan config:cache
php artisan horizon:terminate
```

The admin **Settings → SMS Balance** page reads these values. When production is configured correctly you should see:

- **SMS provider:** `zamtel`
- **SMS sending:** `Enabled`

If you still see `log` / `Disabled`, check `SMS_ENABLED`, `SMS_PROVIDER`, run `config:clear`, and confirm Horizon was restarted (`php artisan horizon:terminate`).

## Production go-live checklist

1. Set production `.env` values above (`SMS_ENABLED=true`, `SMS_PROVIDER=zamtel`, Zamtel credentials).
2. Run `php artisan config:clear`.
3. Run `php artisan sms:test` — confirms host reachability and credentials are present (does not send an SMS).
4. Use **Admin → Settings → SMS Balance → Test provider connection** for the same check in the UI.
5. **Add SMS balance** (Admin → Settings → SMS Balance) — balance must be **> 0** or sends are skipped as `insufficient_balance`.
6. Ensure **Horizon is running** (Supervisor `[program:zaqa-horizon]`). Without Horizon, SMS jobs stay `queued` in `sms_logs`. See `docs/05_MOBILE_MONEY_PAYMENTS_PRODUCTION.md` for setup.
7. Trigger a test notification (e.g. account OTP or payment approved) and review **Reports → SMS logs**.
8. Confirm success: HTTP **200 or 202** and response body **`success: true`** — only then is balance debited.

All outbound SMS (including account activation OTP) use `queueTemplate()` and respect `SMS_ENABLED`, `SMS_PROVIDER`, balance, and queue settings.

## Success criteria

An SMS is successful only when:

1. HTTP status is **200** or **202**
2. Parsed response body has **`success: true`**

Only successful sends decrement internal balance by 1.

## Queues and Horizon

SMS and notification jobs are processed by **Laravel Horizon** (same production architecture as payments). Horizon supervisors in `config/horizon.php` include:

| Queue | Horizon supervisor | Used for |
|-------|-------------------|----------|
| `notifications` | `supervisor-notifications` | Outbound email (including balance alert emails) |
| `default` | `supervisor-default` | Event listeners; `SendSmsJob` when `NOTIFICATIONS_SMS_QUEUE` is empty |

If `NOTIFICATIONS_SMS_QUEUE` is set to a dedicated queue name (e.g. `sms`), ensure that queue is supervised in Horizon or leave it empty so SMS uses `default`.

| Queue | Env variable | Used for |
|-------|--------------|----------|
| `sms` (optional) | `NOTIFICATIONS_SMS_QUEUE` | `SendSmsJob` when explicitly configured |
| `default` | fallback | `SendSmsJob` when SMS queue unset |
| `default` | `NOTIFICATIONS_LISTENER_QUEUE` | Event listeners |
| `notifications` | `NOTIFICATIONS_MAIL_QUEUE` | Outbound email (including balance alert emails) |

Recommended production setup:

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
HORIZON_PREFIX=zaqa_horizon
NOTIFICATIONS_MAIL_QUEUE=notifications
NOTIFICATIONS_SMS_QUEUE=
NOTIFICATIONS_LISTENER_QUEUE=default
```

## Supervisor (Horizon) — production

Run **one** Horizon program per application server (not separate notification workers):

```ini
[program:zaqa-horizon]
process_name=%(program_name)s
command=php /var/www/html/zaqa-portal/artisan horizon
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/zaqa-horizon.log
stopwaitsecs=3600
```

After deploy:

```bash
php artisan config:cache
php artisan horizon:terminate
```

Full deployment, operations, and monitoring: `docs/05_MOBILE_MONEY_PAYMENTS_PRODUCTION.md`.

## Legacy `queue:work` configuration (fallback only)

> **Warning:** Do not run these workers when Horizon is enabled.
>
> Running Horizon and `queue:work` against the same queues can cause duplicate processing, monitoring confusion, and unpredictable worker balancing.

```ini
[program:zaqa-worker-notifications]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/zaqa-portal/artisan queue:work redis --queue=sms,notifications,default --sleep=2 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/zaqa/worker-notifications.log
```

Legacy deploy restart:

```bash
php artisan queue:restart
```

## Local development (non-Horizon)

For local development with `QUEUE_CONNECTION=database`, run a worker manually (not used in production):

```bash
php artisan queue:work --queue=default --tries=3 --timeout=90
```

## Balance management

- Admin → **Settings → SMS Balance**
- Credits are auditable in `sms_balance_adjustments`
- Warning banner at `balance <= SMS_LOW_BALANCE_THRESHOLD` (default 100)
- Critical banner at `balance <= SMS_CRITICAL_BALANCE_THRESHOLD` (default 10)
- When balance is `0`, template SMS are skipped as `insufficient_balance`

Permissions:

- `sms.balance.view`
- `sms.balance.manage`
- `sms.logs.view`

## Email balance alerts

Queued email alerts notify configured recipients when balance **crosses** a threshold (not on every SMS send).

```env
SMS_ALERT_EMAILS=ict@zaqa.gov.zm,finance@zaqa.gov.zm
```

If `SMS_ALERT_EMAILS` is empty, alert emails are skipped silently.

| Alert | Subject | Fires when |
|-------|---------|------------|
| Low | `[ZAQA] SMS Balance Low` | Balance crosses from above `SMS_LOW_BALANCE_THRESHOLD` to at or below it |
| Critical | `[ZAQA] SMS Balance Critical` | Balance crosses from above `SMS_CRITICAL_BALANCE_THRESHOLD` to at or below it |
| Zero | `[ZAQA] SMS Balance Exhausted` | Balance crosses from above `0` to `0` |

Alert state is stored on `sms_balance_accounts`:

- `low_balance_alert_sent_at`
- `critical_balance_alert_sent_at`
- `zero_balance_alert_sent_at`

Each alert fires **once per threshold crossing**. Further debits below the threshold do not repeat emails until alert timestamps are reset.

### Reset behavior

When balance is topped up above a threshold, the corresponding alert timestamp is cleared:

- Balance above low threshold → `low_balance_alert_sent_at = null`
- Balance above critical threshold → `critical_balance_alert_sent_at = null`
- Balance above zero → `zero_balance_alert_sent_at = null`

Example: balance `5` topped up to `200` clears all three timestamps. A later drop to `99` sends the low alert again.

Alerts are evaluated by `SmsBalanceAlertService` after:

1. Successful SMS debit (`SendSmsJob`)
2. Manual balance credit (`SmsBalanceService::credit`)

Emails are queued through `OutboundMailService` on the notifications mail queue.

## Templates

All business SMS text lives in `config/sms_templates.php`. Rendered messages must not exceed 159 characters.

Phase 2 wired templates:

- `application_submitted`
- `application_resubmitted`
- `payment_approved`
- `application_sent_back`

Phase 3 wired templates:

- `qualification_sent_back`
- `certificate_issued`
- `activation_otp`

## Health check

CLI:

```bash
php artisan sms:test
```

Admin UI: **Settings → SMS Balance → Test provider connection**

Neither command exposes API secrets. A passing check does not send an SMS.

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Admin shows **Provider: log** | Set `SMS_PROVIDER=zamtel` and run `php artisan config:clear` |
| Admin shows **SMS sending: Disabled** | Set `SMS_ENABLED=true` and run `php artisan config:clear` |
| SMS stuck in `queued` | Worker running on `sms` / `default` queue; Redis or database queue connection |
| `skipped / disabled` | `SMS_ENABLED=false` |
| `skipped / insufficient_balance` | Add credits in admin SMS balance page |
| `skipped / too_long` | Template or placeholder exceeds 159 characters |
| `skipped / invalid_phone` | Recipient not a valid Zambian MSISDN |
| `failed` with HTTP 200/202 | Provider returned `success=false` |
| Balance not decreasing | Confirm HTTP 200/202 + `success=true` in provider response |
| OTP not received | Same pipeline as other SMS — check `SMS_ENABLED`, balance, worker, and **Reports → SMS logs** for `activation_otp` |

Monitor failed jobs:

```bash
php artisan queue:failed
```

Review entries in **Reports → SMS logs** (route: `/admin/settings/sms/logs`).

## Admin log privacy

SMS log detail pages mask recipient numbers and redact sensitive template values before display (for example OTP codes on `activation_otp`). Full message content remains stored in the database for delivery and auditing; configure additional redaction in `config/sms.php` under `admin_redaction`.
