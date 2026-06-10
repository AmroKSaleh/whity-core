// WC-32 k6 smoke / baseline load test for whity-core.
//
// Exercises three representative paths against the staging stack:
//   1. GET  /api/health                 (public, dependency-light)
//   2. POST /api/login -> GET /api/admin/stats   (auth flow via httpOnly cookie)
//   3. GET  /api/roles                  (RBAC-gated: requires `admin` role)
//
// k6 keeps a per-VU cookie jar, so the cookie set by /api/login is sent
// automatically on the subsequent authenticated requests within the same VU.
//
// Run (zero repo dependency — Grafana k6 Docker image):
//   docker run --rm -i --network=whity-staging_default \
//     -e BASE_URL=http://frankenphp:80 \
//     -e ADMIN_EMAIL=admin@example.com -e ADMIN_PASSWORD=... \
//     -e VUS=20 -e DURATION=30s \
//     grafana/k6 run - < load-tests/smoke.js
//
// Or via the runner:  ./load-tests/run.sh
//
// Env knobs (all optional, with sensible defaults):
//   BASE_URL        base URL of the target (default http://frankenphp:80)
//   ADMIN_EMAIL     seeded admin login (default admin@example.com)
//   ADMIN_PASSWORD  seeded admin password (default admin123 — override for staging)
//   VUS             virtual users (default 10)
//   DURATION        test duration, e.g. 30s / 2m (default 30s)

import http from 'k6/http';
import { check, group, fail } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://frankenphp:80';
const ADMIN_EMAIL = __ENV.ADMIN_EMAIL || 'admin@example.com';
const ADMIN_PASSWORD = __ENV.ADMIN_PASSWORD || 'admin123';
const VUS = parseInt(__ENV.VUS || '10', 10);
const DURATION = __ENV.DURATION || '30s';

// Custom metrics so the summary breaks results down per scenario.
const healthFail = new Rate('health_failed');
const authFail = new Rate('auth_flow_failed');
const rbacFail = new Rate('rbac_failed');
const loginDuration = new Trend('login_duration', true);

export const options = {
  scenarios: {
    mixed: {
      executor: 'constant-vus',
      vus: VUS,
      duration: DURATION,
    },
  },
  thresholds: {
    // Fail the run if errors or latency blow past these. Tune for your hardware.
    http_req_failed: ['rate<0.01'],          // < 1% HTTP errors overall
    http_req_duration: ['p(95)<800'],        // p95 < 800ms overall
    health_failed: ['rate<0.01'],
    auth_flow_failed: ['rate<0.01'],
    rbac_failed: ['rate<0.01'],
  },
};

// One authenticated login per iteration start would be wasteful; instead each VU
// logs in once on first iteration and reuses its cookie jar for the rest.
export function setup() {
  // Sanity-check the target is reachable before the load starts.
  const res = http.get(`${BASE_URL}/api/health`);
  if (res.status !== 200) {
    fail(`target not healthy at ${BASE_URL}/api/health (status ${res.status})`);
  }
  return {};
}

export default function () {
  // 1. Public health check.
  group('health', function () {
    const res = http.get(`${BASE_URL}/api/health`);
    const ok = check(res, {
      'health 200': (r) => r.status === 200,
      'health db_connected': (r) => {
        try { return r.json('db_connected') === true; } catch (e) { return false; }
      },
    });
    healthFail.add(!ok);
  });

  // 2. Auth flow: login (sets httpOnly cookie) -> authenticated admin stats.
  group('auth_flow', function () {
    const loginRes = http.post(
      `${BASE_URL}/api/login`,
      JSON.stringify({ email: ADMIN_EMAIL, password: ADMIN_PASSWORD }),
      {
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on the auth POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
      }
    );
    loginDuration.add(loginRes.timings.duration);

    const loggedIn = check(loginRes, { 'login 200': (r) => r.status === 200 });

    // The k6 cookie jar carries the access_token cookie into this request.
    const statsRes = http.get(`${BASE_URL}/api/admin/stats`);
    const statsOk = check(statsRes, { 'admin/stats 200': (r) => r.status === 200 });

    authFail.add(!(loggedIn && statsOk));
  });

  // 3. RBAC-gated route (admin-only). Reuses the same authenticated cookie jar.
  group('rbac', function () {
    const res = http.get(`${BASE_URL}/api/roles`);
    const ok = check(res, { 'roles 200': (r) => r.status === 200 });
    rbacFail.add(!ok);
  });
}
