// Servidor HTTP mínimo que ejecuta el backend Laravel con PHP 8.4 (WebAssembly).
// Uso: node server.mjs <ruta-backend> [puerto]
import http from 'node:http';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';

const root = process.argv[2];
const port = Number(process.argv[3] || 8000);
const php = new PHP(await loadNodeRuntime('8.4', { followSymlinks: true, emscriptenOptions: { processId: 1 } }));
useHostFilesystem(php);
php.chdir(root);
const handler = new PHPRequestHandler({
  php,
  documentRoot: `${root}/public`,
  absoluteUrl: `http://127.0.0.1:${port}`,
  cookieStore: false,
  getFileNotFoundAction: () => ({ type: 'internal-redirect', uri: '/index.php' }),
});

let queue = Promise.resolve();
http.createServer((req, res) => {
  const chunks = [];
  req.on('data', (c) => chunks.push(c));
  req.on('end', () => {
    queue = queue.then(async () => {
      const t0 = Date.now();
      try {
        const headers = {};
        for (const [k, v] of Object.entries(req.headers)) headers[k] = Array.isArray(v) ? v.join(', ') : v;
        const body = chunks.length ? new Uint8Array(Buffer.concat(chunks)) : undefined;
        const r = await handler.request({ url: req.url, method: req.method, headers, body });
        const out = {};
        for (const [k, v] of Object.entries(r.headers)) out[k] = v;
        res.writeHead(r.httpStatusCode, out);
        res.end(Buffer.from(r.bytes));
        console.log(req.method, req.url, r.httpStatusCode, `${Date.now() - t0}ms`);
      } catch (e) {
        console.error('ERROR', req.method, req.url, e?.message || e);
        res.writeHead(500, { 'content-type': 'text/plain' });
        res.end(String(e?.message || e));
      }
    });
  });
}).listen(port, '127.0.0.1', () => console.log(`PHP 8.4 wasm en http://127.0.0.1:${port}`));
