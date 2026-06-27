const fs = require('fs');
const net = require('net');
const dns = require('dns').promises;
const { EmbedBuilder } = require('discord.js');

const DATA_FILE = './data/monitors.json';
const POLL_INTERVAL_MS = 60_000; // check every 60 seconds

const _timers = new Map(); // serviceName -> setInterval handle

// ─── Persistence ─────────────────────────────────────────────────────────────

function load() {
  try {
    if (!fs.existsSync('./data')) fs.mkdirSync('./data');
    if (!fs.existsSync(DATA_FILE)) return [];
    return JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
  } catch {
    return [];
  }
}

function save(monitors) {
  if (!fs.existsSync('./data')) fs.mkdirSync('./data');
  fs.writeFileSync(DATA_FILE, JSON.stringify(monitors, null, 2));
}

// ─── CRUD ────────────────────────────────────────────────────────────────────

function list() {
  return load();
}

function get(name) {
  return load().find(m => m.name.toLowerCase() === name.toLowerCase()) || null;
}

function add(monitor) {
  const monitors = load();
  monitors.push(monitor);
  save(monitors);
}

function remove(name) {
  const monitors = load();
  const idx = monitors.findIndex(m => m.name.toLowerCase() === name.toLowerCase());
  if (idx === -1) return false;
  monitors.splice(idx, 1);
  save(monitors);
  // Stop polling if active
  if (_timers.has(name)) {
    clearInterval(_timers.get(name));
    _timers.delete(name);
  }
  return true;
}

function updateStatus(name, status) {
  const monitors = load();
  const m = monitors.find(m => m.name.toLowerCase() === name.toLowerCase());
  if (m) {
    m.status = status;
    m.lastChecked = new Date().toISOString();
    save(monitors);
  }
}

// ─── TCP Ping ─────────────────────────────────────────────────────────────────

async function tcpPing(host, port, timeoutMs = 5000) {
  return new Promise((resolve) => {
    const socket = new net.Socket();
    const start = Date.now();
    socket.setTimeout(timeoutMs);
    socket.on('connect', () => {
      const latency = Date.now() - start;
      socket.destroy();
      resolve({ success: true, latency });
    });
    socket.on('error', () => { socket.destroy(); resolve({ success: false }); });
    socket.on('timeout', () => { socket.destroy(); resolve({ success: false }); });
    socket.connect(port, host);
  });
}

// ─── Polling ──────────────────────────────────────────────────────────────────

function startPolling(name, client) {
  if (_timers.has(name)) return; // already polling

  const handle = setInterval(async () => {
    const m = get(name);
    if (!m || m.type !== 'auto') {
      clearInterval(handle);
      _timers.delete(name);
      return;
    }

    // Resolve domain if needed
    let ip = m.resolvedIp || m.host;
    if (m.serviceType === 'domain') {
      try {
        const res = await dns.lookup(m.host);
        ip = res.address;
      } catch {
        ip = null;
      }
    }

    const result = ip ? await tcpPing(ip, m.port) : { success: false };
    const newStatus = result.success ? 'up' : 'down';
    const prevStatus = m.status;

    updateStatus(name, newStatus);

    // Only alert on status change if ping alerts enabled
    if (m.pingAlerts === 'yes' && newStatus !== prevStatus) {
      try {
        const channel = await client.channels.fetch(m.channelId);
        if (!channel) return;

        const color = newStatus === 'up' ? 0x2ecc71 : 0xe74c3c;
        const icon = newStatus === 'up' ? '🟢' : '🔴';
        const now = new Date();

        const embed = new EmbedBuilder()
          .setTitle(`${icon} Monitor Alert — ${m.name}`)
          .setColor(color)
          .addFields(
            { name: 'Service', value: m.name, inline: true },
            { name: 'Status', value: newStatus === 'up' ? '🟢 Back Online' : '🔴 Went Offline', inline: true },
            { name: 'Host', value: `${m.host}:${m.port}`, inline: true },
            { name: 'Time', value: now.toLocaleTimeString(), inline: true },
            { name: 'Date', value: now.toLocaleDateString(), inline: true },
            ...(result.latency ? [{ name: 'Latency', value: `${result.latency}ms`, inline: true }] : [])
          )
          .setTimestamp();

        await channel.send({ embeds: [embed] });
      } catch (err) {
        console.error(`Failed to send alert for ${name}:`, err.message);
      }
    }
  }, POLL_INTERVAL_MS);

  _timers.set(name, handle);
}

module.exports = { list, get, add, remove, updateStatus, startPolling };
