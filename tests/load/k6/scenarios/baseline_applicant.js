import { sleep } from 'k6';
import {
  BASELINE_OPTIONS,
  BASE_URL,
  APPLICANT_EMAIL,
  APPLICANT_PASSWORD,
  APPLICATION_ID,
  hasApplicantCredentials,
} from '../lib/config.js';
import { login, requireCredentials } from '../lib/auth.js';
import { getPage, sleepThink } from '../lib/http.js';

export const options = {
  ...BASELINE_OPTIONS,
  tags: {
    scenario: 'baseline_applicant',
  },
};

export function setup() {
  if (!hasApplicantCredentials()) {
    console.warn(
      'APPLICANT_EMAIL / APPLICANT_PASSWORD not set — iterations will fail. See tests/load/k6/README.md',
    );
    return { ready: false };
  }

  requireCredentials('APPLICANT', APPLICANT_EMAIL, APPLICANT_PASSWORD);
  const session = login(APPLICANT_EMAIL, APPLICANT_PASSWORD);

  if (!session.ok) {
    console.error(`Applicant login failed (HTTP ${session.status}). Check credentials and ${BASE_URL}`);
  }

  return { ready: session.ok };
}

export default function () {
  if (!hasApplicantCredentials()) {
    sleep(1);
    return;
  }

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
