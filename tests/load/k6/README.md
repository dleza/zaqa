# ZAQA Portal — k6 Baseline Load Tests

Scriptable HTTP baseline load tests for **staging or local** environments only.

**Do not run against production.**

## Prerequisites

- [k6](https://k6.io/docs/get-started/installation/) v0.47+ (or use `./bin/k6` if downloaded locally)
- Target environment running with frontend built (`npm run build`)
- Test users with appropriate roles (activated accounts)

## Quick install (k6)

```bash
# Option A: official package (recommended)
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" \
  | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update && sudo apt-get install k6

# Option B: project-local binary (already supported)
./bin/k6 version
```

## Environment variables

Export these before running tests. **Never commit real credentials.**

| Variable | Required | Description |
|----------|----------|-------------|
| `BASE_URL` | No | Default `http://127.0.0.1:8000` |
| `APPLICANT_EMAIL` | Applicant scenarios | Activated individual/institution applicant |
| `APPLICANT_PASSWORD` | Applicant scenarios | Applicant password |
| `ADMIN_EMAIL` | Admin scenarios | Staff user with `verification.pool.view` |
| `ADMIN_PASSWORD` | Admin scenarios | Admin password |
| `APPLICATION_ID` | Optional | Draft application ID for show/upload tests |
| `QUALIFICATION_ID` | Optional | Qualification ID for admin show page |
| `CERTIFICATE_TOKEN` | Optional | Public certificate verify token |

### Example (local)

```bash
export BASE_URL=http://127.0.0.1:8000
export APPLICANT_EMAIL=applicant@example.test
export APPLICANT_PASSWORD=your-password
export ADMIN_EMAIL=superadmin@zaqa.gov.zm
export ADMIN_PASSWORD=your-password
export APPLICATION_ID=1
export QUALIFICATION_ID=1
export CERTIFICATE_TOKEN=your-public-token
```

Find IDs in staging (examples):

```bash
php artisan tinker --execute="echo App\Models\Application::query()->value('id');"
php artisan tinker --execute="echo App\Models\Qualification::query()->value('id');"
```

## Baseline profile

All main scenarios use:

| Setting | Value |
|---------|--------|
| Virtual users | 50 (ramp 2m → sustain 11m → ramp-down 2m) |
| Duration | **15 minutes** |
| Think time | 3–8 seconds between requests |
| Payments | **None** |
| Mass uploads | **None** (upload scenario validates rejection only) |

## Scenarios

| Script | Flow |
|--------|------|
| `baseline_applicant.js` | Login → dashboard → applications list → optional application show |
| `baseline_admin_verification.js` | Login → assigned-to-me → verification pool → optional qualification show |
| `baseline_mixed.js` | Weighted mix: applicant (55%), admin (25%), public cert (10%) |
| `baseline_upload_validation.js` | Small PDF + oversized upload validation (5 VUs, 5 min) |

## Run commands

From repository root:

```bash
# Full 15-minute baseline
k6 run tests/load/k6/scenarios/baseline_applicant.js
k6 run tests/load/k6/scenarios/baseline_admin_verification.js
k6 run tests/load/k6/scenarios/baseline_mixed.js

# Upload validation (lighter load, requires APPLICATION_ID)
k6 run tests/load/k6/scenarios/baseline_upload_validation.js
```

Save results:

```bash
mkdir -p tests/load/k6/results
k6 run --summary-export=tests/load/k6/results/baseline_applicant.json \
  tests/load/k6/scenarios/baseline_applicant.js
```

### Smoke test (30 seconds, 1 VU)

Validate scripts before a full baseline:

```bash
k6 run --vus 1 --duration 30s tests/load/k6/scenarios/baseline_admin_verification.js
```

## Thresholds

| Metric | Target |
|--------|--------|
| `http_req_failed` | < 1% |
| `http_req_duration{page:applicant_dashboard}` p95 | < 2000 ms |
| `http_req_duration{page:admin_assigned_to_me}` p95 | < 2000 ms |
| `http_req_duration{page:admin_verification_pool}` p95 | < 2000 ms |
| `http_req_duration{page:admin_qualification_show}` p95 | < 3000 ms |

k6 prints **p50, p90, p95, p99** in the summary. Use `--summary-export` for JSON.

## Metrics to capture

During each run, record:

1. **http_req_duration** — p50, p95, p99 (overall and per `page` tag)
2. **http_req_failed** — error rate
3. **http_reqs** — throughput (requests/s)
4. **response_size_bytes** — Inertia/HTML payload size per page
5. **checks** — pass/fail counts per endpoint
6. Server-side (parallel): PHP-FPM busy workers, MySQL slow log, queue depth

### Identifying slow endpoints

Filter k6 summary by `page` tag:

- `applicant_dashboard`
- `applicant_applications`
- `applicant_application_show`
- `admin_assigned_to_me`
- `admin_verification_pool`
- `admin_qualification_show`
- `public_certificate_verify`
- `upload_validation`

## Folder layout

```text
tests/load/k6/
  README.md
  lib/
    config.js       # BASE_URL, thresholds, stages
    auth.js         # Laravel session login + CSRF
    http.js         # Tagged GET helper + payload metric
  scenarios/
    baseline_applicant.js
    baseline_admin_verification.js
    baseline_mixed.js
    baseline_upload_validation.js
  data/
    files/
      sample_valid.pdf
  results/          # gitignored — JSON summaries
```

## Troubleshooting

| Issue | Likely cause |
|-------|----------------|
| Login returns 419 | CSRF/session — ensure `BASE_URL` matches server host |
| Login returns 422 | Wrong credentials or rate limit (5 attempts/min per identifier) |
| Redirect to `/activate` | User not activated — use `is_active` account |
| Admin 403 | User lacks `verification.pool.view` permission |
| Application show 404 | Wrong `APPLICATION_ID` or not owned by applicant |
| Qualification show 404 | Wrong `QUALIFICATION_ID` or access restricted |

## Safety rules

- Do not target production URLs
- Do not run payment initiation scenarios in baseline
- Do not load-test real CyberSource / cGrate endpoints
- Upload validation posts at most 2 files per iteration (small + oversized); oversized must be rejected

See also: [docs/PERFORMANCE_LOAD_RESILIENCE_TEST_PLAN.md](../../docs/PERFORMANCE_LOAD_RESILIENCE_TEST_PLAN.md)
