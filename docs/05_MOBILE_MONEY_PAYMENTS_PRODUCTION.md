# Mobile Money Payments — Production Architecture

## Production queue architecture (official)

Production queue processing uses:

```text
Laravel Horizon
Redis
Supervisor managing Horizon
```

- **Redis** is the queue backend (`QUEUE_CONNECTION=redis`).
- **Laravel Horizon** (`php artisan horizon`) manages all worker processes and queue balancing.
- **Supervisor** runs a **single** `[program:zaqa-horizon]` process per application server — not separate `queue:work` programs.

Horizon supervisors and worker counts are defined in `config/horizon.php` (production environment):

| Horizon supervisor | Queue | Production min/max processes | Timeout |
|--------------------|-------|------------------------------|---------|
| `supervisor-payments-high` | `payments-high` | 2 / 10 | 120 s |
| `supervisor-payments` | `payments` | 2 / 12 | 120 s |
| `supervisor-notifications` | `notifications` | 1 / 6 | 120 s |
| `supervisor-default` | `default` | 2 / 8 | 300 s |

**Do not** run `php artisan queue:work` for these queues while Horizon is enabled on the same host.

---

## Overview

Applicant Mobile Money uses cGrate (Konik SOAP) with an **async initiation + queued polling** architecture designed for scale.

Flow:

1. Applicant submits mobile number → payment attempt created immediately.
2. `DispatchMobileMoneyPaymentPromptJob` (`payments-high` queue) sends the USSD/STK prompt.
3. `QueryCGratePaymentAttemptJob` (`payments` queue) polls provider status with backoff.
4. Optional inbound callback triggers an immediate follow-up poll (idempotent).
5. Confirmed payments settle the invoice and auto-submit the application.

Applicants only see: **pending**, **successful**, **failed**.

---

## Queues (payment jobs)

| Queue | Purpose | Jobs |
|-------|---------|------|
| `payments-high` | Payment prompt push + callback follow-up | `DispatchMobileMoneyPaymentPromptJob` |
| `payments` | Status polling | `QueryCGratePaymentAttemptJob` |
| `default` | Other application jobs (auto-verification, etc.) | — |

Related queues (not payment-specific but supervised by the same Horizon instance):

| Queue | Purpose |
|-------|---------|
| `notifications` | Outbound email and portal notifications |

---

## Redis setup

Install and enable Redis on each application server:

```bash
sudo apt update
sudo apt install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
redis-cli ping   # expect PONG
```

Install the PHP Redis extension (match your PHP version):

```bash
sudo apt install php8.3-redis
sudo systemctl restart php8.3-fpm
```

### Environment variables

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
HORIZON_PREFIX=zaqa_horizon
PAYMENTS_QUEUE_HIGH=payments-high
PAYMENTS_QUEUE=payments
```

### How Horizon manages workers

- Horizon **automatically** starts, balances, and restarts worker processes — you do **not** add per-queue `queue:work` Supervisor programs.
- **Worker counts** and timeouts come from `config/horizon.php` (`environments.production`).
- **Scaling** is done by adjusting Horizon supervisor settings (e.g. `minProcesses` / `maxProcesses`), not by adding more `queue:work` processes.

After changing Horizon or queue configuration:

```bash
php artisan config:cache
php artisan horizon:terminate
```

---

## Supervisor (Horizon) — production

Run **one** Horizon program per application server:

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

Enable after first deploy:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start zaqa-horizon
```

---

## Deployment

Primary production deploy sequence:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan horizon:terminate
```

Ensure the scheduler cron is active (`cgrate.poll_due_attempts` runs every minute):

```bash
php artisan schedule:run
```

Do **not** use `php artisan queue:restart` in the primary Horizon deployment workflow. That command applies only to legacy `queue:work` setups (see fallback section below).

---

## Horizon operations

### View status

```bash
php artisan horizon:status
```

### Restart Horizon after deployment or config changes

```bash
php artisan horizon:terminate
```

Supervisor will restart Horizon automatically. Workers finish their current job before exiting (graceful termination).

### Pause processing (maintenance)

```bash
php artisan horizon:pause
```

### Resume processing

```bash
php artisan horizon:continue
```

---

## Monitoring

| Area | How |
|------|-----|
| **Horizon dashboard** | `https://<your-domain>/horizon` |
| **Access control** | Super Admin role only in non-local environments; local environment bypasses the gate |
| **Queue depth** | Horizon dashboard → Queues; Redis `LLEN` on queue keys during incidents |
| **Failed jobs** | Horizon dashboard → Failed Jobs; CLI: `php artisan queue:failed` |
| **Throughput** | Horizon dashboard → Metrics (requires `horizon:snapshot` in scheduler) |
| **Payment-specific** | Admin finance payment views; `payment_webhook_logs`; `failed_jobs` table |

