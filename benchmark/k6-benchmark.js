import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// Custom metric definitions
export const errorRate = new Rate('api_errors');
export const authLatency = new Trend('auth_latency');
export const profileLatency = new Trend('profile_latency');

export const options = {
  stages: [
    { duration: '10s', target: 20 },  // Ramp up to 20 virtual users
    { duration: '30s', target: 50 },  // Stay at 50 virtual users
    { duration: '10s', target: 0 },   // Ramp down
  ],
  thresholds: {
    http_req_duration: ['p(95)<300', 'p(99)<500'], // 95% of requests must finish within 300ms
    api_errors: ['rate<0.02'],                     // Error rate must stay below 2%
  },
};

const BASE_URL = __ENV.TARGET_URL || 'http://localhost:8080';

export default function () {
  const uniqueId = `${__VU}_${__ITER}_${Date.now()}`;
  const userPayload = JSON.stringify({
    name: `User ${uniqueId}`,
    email: `loadtest_${uniqueId}@example.com`,
    password: 'password123456!',
    password_confirmation: 'password123456!',
  });

  const jsonHeaders = {
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
  };

  group('01. Health Check', function () {
    const res = http.get(`${BASE_URL}/api/health`);
    const passed = check(res, {
      'health status is 200 or 503': (r) => r.status === 200 || r.status === 503,
      'health returns json': (r) => r.headers['Content-Type'] && r.headers['Content-Type'].includes('json'),
    });
    errorRate.add(!passed);
  });

  let token = null;

  group('02. User Registration', function () {
    const res = http.post(`${BASE_URL}/api/v1/auth/register`, userPayload, jsonHeaders);
    authLatency.add(res.timings.duration);

    const passed = check(res, {
      'registered status is 201': (r) => r.status === 201,
      'token is returned': (r) => {
        try {
          const body = JSON.parse(r.body);
          if (body.data && body.data.token) {
            token = body.data.token;
            return true;
          }
        } catch (e) {}
        return false;
      },
    });
    errorRate.add(!passed);
  });

  if (token) {
    group('03. Authenticated Profile Request', function () {
      const authHeaders = {
        headers: {
          'Accept': 'application/json',
          'Authorization': `Bearer ${token}`,
        },
      };

      const res = http.get(`${BASE_URL}/api/v1/auth/me`, authHeaders);
      profileLatency.add(res.timings.duration);

      const passed = check(res, {
        'profile status is 200': (r) => r.status === 200,
        'profile returns user email': (r) => {
          try {
            const body = JSON.parse(r.body);
            return body.data && body.data.email !== undefined;
          } catch (e) {}
          return false;
        },
      });
      errorRate.add(!passed);
    });

    group('04. Logout & Token Revocation', function () {
      const authHeaders = {
        headers: {
          'Accept': 'application/json',
          'Authorization': `Bearer ${token}`,
        },
      };

      const res = http.post(`${BASE_URL}/api/v1/auth/logout`, null, authHeaders);
      const passed = check(res, {
        'logout status is 200': (r) => r.status === 200,
      });
      errorRate.add(!passed);
    });
  }

  group('05. Scrape Metrics', function () {
    const res = http.get(`${BASE_URL}/api/metrics`);
    const passed = check(res, {
      'metrics status is 200': (r) => r.status === 200,
      'contains prometheus format': (r) => r.body.includes('http_requests_total'),
    });
    errorRate.add(!passed);
  });

  sleep(0.5);
}
