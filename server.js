import express from 'express';
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';
import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const {
  WEBHOOK_URL,
  WEBHOOK_SECRET = '',
  ADMIN_PASSWORD,
  ENCRYPTION_KEY,
  DATA_DIR = './data',
  INCLUDE_ATTACHMENTS = 'false',
  PORT = 3000,
} = process.env;

if (!WEBHOOK_URL || !ADMIN_PASSWORD) {
  console.error('WEBHOOK_URL এবং ADMIN_PASSWORD environment variable দিতে হবে।');
  process.exit(1);
}

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const FILE = path.join(DATA_DIR, 'accounts.json');
const MAX_ACCOUNTS = 20;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------- encryption (app passwords disk-এ encrypted থাকে) ----------
const KEY = crypto.createHash('sha256').update(ENCRYPTION_KEY || ADMIN_PASSWORD).digest();
const enc = (t) => {
  const iv = crypto.randomBytes(12);
  const c = crypto.createCipheriv('aes-256-gcm', KEY, iv);
  const d = Buffer.concat([c.update(t, 'utf8'), c.final()]);
  return [iv, c.getAuthTag(), d].map((b) => b.toString('base64')).join('.');
};
const dec = (s) => {
  const [iv, tag, d] = s.split('.').map((x) => Buffer.from(x, 'base64'));
  const c = crypto.createDecipheriv('aes-256-gcm', KEY, iv);
  c.setAuthTag(tag);
  return Buffer.concat([c.update(d), c.final()]).toString('utf8');
};

// ---------- storage ----------
fs.mkdirSync(DATA_DIR, { recursive: true });
let accounts = [];
try { accounts = JSON.parse(fs.readFileSync(FILE, 'utf8')); } catch {}
const save = () => fs.writeFileSync(FILE, JSON.stringify(accounts, null, 2));

// ---------- webhook ----------
async function post(body) {
  for (let i = 1; i <= 4; i++) {
    try {
      const r = await fetch(WEBHOOK_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Webhook-Secret': WEBHOOK_SECRET },
        body: JSON.stringify(body),
        signal: AbortSignal.timeout(20000),
      });
      if (r.ok) return;
      throw new Error('HTTP ' + r.status);
    } catch (e) {
      if (i === 4) throw e;
      await sleep(1000 * 2 ** i);
    }
  }
}

const deliver = (email, uid, p) =>
  post({
    type: 'email',
    account: email,
    uid,
    messageId: p.messageId || null,
    from: p.from?.text || null,
    fromAddress: p.from?.value?.[0]?.address || null,
    to: p.to?.text || null,
    subject: p.subject || null,
    date: p.date || null,
    text: p.text || null,
    html: p.html || null,
    attachments: (p.attachments || []).map((a) => ({
      filename: a.filename,
      contentType: a.contentType,
      size: a.size,
      ...(INCLUDE_ATTACHMENTS === 'true' ? { contentBase64: a.content.toString('base64') } : {}),
    })),
  });

// ---------- Gmail watcher (IMAP IDLE = প্রায় real-time) ----------
class Watcher {
  constructor(acc) {
    this.acc = acc;
    this.status = 'connecting';
    this.error = null;
    this.lastMail = null;
    this.count = 0;
    this.stopped = false;
    this.client = null;
    this.retry = 0;
    this.busy = false;
    this.again = false;
    this.start();
  }

  async start() {
    while (!this.stopped) {
      let wait = Math.min(60000, 2000 * 2 ** Math.min(this.retry, 5));
      try {
        await this.run();
      } catch (e) {
        this.error = e.responseText || e.message;
        if (e.authenticationFailed) { this.status = 'auth_failed'; wait = 300000; }
      }
      if (this.stopped) break;
      if (this.status !== 'auth_failed') this.status = 'reconnecting';
      this.retry++;
      await sleep(wait);
    }
  }

  async run() {
    const client = new ImapFlow({
      host: 'imap.gmail.com',
      port: 993,
      secure: true,
      auth: { user: this.acc.email, pass: dec(this.acc.pass) },
      logger: false,
    });
    this.client = client;
    client.on('error', (e) => { this.error = e.message; });
    await client.connect();
    const lock = await client.getMailboxLock('INBOX');
    try {
      this.status = 'connected';
      this.error = null;
      this.retry = 0;
      if (!this.acc.lastUid) { // প্রথমবার: পুরনো mail forward করবে না
        this.acc.lastUid = client.mailbox.uidNext - 1;
        save();
      }
      await this.fetchNew(client);
      client.on('exists', () => this.fetchNew(client).catch((e) => { this.error = e.message; }));
      await new Promise((res) => client.once('close', res));
    } finally {
      try { lock.release(); } catch {}
    }
  }