Alert if `failed_jobs` grows unexpectedly during payment peaks or if `payments-high` wait time exceeds Horizon `waits` thresholds in `config/horizon.php`.

---

## Legacy `queue:work` configuration (fallback only)

> **Warning:** Do not run these workers when Horizon is enabled.
>
> Running Horizon and `queue:work` against the same queues can cause **duplicate processing**, **monitoring confusion**, and **unpredictable worker balancing**.
>
> Use this section **only** when Horizon cannot be deployed (not recommended for production).

### Legacy Supervisor examples

```ini
[program:zaqa-worker-payments-high]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/zaqa-portal/artisan queue:work redis --queue=payments-high,default --sleep=1 --tries=3 --timeout=120 --max-time=3600
autostart=true
autorestart=true
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/zaqa/worker-payments-high.log
stopwaitsecs=130

[program:zaqa-worker-payments]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/zaqa-portal/artisan queue:work redis --queue=payments,default --sleep=2 --tries=3 --timeout=120 --max-time=3600
autostart=true
autorestart=true
numprocs=8
redirect_stderr=true
stdout_logfile=/var/log/zaqa/worker-payments.log
stopwaitsecs=130

[program:zaqa-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/zaqa-portal/artisan queue:work redis --queue=default --sleep=3 --tries=3 --timeout=90 --max-time=3600
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/zaqa/worker-default.log
stopwaitsecs=100
```

### Legacy deploy restart

```bash
php artisan queue:restart
sudo supervisorctl restart zaqa-worker-payments-high:*
sudo supervisorctl restart zaqa-worker-payments:*
sudo supervisorctl restart zaqa-worker-default:*
```

---

## Applicant API

### Initiate (async)

`POST /applicant/applications/{application}/payment/initiate-mobile-money`

Returns JSON when `Accept: application/json`:

```json
{
  "attempt_id": 42,
  "status": "pending",
  "message": "Waiting for payment approval.",
  "paid": false,
  "mobile_number": "0971000000",
  "amount_cents": 15000,
  "currency": "ZMW",
  "already_pending": false
}
```

### Poll status (every 3 seconds from UI)

`GET /applicant/payments/attempts/{attempt}/status`

Returns applicant-safe fields only. Technical gateway diagnostics are excluded.

---

## Callback (optional)

`POST /webhooks/cgrate/payment`

```env
CGRATE_CALLBACK_ENABLED=true
CGRATE_CALLBACK_TOKEN=your-shared-secret
CGRATE_CALLBACK_ALLOWED_IPS=203.0.113.10,203.0.113.11
```

Payload must include `payment_reference` (or `ref`) and `token` (or `X-CGrate-Callback-Token` header).

Callbacks are idempotent: terminal attempts are ignored; active attempts dispatch a high-priority poll job.

---

## Retry rules

- **Pending attempt exists** → reuse it, do not send a second prompt.
- **Failed/expired attempt** → new attempt with a new `payment_reference`.
- **Confirmed payment** → invoice settled; applicant UI becomes read-only.

---

## Failed jobs

Monitor via Horizon dashboard or CLI:

```bash
php artisan queue:failed
php artisan queue:retry all
```

---

## Scheduler safety net

`cgrate.poll_due_attempts` runs every minute and dispatches polling jobs for due attempts (limit 50/run).

---

## Admin diagnostics

Finance/admin payment views retain full gateway diagnostics:

- response codes
- query attempt counts
- sanitized gateway payloads
- callback logs in `payment_webhook_logs`

These fields are intentionally hidden from applicant endpoints and the payment wizard UI.
