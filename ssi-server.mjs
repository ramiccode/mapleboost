// Minimal SSI preview server
// Usage: node ssi-server.mjs [port]
// Processes <!--#include virtual="..." --> directives

import http from 'http';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const ROOT = process.argv[2] || '/sessions/practical-focused-mayer/mnt/MapleBoost/public_html';
const PORT = parseInt(process.argv[3] || '8080');

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css':  'text/css',
  '.js':   'application/javascript',
  '.svg':  'image/svg+xml',
  '.png':  'image/png',
  '.jpg':  'image/jpeg',
  '.webp': 'image/webp',
  '.ico':  'image/x-icon',
  '.woff2':'font/woff2',
};

function processSSI(content, filePath) {
  return content.replace(/<!--#include virtual="([^"]+)"\s*-->/g, (_, virtual) => {
    // virtual is an absolute path like /inc/nav.html
    const inc = path.join(ROOT, virtual);
    try {
      const src = fs.readFileSync(inc, 'utf8');
      return processSSI(src, inc); // recursive
    } catch {
      return `<!-- SSI: could not include ${virtual} -->`;
    }
  });
}

function resolve(urlPath) {
  // strip query string
  urlPath = urlPath.split('?')[0];
  // trailing slash → index.html
  if (urlPath.endsWith('/')) urlPath += 'index';

  const candidates = [
    path.join(ROOT, urlPath),
    path.join(ROOT, urlPath + '.html'),
    path.join(ROOT, urlPath + '.php'),  // for contact, go, etc.
  ];

  for (const c of candidates) {
    if (fs.existsSync(c) && fs.statSync(c).isFile()) return c;
  }
  // directory with index
  const idx = path.join(ROOT, urlPath, 'index.html');
  if (fs.existsSync(idx)) return idx;
  return null;
}

http.createServer((req, res) => {
  const file = resolve(req.url);

  if (!file) {
    const err404 = path.join(ROOT, '404.html');
    if (fs.existsSync(err404)) {
      const html = processSSI(fs.readFileSync(err404, 'utf8'), err404);
      res.writeHead(404, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(html);
    } else {
      res.writeHead(404); res.end('Not found');
    }
    return;
  }

  const ext = path.extname(file).toLowerCase();
  const mime = MIME[ext] || 'application/octet-stream';

  try {
    if (ext === '.html') {
      const raw = fs.readFileSync(file, 'utf8');
      const html = processSSI(raw, file);
      res.writeHead(200, { 'Content-Type': mime });
      res.end(html);
    } else {
      const data = fs.readFileSync(file);
      res.writeHead(200, { 'Content-Type': mime });
      res.end(data);
    }
  } catch (e) {
    res.writeHead(500); res.end('Error: ' + e.message);
  }
}).listen(PORT, () => {
  console.log(`MapleBoost preview → http://localhost:${PORT}`);
  console.log(`Serving: ${ROOT}`);
  console.log('Ctrl+C to stop.');
});
