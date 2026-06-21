/**
 * Upload validation baseline — no mass uploads.
 * Posts a tiny PDF and an intentionally oversized file; expects validation rejection (422), not 500.
 *
 * Requires APPLICANT_EMAIL, APPLICANT_PASSWORD, APPLICATION_ID.
 */
import http from 'k6/http';
import { check, sleep } from 'k6';
import {
  BASE_URL,
  APPLICANT_EMAIL,
  APPLICANT_PASSWORD,
  APPLICATION_ID,
  UPLOAD_VALIDATION_STAGES,
  hasApplicantCredentials,
} from '../lib/config.js';
import { login, xsrfHeaders, defaultHeaders } from '../lib/auth.js';
import { responseSizeBytes } from '../lib/http.js';

const samplePdf = open('../data/files/sample_valid.pdf', 'b');

export const options = {
  stages: UPLOAD_VALIDATION_STAGES,
  thresholds: {
    http_req_failed: ['rate<0.05'],
    'http_req_duration{page:upload_validation}': ['p(95)<3000'],
  },
  tags: { scenario: 'baseline_upload_validation' },
};

export default function () {
  if (!hasApplicantCredentials() || !APPLICATION_ID) {
    sleep(1);
    return;
  }

  const session = login(APPLICANT_EMAIL, APPLICANT_PASSWORD);
  if (!session.ok) {
    sleep(2);
    return;
  }

  const { jar } = session;
  const url = `${BASE_URL}/applicant/applications/${APPLICATION_ID}/documents`;

  // 1) Valid small file — may succeed (201/302) or fail with business validation (403/422); must not 500.
  const validRes = http.post(
    url,
    {
      document_type: 'certificate_copy',
      file: http.file(samplePdf, 'baseline-sample.pdf', 'application/pdf'),
    },
    {
      jar,
      headers: {
        ...defaultHeaders(),
        ...xsrfHeaders(jar),
        Origin: BASE_URL,
        Referer: `${BASE_URL}/applicant/applications/${APPLICATION_ID}/edit`,
      },
      tags: { page: 'upload_validation' },
    },
  );

  if (validRes.body) {
    responseSizeBytes.add(validRes.body.length, { page: 'upload_validation' });
  }

  check(validRes, {
    'valid upload not server error': (r) => r.status < 500,
  });

  sleep(3 + Math.random() * 5);

  // 2) Oversized file (~3.1 MB) — expect 422 validation, not 500.
  const oversized = new Uint8Array(Math.floor(3.1 * 1024 * 1024));
  oversized.fill(0x25); // PDF-ish bytes prefix optional; mime check runs first

  const oversizeRes = http.post(
    url,
    {
      document_type: 'certificate_copy',
      file: http.file(oversized.buffer, 'oversized.pdf', 'application/pdf'),
    },
    {
      jar,
      headers: {
        ...defaultHeaders(),
        ...xsrfHeaders(jar),
        Origin: BASE_URL,
        Referer: `${BASE_URL}/applicant/applications/${APPLICATION_ID}/edit`,
      },
      tags: { page: 'upload_validation' },
    },
  );

  check(oversizeRes, {
    'oversized upload rejected (422 or 413)': (r) => r.status === 422 || r.status === 413,
    'oversized upload not server error': (r) => r.status < 500,
  });

  sleep(5 + Math.random() * 5);
}
