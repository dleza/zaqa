/**
 * Shared k6 baseline configuration.
 * Override via environment variables — never hardcode credentials.
 */

export const BASE_URL = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');

export const APPLICANT_EMAIL = __ENV.APPLICANT_EMAIL || '';
export const APPLICANT_PASSWORD = __ENV.APPLICANT_PASSWORD || '';
export const ADMIN_EMAIL = __ENV.ADMIN_EMAIL || '';
export const ADMIN_PASSWORD = __ENV.ADMIN_PASSWORD || '';

export const APPLICATION_ID = __ENV.APPLICATION_ID || '';
export const QUALIFICATION_ID = __ENV.QUALIFICATION_ID || '';
export const CERTIFICATE_TOKEN = __ENV.CERTIFICATE_TOKEN || '';

/** Baseline load profile: 50 VUs, 15 minutes total with ramp. */
export const BASELINE_STAGES = [
  { duration: '2m', target: 50 },
  { duration: '11m', target: 50 },
  { duration: '2m', target: 0 },
];

export const BASELINE_THRESHOLDS = {
  http_req_failed: ['rate<0.01'],
  http_req_duration: ['p(99)<5000'],
  'http_req_duration{page:applicant_dashboard}': ['p(95)<2000'],
  'http_req_duration{page:applicant_applications}': ['p(95)<2000'],
  'http_req_duration{page:applicant_application_show}': ['p(95)<2000'],
  'http_req_duration{page:admin_assigned_to_me}': ['p(95)<2000'],
  'http_req_duration{page:admin_verification_pool}': ['p(95)<2000'],
  'http_req_duration{page:admin_qualification_show}': ['p(95)<3000'],
  'http_req_duration{page:public_certificate_verify}': ['p(95)<2000'],
  'http_req_duration{page:upload_validation}': ['p(95)<3000'],
};

export const BASELINE_OPTIONS = {
  stages: BASELINE_STAGES,
  thresholds: BASELINE_THRESHOLDS,
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
};

/** Shorter profile for smoke / upload validation checks. */
export const SMOKE_STAGES = [
  { duration: '30s', target: 1 },
];

export const UPLOAD_VALIDATION_STAGES = [
  { duration: '1m', target: 5 },
  { duration: '3m', target: 5 },
  { duration: '1m', target: 0 },
];

/**
 * Normal think time between requests (seconds).
 */
export function thinkTime(minSeconds = 3, maxSeconds = 8) {
  const min = minSeconds;
  const max = maxSeconds;
  return min + Math.random() * (max - min);
}

export function hasApplicantCredentials() {
  return APPLICANT_EMAIL !== '' && APPLICANT_PASSWORD !== '';
}

export function hasAdminCredentials() {
  return ADMIN_EMAIL !== '' && ADMIN_PASSWORD !== '';
}
