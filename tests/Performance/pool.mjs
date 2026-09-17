import http from 'node:http';
import { readFileSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, basename } from 'node:path';

const file = realpathSync(process.argv[2]);
const config = JSON.parse(readFileSync(file, 'utf8'));
if (process.env.FOTOARCHIEF_DISPOSABLE_BENCH !== '1'
    || dirname(file) !== realpathSync(process.env.FOTOARCHIEF_BENCH_ROOT)
    || dirname(dirname(file)) !== realpathSync(tmpdir())
    || realpathSync(config.root) !== dirname(file)
    || !basename(dirname(file)).startsWith('fotoarchief-benchmark-')
    || config.workers.length !== 4) throw new Error('Owned four-worker disposable fixture required');
readFileSync(`${config.root}/.disposable-benchmark-fixture`);
for (const value of [config.url, ...config.workers]) {
  const url = new URL(value);
  if (url.hostname !== '127.0.0.1' || url.protocol !== 'http:' || !url.port) throw new Error('Loopback only');
}
let next = 0;
const server = http.createServer(async (request, response) => {
  if (request.url === '/__fixture_health') return response.end('disposable benchmark');
  if (request.url === '/v1/embed-text' && request.method === 'POST') {
    try {
      const chunks = [];
      let size = 0;
      for await (const chunk of request) {
        size += chunk.length;
        if (size > 4096) throw new Error('Fixture request exceeds 4096 bytes');
        chunks.push(chunk);
      }
      const body = JSON.parse(Buffer.concat(chunks).toString());
      const slots = { 'straat met huizen': 0, 'schepen in de haven': 1, 'markt op het dorpsplein': 2 };
      if (!(body.text in slots) || body.language !== 'nl') throw new Error('Unrecognized deterministic fixture query');
      const mode = readFileSync(`${config.root}/provider-mode`, 'utf8');
      if (!['normal', 'wrong-ranking'].includes(mode)) throw new Error('Unknown provider fixture mode');
      const embedding = Array(384).fill(0);
      embedding[(slots[body.text] + (mode === 'wrong-ranking' ? 1 : 0)) % 3] = 1;
      response.writeHead(200, { 'content-type': 'application/json' });
      return response.end(JSON.stringify({ embedding, dimensions: 384, model_space: 'synthetic-benchmark-384' }));
    } catch (error) {
      console.error(error);
      response.writeHead(500);
      return response.end('Explicit provider fixture failure');
    }
  }
  const worker = new URL(config.workers[next++ % config.workers.length]);
  const upstream = http.request({
    hostname: worker.hostname, port: worker.port, path: request.url, method: request.method,
    headers: { ...request.headers, host: new URL(config.url).host },
    timeout: 30000,
  }, incoming => {
    response.writeHead(incoming.statusCode, incoming.headers);
    incoming.pipe(response);
  });
  upstream.on('timeout', () => upstream.destroy(new Error('Owned PHP worker timed out')));
  upstream.on('error', error => {
    console.error(error);
    if (!response.headersSent) response.writeHead(502);
    response.end('Owned PHP worker failed');
  });
  request.pipe(upstream);
});
server.listen(Number(new URL(config.url).port), '127.0.0.1');
