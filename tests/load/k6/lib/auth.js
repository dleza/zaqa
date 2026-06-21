import http from 'k6/http';
import { check, fail } from 'k6';
import { BASE_URL } from './config.js';

/**
 * Build X-XSRF-TOKEN header from Laravel's XSRF-TOKEN cookie.
 */
export function xsrfHeaders(jar, url = BASE_URL) {
  const cookies = jar.cookiesForURL(url);

  const raw =
    cookies['XSRF-TOKEN']?.[0] ||
    cookies['xsrf-token']?.[0] ||
    cookies['XSRF-TOKEN'.toLowerCase()]?.[0];

  if (!raw) {
    return {};
  }

  try {
    return { 'X-XSRF-TOKEN': decodeURIComponent(raw) };
  } catch (_) {
    return { 'X-XSRF-TOKEN': raw };
  }
}

export function defaultHeaders(extra = {}) {
  return {
    Accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language': 'en',
    ...extra,
  };
}

/**
 * Log in via POST /login and return a cookie jar with an authenticated session.
 */
export function login(identifier, password, baseUrl = BASE_URL) {
  const jar = http.cookieJar();

  const loginPage = http.get(`${baseUrl}/login`, {
    jar,
    headers: defaultHeaders(),
    tags: { page: 'login_form' },
  });

  const pageOk = check(loginPage, {
    'login page status 200': (r) => r.status === 200,
  });

  if (!pageOk) {
    return { jar, ok: false, status: loginPage.status };
  }

  const payload = {
    identifier,
    password,
    remember: '0',
  };

  const response = http.post(`${baseUrl}/login`, payload, {
    jar,
    headers: {
      ...defaultHeaders({
        'Content-Type': 'application/x-www-form-urlencoded',
        Origin: baseUrl,
        Referer: `${baseUrl}/login`,
      }),
      ...xsrfHeaders(jar, baseUrl),
    },
    redirects: 0,
    tags: { page: 'login_submit' },
  });

  const ok = check(response, {
    'login redirects after success': (r) => r.status === 302 || r.status === 303,
    'login not validation error': (r) => r.status !== 422 && r.status !== 419,
  });

  return { jar, ok, status: response.status, location: response.headers.Location || '' };
}

/**
 * Fail fast when required credentials are missing.
 */
export function requireCredentials(label, email, password) {
  if (!email || !password) {
    fail(`${label} credentials missing. Set ${label}_EMAIL and ${label}_PASSWORD environment variables.`);
  }
}
