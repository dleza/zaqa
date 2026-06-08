# Outbound Email & SMS — Queued Delivery

## Overview

All production email and SMS notifications are dispatched through background queues so HTTP requests return quickly. Delivery failures are logged and **never break** the calling workflow.

## Central services

| Service | Purpose |
|---------|---------|
| `OutboundMailService` | Queue mailables + `email_logs` audit |
| `OutboundSmsService` | Send SMS + `sms_logs` audit |

Both services catch exceptions, log warnings, and return `true`/`false` instead of throwing.

## Queues

| Queue | Env variable | Used for |
|-------|--------------|----------|
| `notifications` | `NOTIFICATIONS_MAIL_QUEUE` | Laravel mail jobs (`SendQueuedMailable`) |
| `notifications` | `NOTIFICATIONS_SMS_QUEUE` | Reserved for future dedicated SMS jobs |
| `default` | `NOTIFICATIONS_LISTENER_QUEUE` | Event listeners (`Send*Notification`) |

```env
NOTIFICATIONS_MAIL_QUEUE=notifications
NOTIFICATIONS_SMS_QUEUE=notifications
NOTIFICATIONS_LISTENER_QUEUE=default
QUEUE_CONNECTION=redis
```

## Supervisor example

```ini
[program:zaqa-worker-notifications]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/zaqa-portal/artisan queue:work redis --queue=notifications,default --sleep=2 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/zaqa/worker-notifications.log
```

After deploy: `php artisan queue:restart`

## Covered flows

All event listeners under `app/Domain/*/Listeners/Send*.php` are queued and use the central services:

- Account activation email + OTP SMS
- Application submitted / resubmitted
- Verification assignment, level-1 complete, send-back
- Finance payment proof submitted / approved / rejected
- Qualification certificate issued (queued from service)
- Institution API token email (queued from admin action)
- Password reset (queued notification on `User` model)

## Audit logs

- `email_logs.status`: `queued` when handed to mail queue, `failed` on dispatch error
- `sms_logs.status`: `sent` when provider accepts, `failed` on error

## SMS provider

Local/dev uses `SMS_PROVIDER=log` (writes to Laravel log). Production gateways plug into `OutboundSmsService::dispatchToProvider()`.

## Failure handling

- Listener jobs complete even if mail/SMS dispatch fails
- Failed entries appear in `email_logs` / `sms_logs`
- Monitor `failed_jobs` for mail worker issues: `php artisan queue:failed`