  async fetchNew(client) {
    if (this.busy) { this.again = true; return; }
    this.busy = true;
    try {
      do {
        this.again = false;
        const from = this.acc.lastUid + 1;
        const msgs = [];
        for await (const m of client.fetch(`${from}:*`, { uid: true, source: true }, { uid: true })) {
          if (m.uid >= from) msgs.push(m);
        }
        msgs.sort((a, b) => a.uid - b.uid);
        for (const m of msgs) {
          const parsed = await simpleParser(m.source);
          await deliver(this.acc.email, m.uid, parsed);
          this.acc.lastUid = m.uid;
          save();
          this.lastMail = new Date().toISOString();
          this.count++;
        }
      } while (this.again);
    } finally {
      this.busy = false;
    }
  }

  stop() {
    this.stopped = true;
    try { this.client?.close(); } catch {}
  }
}

const watchers = new Map();
for (const a of accounts) watchers.set(a.email, new Watcher(a));

// ---------- web ----------
const app = express();
const PUBLIC_FILES = new Set(['/', '/index.html', '/manifest.webmanifest', '/sw.js', '/icon-192.png', '/icon-512.png']);
app.use((req, res, next) => {
  if (req.method === 'GET' && PUBLIC_FILES.has(req.path)) return next(); // শুধু খালি পেজ/আইকন; data API সুরক্ষিতই থাকে
  const h = req.headers.authorization || '';
  const pass = Buffer.from(h.split(' ')[1] || '', 'base64').toString().split(':').slice(1).join(':');
  const a = crypto.createHash('sha256').update(pass).digest();
  const b = crypto.createHash('sha256').update(ADMIN_PASSWORD).digest();
  if (crypto.timingSafeEqual(a, b)) return next();
  res.set('WWW-Authenticate', 'Basic realm="Gmail Relay"').status(401).send('Login required');
});
app.use(express.json());
const SHELL = { '/': 'index.html', '/index.html': 'index.html', '/manifest.webmanifest': 'manifest.webmanifest', '/sw.js': 'sw.js', '/icon-192.png': 'icon-192.png', '/icon-512.png': 'icon-512.png' };
for (const [route, file] of Object.entries(SHELL)) app.get(route, (req, res) => res.sendFile(path.join(__dirname, file)));

app.get('/api/state', (req, res) => {
  res.json({
    webhook: WEBHOOK_URL,
    accounts: accounts.map((a) => {
      const w = watchers.get(a.email);
      return { email: a.email, status: w?.status, error: w?.error, lastMail: w?.lastMail, count: w?.count };
    }),
  });
});

app.post('/api/accounts', async (req, res) => {
  const email = String(req.body.email || '').trim().toLowerCase();
  const pass = String(req.body.password || '').replace(/\s+/g, '');
  if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email) || !pass) return res.status(400).json({ error: 'Email আর App Password দিন।' });
  if (watchers.has(email)) return res.status(409).json({ error: 'এই account আগেই যোগ করা আছে।' });
  if (accounts.length >= MAX_ACCOUNTS) return res.status(400).json({ error: `সর্বোচ্চ ${MAX_ACCOUNTS}টা account।` });

  // login ঠিক আছে কিনা আগে যাচাই
  const test = new ImapFlow({ host: 'imap.gmail.com', port: 993, secure: true, auth: { user: email, pass }, logger: false });
  test.on('error', () => {});
  try {
    await test.connect();
    await test.logout();
  } catch (e) {
    return res.status(400).json({ error: 'Login হয়নি: ' + (e.responseText || e.message) });
  }
  const acc = { email, pass: enc(pass), lastUid: 0 };
  accounts.push(acc);
  save();
  watchers.set(email, new Watcher(acc));
  res.json({ ok: true });
});

app.delete('/api/accounts/:email', (req, res) => {
  const email = req.params.email.toLowerCase();
  watchers.get(email)?.stop();
  watchers.delete(email);
  accounts = accounts.filter((a) => a.email !== email);
  save();
  res.json({ ok: true });
});

app.post('/api/test', async (req, res) => {
  try {
    await post({ type: 'test', message: 'Gmail Relay test', sentAt: new Date().toISOString() });
    res.json({ ok: true });
  } catch (e) {
    res.status(502).json({ error: 'Webhook এ পাঠানো যায়নি: ' + e.message });
  }
});

app.listen(PORT, () => console.log('Gmail Relay running on port ' + PORT));
