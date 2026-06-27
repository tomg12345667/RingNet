require('dotenv').config();

const {
  Client,
  GatewayIntentBits,
  SlashCommandBuilder,
  REST,
  Routes,
  EmbedBuilder,
  PermissionFlagsBits,
} = require('discord.js');
const net = require('net');
const dns = require('dns').promises;
const monitors = require('./monitors');

const client = new Client({ intents: [GatewayIntentBits.Guilds] });

// ─── Helpers ────────────────────────────────────────────────────────────────

function statusEmbed({ title, service, status, time, date, pingAlerts, color }) {
  const embed = new EmbedBuilder()
    .setTitle(title || '📡 RingNet CPBX Status Update')
    .setColor(color || (status === 'UP' ? 0x2ecc71 : 0xe74c3c))
    .addFields(
      { name: 'Service', value: service, inline: true },
      { name: 'Status', value: status === 'UP' ? '🟢 Online' : '🔴 Offline', inline: true },
      { name: 'Date', value: date, inline: true },
      { name: 'Time', value: time, inline: true },
      { name: 'Ping Alerts', value: pingAlerts === 'yes' ? '🔔 Enabled' : '🔕 Disabled', inline: true }
    )
    .setTimestamp();
  return embed;
}

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

async function resolveHost(host) {
  try {
    const result = await dns.lookup(host);
    return result.address;
  } catch {
    return null;
  }
}

// ─── Slash Command Definitions ───────────────────────────────────────────────

const commands = [
  new SlashCommandBuilder()
    .setName('manual-status-send')
    .setDescription('Post a manual status update for a RingNet service')
    .addStringOption(o =>
      o.setName('service')
        .setDescription('Which service?')
        .setRequired(true)
        .addChoices(
          { name: 'RingNet Ubuntu (Local Network)', value: 'RingNet Ubuntu (Local Network)' },
          { name: 'RingNet Asterisk (VPS-Based)', value: 'RingNet Asterisk (VPS-Based)' }
        ))
    .addStringOption(o =>
      o.setName('status')
        .setDescription('Is the service up or down?')
        .setRequired(true)
        .addChoices(
          { name: '🟢 Up', value: 'UP' },
          { name: '🔴 Down', value: 'DOWN' }
        ))
    .addStringOption(o =>
      o.setName('time')
        .setDescription('Time it went up/down (e.g. 14:30)')
        .setRequired(true))
    .addStringOption(o =>
      o.setName('date')
        .setDescription('Date it went up/down (e.g. 2025-06-26)')
        .setRequired(true))
    .addStringOption(o =>
      o.setName('ping_alerts')
        .setDescription('Send ping status alerts?')
        .setRequired(true)
        .addChoices(
          { name: 'Yes', value: 'yes' },
          { name: 'No', value: 'no' }
        )),

  new SlashCommandBuilder()
    .setName('add-manual-monitor')
    .setDescription('Add a manual (no auto-ping) monitor entry')
    .addStringOption(o =>
      o.setName('service_name')
        .setDescription('Name of the service')
        .setRequired(true))
    .addStringOption(o =>
      o.setName('ping_alerts')
        .setDescription('Alert on issues?')
        .setRequired(true)
        .addChoices(
          { name: 'Yes', value: 'yes' },
          { name: 'No', value: 'no' }
        )),

  new SlashCommandBuilder()
    .setName('setup-auto-monitor')
    .setDescription('Set up an auto-monitor with periodic pinging')
    .addStringOption(o =>
      o.setName('service_name')
        .setDescription('Name of the service')
        .setRequired(true))
    .addStringOption(o =>
      o.setName('service_type')
        .setDescription('Domain or IP monitor?')
        .setRequired(true)
        .addChoices(
          { name: 'Domain Monitor', value: 'domain' },
          { name: 'IP Monitor', value: 'ip' }
        ))
    .addStringOption(o =>
      o.setName('host')
        .setDescription('Domain or IP address to monitor')
        .setRequired(true))
    .addIntegerOption(o =>
      o.setName('port')
        .setDescription('Port to ping')
        .setRequired(true))
    .addStringOption(o =>
      o.setName('ping_alerts')
        .setDescription('Alert on issue?')
        .setRequired(true)
        .addChoices(
          { name: 'Yes', value: 'yes' },
          { name: 'No', value: 'no' }
        )),

  new SlashCommandBuilder()
    .setName('list-monitors')
    .setDescription('List all active monitors'),

  new SlashCommandBuilder()
    .setName('remove-monitor')
    .setDescription('Remove a monitor by name')
    .addStringOption(o =>
      o.setName('service_name')
        .setDescription('Name of the monitor to remove')
        .setRequired(true)),
].map(c => c.toJSON());

// ─── Command Handlers ────────────────────────────────────────────────────────

