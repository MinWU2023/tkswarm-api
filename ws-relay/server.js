/**
 * TkSwarm 租约 WSS 中继（与 PHP 同机运行，Nginx 反代 wss://.../ws-relay → 127.0.0.1:8998/ws）
 *
 * - 客户端（node / linuxbrowse）主动连入：/ws?ticket=...，ticket 由 PHP 签发（HMAC），只含 hostId 与过期时间。
 * - PHP 在「上传环境包 + 释放占用」后 POST /notify，中继只推给 FIFO 队首所在主机。
 * - 中继不保存队列，队列与顺序以 PHP 数据库 pack_wait_queue 为准；客户端重连时会重新入队补漏。
 *
 * 环境变量：
 *   TKSWARM_RELAY_SECRET  必填，PHP 与中继共用（签 ticket + 校验 /notify）
 *   TKSWARM_RELAY_PORT    默认 8998
 *   TKSWARM_RELAY_HOST    默认 127.0.0.1
 */
const http = require('node:http');
const crypto = require('node:crypto');
const { WebSocketServer } = require('ws');

const PORT = Number(process.env.TKSWARM_RELAY_PORT || 8998);
const HOST = process.env.TKSWARM_RELAY_HOST || '127.0.0.1';
const SECRET = String(process.env.TKSWARM_RELAY_SECRET || '').trim();
if (!SECRET) {
  console.error('[relay] 缺少 TKSWARM_RELAY_SECRET，拒绝启动');
  process.exit(1);
}

/** hostId -> Set<WebSocket> */
const hosts = new Map();

function b64urlDecode(s) {
  return Buffer.from(String(s).replace(/-/g, '+').replace(/_/g, '/'), 'base64').toString('utf8');
}

/** ticket = base64url(JSON{h,e}).hex(hmac_sha256(payload)) */
function verifyTicket(ticket) {
  const [payload, sig] = String(ticket || '').split('.');
  if (!payload || !sig) return null;
  const expect = crypto.createHmac('sha256', SECRET).update(payload).digest('hex');
  const a = Buffer.from(sig);
  const b = Buffer.from(expect);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;
  try {
    const j = JSON.parse(b64urlDecode(payload));
    if (!j?.h || !j?.e || Number(j.e) < Math.floor(Date.now() / 1000)) return null;
    return String(j.h);
  } catch {
    return null;
  }
}

function pushToHost(hostId, message) {
  const set = hosts.get(hostId);
  if (!set) return 0;
  const raw = JSON.stringify(message);
  let n = 0;
  for (const ws of set) {
    if (ws.readyState === 1) {
      ws.send(raw);
      n += 1;
    }
  }
  return n;
}

const server = http.createServer((req, res) => {
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({ ok: true, hosts: hosts.size }));
  }
  if (req.method === 'POST' && req.url === '/notify') {
    if (req.headers['x-relay-secret'] !== SECRET) {
      res.writeHead(401);
      return res.end('unauthorized');
    }
    let body = '';
    req.on('data', (c) => { body += c; if (body.length > 64 * 1024) req.destroy(); });
    req.on('end', () => {
      let j = {};
      try { j = JSON.parse(body || '{}'); } catch { /* ignore */ }
      const hostId = String(j.hostId || '').trim();
      if (!hostId || !j.profileId) {
        res.writeHead(400);
        return res.end('bad request');
      }
      const delivered = pushToHost(hostId, {
        type: 'pack-lease-ready',
        data: {
          profileId: String(j.profileId),
          hostId,
          releaseSeq: Number(j.releaseSeq || 0),
          queueSize: Number(j.queueSize || 0),
        },
      });
      console.log(`[relay] notify profile=${j.profileId} -> ${hostId} delivered=${delivered}`);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, delivered }));
    });
    return undefined;
  }
  res.writeHead(404);
  return res.end('not found');
});

const wss = new WebSocketServer({ noServer: true });
server.on('upgrade', (req, socket, head) => {
  let hostId = null;
  try {
    const u = new URL(req.url, 'http://relay');
    if (u.pathname !== '/ws') throw new Error('path');
    hostId = verifyTicket(u.searchParams.get('ticket'));
  } catch { /* ignore */ }
  if (!hostId) {
    socket.write('HTTP/1.1 401 Unauthorized\r\n\r\n');
    return socket.destroy();
  }
  return wss.handleUpgrade(req, socket, head, (ws) => {
    ws.hostId = hostId;
    ws.isAlive = true;
    if (!hosts.has(hostId)) hosts.set(hostId, new Set());
    hosts.get(hostId).add(ws);
    ws.on('pong', () => { ws.isAlive = true; });
    ws.on('close', () => {
      const set = hosts.get(hostId);
      if (set) {
        set.delete(ws);
        if (!set.size) hosts.delete(hostId);
      }
    });
    ws.on('error', () => {});
    ws.send(JSON.stringify({ type: 'hello', data: { hostId } }));
  });
});

// 仅用于清理死连接，不是业务轮询
setInterval(() => {
  for (const set of hosts.values()) {
    for (const ws of set) {
      if (!ws.isAlive) { ws.terminate(); continue; }
      ws.isAlive = false;
      try { ws.ping(); } catch { /* ignore */ }
    }
  }
}, 30000).unref();

server.listen(PORT, HOST, () => {
  console.log(`[relay] listening ${HOST}:${PORT}`);
});
