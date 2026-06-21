import { sleep } from 'k6';
import {
  BASELINE_OPTIONS,
  APPLICANT_EMAIL,
  APPLICANT_PASSWORD,
  ADMIN_EMAIL,
  ADMIN_PASSWORD,
  APPLICATION_ID,
  QUALIFICATION_ID,
  CERTIFICATE_TOKEN,
  hasApplicantCredentials,
  hasAdminCredentials,
} from '../lib/config.js';
import { login } from '../lib/auth.js';
import { getPage, sleepThink } from '../lib/http.js';

export const options = {
  ...BASELINE_OPTIONS,
  tags: {
    scenario: 'baseline_mixed',
  },
};

/**
 * Weighted mix (approximate):
 * - 55% applicant browsing
 * - 25% admin verification queues
 * - 10% public certificate verify
 * - 10% idle / skipped when credentials missing
 */
export default function () {
  const roll = Math.random();

  if (roll < 0.55 && hasApplicantCredentials()) {
    runApplicantFlow();
    return;
  }

  if (roll < 0.8 && hasAdminCredentials()) {
    runAdminFlow();
    return;
  }

  if (roll < 0.9 && CERTIFICATE_TOKEN) {
    getPage(`/certificates/${CERTIFICATE_TOKEN}`, null, 'public_certificate_verify');
    sleepThink();
    return;
  }

  if (CERTIFICATE_TOKEN) {
    getPage(`/certificates/${CERTIFICATE_TOKEN}`, null, 'public_certificate_verify');
  }

  sleepThink(2, 5);
}

function runApplicantFlow() {
  const session = login(APPLICANT_EMAIL, APPLICANT_PASSWORD);
  if (!session.ok) {
    sleep(2);
    return;
  }

  const { jar } = session;

  getPage('/applicant/dashboard', jar, 'applicant_dashboard');
  sleepThink();

  getPage('/applicant/applications', jar, 'applicant_applications');
  sleepThink();

  if (APPLICATION_ID) {
    getPage(`/applicant/applications/${APPLICATION_ID}`, jar, 'applicant_application_show');
    sleepThink();
  }
}

function runAdminFlow() {
  const session = login(ADMIN_EMAIL, ADMIN_PASSWORD);
  if (!session.ok) {
    sleep(2);
    return;
  }

  const { jar } = session;

  getPage('/admin/verification/assigned-to-me', jar, 'admin_assigned_to_me');
  sleepThink();

  getPage('/admin/verification/pool', jar, 'admin_verification_pool');
  sleepThink();

  if (QUALIFICATION_ID) {
    getPage(
      `/admin/verification/qualifications/${QUALIFICATION_ID}`,
      jar,
      'admin_qualification_show',
    );
    sleepThink();
  }
}
