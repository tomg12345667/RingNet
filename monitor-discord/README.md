# RingNet CPBX Status Bot

A Discord slash-command bot for managing RingNet service status updates and uptime monitors.

## Setup

1. **Install dependencies**
   ```bash
   npm install
   ```

2. **Configure environment**
   ```bash
   cp .env.example .env
   # Edit .env and add your bot token
   ```

3. **Run the bot**
   ```bash
   node index.js
   # or with dotenv:
   node -r dotenv/config index.js
   ```
   Install dotenv if needed: `npm install dotenv`

## Commands

### `/manual-status-send`
Post a manual status update to the channel.

| Option | Required | Description |
|--------|----------|-------------|
| `service` | YES | RingNet Ubuntu or RingNet Asterisk |
| `status` | YES | Up or Down |
| `time` | YES | Time it went up/down (e.g. `14:30`) |
| `date` | YES | Date it went up/down (e.g. `2025-06-26`) |
| `ping_alerts` | YES | Yes or No |

---

### `/add-manual-monitor`
Add a named service to the monitor list (no auto-pinging).

| Option | Required | Description |
|--------|----------|-------------|
| `service_name` | YES | Display name for the service |
| `ping_alerts` | YES | Alert on issue — Yes or No |

---

### `/setup-auto-monitor`
Add a monitor that actively pings a host every 60 seconds. A **test ping runs first** — if it fails, the monitor is not added.

| Option | Required | Description |
|--------|----------|-------------|
| `service_name` | YES | Display name |
| `service_type` | YES | Domain Monitor or IP Monitor |
| `host` | YES | Domain or IP address |
| `port` | YES | Port to ping |
| `ping_alerts` | YES | Alert on status change — Yes or No |

> The setup reply is ephemeral (only visible to you). On success, the bot confirms with a DM-style private reply including test ping latency.

---

### `/list-monitors`
Shows all monitors and their current status. Ephemeral (only visible to you).

---

### `/remove-monitor`
Remove a monitor by name. Ephemeral.

## Data

Monitors are persisted to `data/monitors.json` so they survive restarts. Auto monitors resume polling automatically on bot startup.

## Discord Bot Permissions

The bot needs:
- `applications.commands` scope
- `Send Messages` permission in status channels
- `Embed Links` permission

## Notes

- Auto monitors poll every **60 seconds** via TCP ping
- Status change alerts fire only when `ping_alerts` is set to Yes
- The setup test ping result is sent as an **ephemeral** (private) reply to the user who ran the command
