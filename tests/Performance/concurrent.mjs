import assert from 'node:assert/strict';
import { readFileSync, realpathSync } from 'node:fs';
import { dirname, basename } from 'node:path';
import { tmpdir } from 'node:os';
import { performance } from 'node:perf_hooks';

const root = realpathSync(process.env.FOTOARCHIEF_BENCH_ROOT);
const base = new URL(process.argv[2]);
assert.equal(process.env.FOTOARCHIEF_DISPOSABLE_BENCH, '1');
assert.equal(dirname(root), realpathSync(tmpdir()));
assert.ok(basename(root).startsWith('fotoarchief-benchmark-'));
readFileSync(`${root}/.disposable-benchmark-fixture`);
assert.equal(base.hostname, '127.0.0.1');
assert.equal(base.protocol, 'http:');
const cookies = new Map();
async function request(path, options = {}, authenticated = true) {
  const start = performance.now();
  const response = await fetch(new URL(path, base), {
    redirect: 'manual', signal: AbortSignal.timeout(30000), ...options,
    headers: { cookie: authenticated ? [...cookies].map(([key, value]) => `${key}=${value}`).join('; ') : '', ...options.headers },
  });
  for (const cookie of authenticated ? response.headers.getSetCookie() : []) {
    const pair = cookie.split(';')[0];
    const equals = pair.indexOf('=');
    cookies.set(pair.slice(0, equals), pair.slice(equals + 1));
  }
  const body = await response.text();
  return { status: response.status, body, ms: performance.now() - start,
    pid: Number(response.headers.get('x-benchmark-pid')),
    dbMs: Number(response.headers.get('x-benchmark-db-ms')),
    started: Number(response.headers.get('x-benchmark-started')),
    finished: Number(response.headers.get('x-benchmark-finished')) };
}
const login = await request('/login');
assert.equal(login.status, 200);
const token = login.body.match(/name="_token"\s+value="([^"]+)"/)?.[1];
assert.ok(token, 'Actual login CSRF token required');
assert.equal((await request('/login', { method: 'POST', body: new URLSearchParams({
  _token: token, email: 'benchmark@example.test', password: 'disposable-benchmark-password',
}) })).status, 302);
const listing = await request('/admin/assets');
assert.equal(listing.status, 200);
const detail = listing.body.match(/\/admin\/assets\/[0-9A-HJKMNP-TV-Z]{26}/)?.[0];
assert.ok(detail, 'Actual private detail link required');
const detailResponse = await request(detail);
assert.equal(detailResponse.status, 200);
const accession = detailResponse.body.match(/BENCH-[0-9]{6}/)?.[0];
assert.ok(accession, 'Actual private asset identity required');
const paths = [
  ['/admin/assets', 800, 'Historische straat'],
  ['/admin/assets?q=straat', 800, 'Historische straat'],
  [detail, 400, accession],
  ['/ontdek?q=straat&collection=benchmark-straten', 700, 'Historische straat', false],
  ['/foto/benchmark-bench-000000', 400, 'Historische straat', false],
  ['/ontdek?semantic_q=straat+met+huizen&semantic_provider=local&semantic_consent=1', 700, 'benchmark-bench-000000', false],
];
const report = [];
for (const [path, limit, marker, authenticated = true] of paths) {
  for (let warmup = 0; warmup < 8; warmup++) {
    const result = await request(path, {}, authenticated);
    assert.equal(result.status, 200);
    assert.ok(result.body.includes(marker));
  }
  const results = [];
  let cursor = 0;
  await Promise.all(Array.from({ length: 4 }, async () => {
    while (cursor++ < 40) {
      const result = await request(path, {}, authenticated);
      assert.equal(result.status, 200, `${path}: unexpected status`);
      assert.ok(result.body.includes(marker), `${path}: incorrect response body`);
      assert.ok(result.pid > 0 && result.started > 0 && result.finished >= result.started, 'Missing application timing evidence');
      results.push(result);
    }
  }));
  assert.equal(results.length, 40);
  const overlap = results.some(a => results.some(b => a.pid !== b.pid
    && Math.max(a.started, b.started) < Math.min(a.finished, b.finished)));
  assert.ok(overlap, 'No overlap between different PHP application workers; client-side concurrency alone is not proof');
  const times = results.map(result => result.ms).sort((a, b) => a - b);
  const databaseTimes = results.map(result => result.dbMs).sort((a, b) => a - b);
  report.push({ path, anonymous: !authenticated, samples: 40, concurrency: 4, php_workers: new Set(results.map(result => result.pid)).size,
    application_overlap_verified: overlap, p95_ms: Number(times[37].toFixed(2)),
    p95_db_ms: databaseTimes[37], limit_ms: limit, passed: times[37] < limit });
}
console.log(JSON.stringify({ scope: '50k-real-concurrent-http-four-owned-php-workers', records: 50000,
  synthetic: true, provider: 'deterministic-loopback-fixture', results: report }, null, 2));
if (report.some(result => !result.passed)) {
  console.error('Concurrent HTTP performance thresholds exceeded.');
  process.exitCode = 1;
}
