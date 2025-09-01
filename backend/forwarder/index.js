// index.js
require('dotenv').config();
const express = require('express');
const bodyParser = require('body-parser');
const qrcode = require('qrcode-terminal');
const { Client, LocalAuth } = require('whatsapp-web.js');

const PORT = process.env.PORT || 3000;
const PERSONAL = process.env.PERSONAL_NUMBER || ''; // example: +918250560727

if (!PERSONAL) {
  console.error('Set PERSONAL_NUMBER in .env (example: +919xxxxxxxxx)');
  // continue; we'll respond with error if not set
}

const client = new Client({
  authStrategy: new LocalAuth({ clientId: 'otp-forwarder' }),
  puppeteer: {
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  }
});

let ready = false;
client.on('qr', qr => {
  // small qr for terminal logs
  qrcode.generate(qr, { small: true });
  console.log('Scan QR (qrcode above) — then client will be ready.');
});
client.on('ready', () => {
  ready = true;
  console.log('WhatsApp client ready.');
});
client.on('auth_failure', (msg) => {
  console.error('Auth failure:', msg);
});
client.initialize();

const app = express();
app.use(bodyParser.json());

app.post('/api/forward', async (req, res) => {
  const { phone, otp } = req.body || {};
  if (!phone || !otp) return res.status(400).json({ status: 'error', message: 'phone and otp required' });

  if (!PERSONAL) return res.status(500).json({ status: 'error', message: 'PERSONAL_NUMBER not configured' });
  if (!ready) return res.status(503).json({ status: 'error', message: 'WhatsApp client not ready, scan QR and wait' });

  try {
    const chatId = PERSONAL.replace(/\D+/g,'') + '@c.us';
    const message = `User: ${phone} OTP: ${otp}`;
    const sent = await client.sendMessage(chatId, message);
    return res.json({ status: 'success', message: 'forwarded', sentId: sent.id._serialized });
  } catch (err) {
    console.error('Send error:', err && err.toString());
    return res.status(500).json({ status: 'error', message: 'failed to send', error: err && err.toString() });
  }
});

app.get('/api/status', (req, res) => {
  res.json({ ready });
});

app.listen(PORT, () => {
  console.log(`Forwarder running on port ${PORT}`);
});
