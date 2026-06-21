import http from 'k6/http';
import { check } from 'k6';
import { sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { BASE_URL, thinkTime } from './config.js';
import { defaultHeaders } from './auth.js';

/** Tracks response body size (bytes) per tagged page. */
export const responseSizeBytes = new Trend('response_size_bytes', true);

/**
 * Authenticated GET with page tag for threshold metrics.
 */
export function getPage(path, jar, pageTag, baseUrl = BASE_URL) {
  const url = path.startsWith('http') ? path : `${baseUrl}${path}`;

  const params = {
    headers: defaultHeaders(),
    tags: { page: pageTag },
  };

  if (jar) {
    params.jar = jar;
  }

  const response = http.get(url, params);

  if (response.body) {
    responseSizeBytes.add(response.body.length, { page: pageTag });
  }

  check(response, {
    [`${pageTag} status 200`]: (r) => r.status === 200,
    [`${pageTag} not server error`]: (r) => r.status < 500,
  });

  return response;
}

export function sleepThink(minSeconds = 3, maxSeconds = 8) {
  sleep(thinkTime(minSeconds, maxSeconds));
}