client.on('interactionCreate', async (interaction) => {
  if (!interaction.isChatInputCommand()) return;

  const { commandName } = interaction;

  // /manual-status-send
  if (commandName === 'manual-status-send') {
    const service = interaction.options.getString('service');
    const status = interaction.options.getString('status');
    const time = interaction.options.getString('time');
    const date = interaction.options.getString('date');
    const pingAlerts = interaction.options.getString('ping_alerts');

    const embed = statusEmbed({ service, status, time, date, pingAlerts });
    await interaction.reply({ embeds: [embed] });
  }

  // /add-manual-monitor
  else if (commandName === 'add-manual-monitor') {
    const serviceName = interaction.options.getString('service_name');
    const pingAlerts = interaction.options.getString('ping_alerts');

    if (monitors.get(serviceName)) {
      return interaction.reply({ content: `⚠️ A monitor named **${serviceName}** already exists.`, ephemeral: true });
    }

    monitors.add({
      name: serviceName,
      type: 'manual',
      pingAlerts,
      addedBy: interaction.user.id,
      channelId: interaction.channelId,
      status: 'unknown',
    });

    const embed = new EmbedBuilder()
      .setTitle('📋 Manual Monitor Added')
      .setColor(0x3498db)
      .addFields(
        { name: 'Service', value: serviceName, inline: true },
        { name: 'Type', value: 'Manual', inline: true },
        { name: 'Ping Alerts', value: pingAlerts === 'yes' ? '🔔 Enabled' : '🔕 Disabled', inline: true }
      )
      .setTimestamp();

    await interaction.reply({ embeds: [embed] });
  }

  // /setup-auto-monitor
  else if (commandName === 'setup-auto-monitor') {
    const serviceName = interaction.options.getString('service_name');
    const serviceType = interaction.options.getString('service_type');
    const host = interaction.options.getString('host');
    const port = interaction.options.getInteger('port');
    const pingAlerts = interaction.options.getString('ping_alerts');

    if (monitors.get(serviceName)) {
      return interaction.reply({ content: `⚠️ A monitor named **${serviceName}** already exists.`, ephemeral: true });
    }

    await interaction.deferReply({ ephemeral: true });

    // Resolve host if domain
    let resolvedIp = host;
    if (serviceType === 'domain') {
      resolvedIp = await resolveHost(host);
      if (!resolvedIp) {
        return interaction.editReply({ content: `❌ Could not resolve domain \`${host}\`. Monitor **not** added.` });
      }
    }

    // Test ping
    const result = await tcpPing(resolvedIp, port);

    if (!result.success) {
      return interaction.editReply({
        content: `❌ Test ping to \`${host}:${port}\` failed. Monitor **not** added. Check the host/port and try again.`,
      });
    }

    // Success — add monitor
    monitors.add({
      name: serviceName,
      type: 'auto',
      serviceType,
      host,
      resolvedIp,
      port,
      pingAlerts,
      addedBy: interaction.user.id,
      channelId: interaction.channelId,
      status: 'up',
      lastChecked: new Date().toISOString(),
    });

    // Start polling
    monitors.startPolling(serviceName, client);

    const embed = new EmbedBuilder()
      .setTitle('✅ Auto Monitor Added')
      .setColor(0x2ecc71)
      .addFields(
        { name: 'Service', value: serviceName, inline: true },
        { name: 'Type', value: serviceType === 'domain' ? 'Domain Monitor' : 'IP Monitor', inline: true },
        { name: 'Host', value: `${host}:${port}`, inline: true },
        { name: 'Ping Alerts', value: pingAlerts === 'yes' ? '🔔 Enabled' : '🔕 Disabled', inline: true },
        { name: 'Test Ping', value: `✅ ${result.latency}ms`, inline: true }
      )
      .setTimestamp();

    await interaction.editReply({ embeds: [embed] });
  }

  // /list-monitors
  else if (commandName === 'list-monitors') {
    const all = monitors.list();
    if (all.length === 0) {
      return interaction.reply({ content: '📭 No monitors configured yet.', ephemeral: true });
    }

    const embed = new EmbedBuilder()
      .setTitle('📡 Active Monitors')
      .setColor(0x3498db)
      .setTimestamp();

    for (const m of all) {
      const statusIcon = m.status === 'up' ? '🟢' : m.status === 'down' ? '🔴' : '⚪';
      const detail = m.type === 'auto'
        ? `\`${m.host}:${m.port}\` — ${m.serviceType}`
        : 'Manual entry';
      embed.addFields({
        name: `${statusIcon} ${m.name}`,
        value: `${detail} | Alerts: ${m.pingAlerts === 'yes' ? '🔔' : '🔕'} | Type: ${m.type}`,
      });
    }

    await interaction.reply({ embeds: [embed], ephemeral: true });
  }

  // /remove-monitor
  else if (commandName === 'remove-monitor') {
    const serviceName = interaction.options.getString('service_name');
    const removed = monitors.remove(serviceName);
    if (!removed) {
      return interaction.reply({ content: `⚠️ No monitor named **${serviceName}** found.`, ephemeral: true });
    }
    await interaction.reply({ content: `🗑️ Monitor **${serviceName}** removed.`, ephemeral: true });
  }
});

// ─── Bot Ready ───────────────────────────────────────────────────────────────

client.once('ready', async () => {
  console.log(`✅ Logged in as ${client.user.tag}`);

  const rest = new REST({ version: '10' }).setToken(process.env.DISCORD_TOKEN);
  try {
    await rest.put(
      Routes.applicationCommands(client.user.id),
      { body: commands }
    );
    console.log('✅ Slash commands registered globally.');
  } catch (err) {
    console.error('Failed to register commands:', err);
  }

  // Resume any auto monitors that were persisted
  for (const m of monitors.list()) {
    if (m.type === 'auto') {
      monitors.startPolling(m.name, client);
      console.log(`▶️  Resumed polling for: ${m.name}`);
    }
  }
});

client.login(process.env.DISCORD_TOKEN);
