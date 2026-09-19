import assert from 'node:assert/strict';
import { readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { dirname, basename } from 'node:path';
import { tmpdir } from 'node:os';
import { performance } from 'node:perf_hooks';

const root = realpathSync(process.env.FOTOARCHIEF_BENCH_ROOT);
const base = new URL(process.argv[2]);
assert.equal(process.env.FOTOARCHIEF_DISPOSABLE_BENCH, '1');
assert.equal(dirname(root), realpathSync(tmpdir()));
assert.ok(basename(root).startsWith('fotoarchief-benchmark-'));
readFileSync(`${root}/.disposable-benchmark-fixture`);
const budgets = JSON.parse(readFileSync(new URL('./budgets.v1.json', import.meta.url), 'utf8'));
assert.equal(budgets.schema_version, 1);
assert.equal(budgets.dataset_records, 50000);
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
  ['/admin/assets', budgets.p95_ms.private_listing, 'Historische straat'],
  ['/admin/assets?q=straat', budgets.p95_ms.private_search, 'Historische straat'],
  [detail, budgets.p95_ms.private_detail, accession],
  ['/ontdek?q=straat&collection=benchmark-straten', budgets.p95_ms.public_search, 'Historische straat', false],
  ['/foto/benchmark-bench-000000', budgets.p95_ms.public_detail, 'Historische straat', false],
  ['/ontdek?semantic_q=straat+met+huizen&semantic_provider=local&semantic_consent=1', budgets.p95_ms.semantic_search, 'benchmark-bench-000000', false],
];
const report = [];
for (const [path, limit, marker, authenticated = true] of paths) {
  for (let warmup = 0; warmup < budgets.samples.warmup; warmup++) {
    const result = await request(path, {}, authenticated);
    assert.equal(result.status, 200);
    assert.ok(result.body.includes(marker));
  }
  const results = [];
  let cursor = 0;
  await Promise.all(Array.from({ length: budgets.samples.concurrency }, async () => {
    while (cursor++ < budgets.samples.measured) {
      const result = await request(path, {}, authenticated);
      assert.equal(result.status, 200, `${path}: unexpected status`);
      assert.ok(result.body.includes(marker), `${path}: incorrect response body`);
      assert.ok(result.pid > 0 && result.started > 0 && result.finished >= result.started, 'Missing application timing evidence');
      results.push(result);
    }
  }));
  assert.equal(results.length, budgets.samples.measured);
  const overlap = results.some(a => results.some(b => a.pid !== b.pid
    && Math.max(a.started, b.started) < Math.min(a.finished, b.finished)));
  assert.ok(overlap, 'No overlap between different PHP application workers; client-side concurrency alone is not proof');
  const times = results.map(result => result.ms).sort((a, b) => a - b);
  const databaseTimes = results.map(result => result.dbMs).sort((a, b) => a - b);
  const p95Index = Math.ceil(results.length * 0.95) - 1;
  report.push({ path, anonymous: !authenticated, samples: results.length, concurrency: budgets.samples.concurrency, php_workers: new Set(results.map(result => result.pid)).size,
    application_overlap_verified: overlap, p95_ms: Number(times[p95Index].toFixed(2)),
    p95_db_ms: databaseTimes[p95Index], limit_ms: limit, passed: times[p95Index] < limit });
}
const result = { schema_version: 1, budget_contract: 'budgets.v1.json',
  scope: '50k-real-concurrent-http-four-owned-php-workers', records: budgets.dataset_records,
  synthetic: true, provider: 'deterministic-loopback-fixture', results: report };
const json = JSON.stringify(result, null, 2);
writeFileSync(`${root}/concurrent-http.json`, `${json}\n`, { flag: 'w' });
console.log(json);
if (report.some(result => !result.passed)) {
  console.error('Concurrent HTTP performance thresholds exceeded.');
  process.exitCode = 1;
}
