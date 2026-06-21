import { sleep } from 'k6';
import {
  BASELINE_OPTIONS,
  BASE_URL,
  ADMIN_EMAIL,
  ADMIN_PASSWORD,
  QUALIFICATION_ID,
  hasAdminCredentials,
} from '../lib/config.js';
import { login, requireCredentials } from '../lib/auth.js';
import { getPage, sleepThink } from '../lib/http.js';

export const options = {
  ...BASELINE_OPTIONS,
  tags: {
    scenario: 'baseline_admin_verification',
  },
};

export function setup() {
  if (!hasAdminCredentials()) {
    console.warn(
      'ADMIN_EMAIL / ADMIN_PASSWORD not set — iterations will fail. See tests/load/k6/README.md',
    );
    return { ready: false };
  }

  requireCredentials('ADMIN', ADMIN_EMAIL, ADMIN_PASSWORD);
  const session = login(ADMIN_EMAIL, ADMIN_PASSWORD);

  if (!session.ok) {
    console.error(`Admin login failed (HTTP ${session.status}). Check credentials and ${BASE_URL}`);
  }

  return { ready: session.ok };
}

export default function () {
  if (!hasAdminCredentials()) {
    sleep(1);
    return;
  }

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
