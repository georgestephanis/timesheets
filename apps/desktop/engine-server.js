'use strict';
// Sidecar HTTP server wrapping @timesheets/engine.
// Usage: node engine-server.js <config-path>
// On startup writes "PORT:<n>\n" to stdout so the launcher can read the port.

const {createServer} = require('http');

const configPath = process.argv[2];
if (!configPath) {
  process.stderr.write('Usage: engine-server.js <config-path>\n');
  process.exit(1);
}

(async () => {
  // Dynamic import handles the ESM engine package from a CJS wrapper.
  const {TimesheetsEngine} = await import('@timesheets/engine');
  const engine = new TimesheetsEngine(configPath);

  const server = createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');
    try {
      if (req.method === 'GET' && url.pathname === '/status') {
        json(res, 200, {ok: true});
      } else if (req.method === 'GET' && url.pathname === '/report') {
        const from = url.searchParams.get('from');
        const to = url.searchParams.get('to');
        const rebuild = url.searchParams.get('rebuild') === '1';
        if (!from || !to) {
          json(res, 400, {error: 'from and to required'});
          return;
        }
        const report = await engine.generateReport({from, to}, {rebuild});
        json(res, 200, report);
      } else if (req.method === 'POST' && url.pathname === '/reassign-signal') {
        await engine.reassignSignal(await readJson(req));
        json(res, 200, {ok: true});
      } else if (req.method === 'POST' && url.pathname === '/set-grouping') {
        await engine.setProjectGrouping(await readJson(req));
        json(res, 200, {ok: true});
      } else if (req.method === 'POST' && url.pathname === '/flag-ignored') {
        await engine.flagProjectsIgnored(await readJson(req));
        json(res, 200, {ok: true});
      } else if (
        req.method === 'POST' &&
        url.pathname === '/generate-summary'
      ) {
        const {date} = await readJson(req);
        if (!date) {
          json(res, 400, {error: 'date required'});
          return;
        }
        const summary = await engine.generateSummary(date);
        json(res, 200, {summary});
      } else {
        json(res, 404, {error: 'not found'});
      }
    } catch (err) {
      process.stderr.write(`[engine-server] ${err.message}\n`);
      json(res, 500, {error: err.message});
    }
  });

  server.listen(0, '127.0.0.1', () => {
    const {port} = server.address();
    process.stdout.write(`PORT:${port}\n`);
  });

  process.on('SIGTERM', () => {
    server.close();
    process.exit(0);
  });
  process.on('SIGINT', () => {
    server.close();
    process.exit(0);
  });
})().catch(err => {
  process.stderr.write(`[engine-server] fatal: ${err.message}\n`);
  process.exit(1);
});

function json(res, status, body) {
  const data = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(data),
  });
  res.end(data);
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    let buf = '';
    req.on('data', c => (buf += c));
    req.on('end', () => {
      try {
        resolve(JSON.parse(buf));
      } catch (e) {
        reject(e);
      }
    });
    req.on('error', reject);
  });
}
