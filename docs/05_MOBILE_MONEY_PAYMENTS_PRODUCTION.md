# Mobile Money Payments — Production Architecture

## Overview

Applicant Mobile Money uses cGrate (Konik SOAP) with an **async initiation + queued polling** architecture designed for scale.

Flow:

1. Applicant submits mobile number → payment attempt created immediately.
2. `DispatchMobileMoneyPaymentPromptJob` (high-priority queue) sends the USSD/STK prompt.
3. `QueryCGratePaymentAttemptJob` (payments queue) polls provider status with backoff.
4. Optional inbound callback triggers an immediate follow-up poll (idempotent).
5. Confirmed payments settle the invoice and auto-submit the application.

Applicants only see: **pending**, **successful**, **failed**.

## Queues

| Queue | Purpose | Jobs |
|-------|---------|------|
| `payments-high` | Payment prompt push + callback follow-up | `DispatchMobileMoneyPaymentPromptJob` |
| `payments` | Status polling | `QueryCGratePaymentAttemptJob` |
| `default` | Other application jobs | — |

Environment:

```env
QUEUE_CONNECTION=redis
PAYMENTS_QUEUE_HIGH=payments-high
PAYMENTS_QUEUE=payments
```

Redis is recommended for production throughput (thousands of concurrent payments).

## Supervisor examples

### High-priority payment prompt workers

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
```

### Payment polling workers

```ini
[program:zaqa-worker-payments]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/zaqa-portal/artisan queue:work redis --queue=payments,default --sleep=2 --tries=3 --timeout=120 --max-time=3600
autostart=true
autorestart=true
numprocs=8
redirect_stderr=true
stdout_logfile=/var/log/zaqa/worker-payments.log
stopwaitsecs=130
```

### Default workers

```ini
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

After deploy:

```bash
php artisan queue:restart
php artisan schedule:run  # ensure scheduler runs cgrate.poll_due_attempts every minute
```

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
  "mobile_number": "0973936164",
  "amount_cents": 15000,
  "currency": "ZMW",
  "already_pending": false
}
```

### Poll status (every 3 seconds from UI)

`GET /applicant/payments/attempts/{attempt}/status`

Returns applicant-safe fields only. Technical gateway diagnostics are excluded.

## Callback (optional)

`POST /webhooks/cgrate/payment`

```env
CGRATE_CALLBACK_ENABLED=true
CGRATE_CALLBACK_TOKEN=your-shared-secret
CGRATE_CALLBACK_ALLOWED_IPS=203.0.113.10,203.0.113.11
```

Payload must include `payment_reference` (or `ref`) and `token` (or `X-CGrate-Callback-Token` header).

Callbacks are idempotent: terminal attempts are ignored; active attempts dispatch a high-priority poll job.

## Retry rules

- **Pending attempt exists** → reuse it, do not send a second prompt.
- **Failed/expired attempt** → new attempt with a new `payment_reference`.
- **Confirmed payment** → invoice settled; applicant UI becomes read-only.

## Failed jobs

Monitor `failed_jobs` table and logs:

```bash
php artisan queue:failed
php artisan queue:retry all
```

## Scheduler safety net

`cgrate.poll_due_attempts` runs every minute and dispatches polling jobs for due attempts (limit 50/run).

## Admin diagnostics

Finance/admin payment views retain full gateway diagnostics:

- response codes
- query attempt counts
- sanitized gateway payloads
- callback logs in `payment_webhook_logs`

These fields are intentionally hidden from applicant endpoints and the payment wizard UI.


Redis Set Up

sudo apt update
sudo apt install redis-server

sudo systemctl enable redis-server
sudo systemctl start redis-server
sudo systemctl status redis-server

redis-cli ping

sudo apt install php8.2-redis
sudo systemctl restart php8.3-fpm

QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

PAYMENTS_QUEUE_HIGH=payments-high
PAYMENTS_QUEUE=payments


command=php /var/www/html/zaqa-portal/artisan queue:work redis --queue=payments-high,payments,default --sleep=1 --tries=3 --timeout=120 --max-time=3600


sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart zaqa-worker-payments-high:*
sudo supervisorctl restart zaqa-worker-payments:*
sudo supervisorctl restart zaqa-worker-default:*


Redis as the queue backend
Supervisor to keep workers alive
payments-high for payment prompts
payments for polling
default for everything else