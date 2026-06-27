# Call Blast — FreePBX Module

A custom FreePBX module that lets you initiate an outbound call directly from the FreePBX Admin GUI. When the call is answered, Asterisk automatically plays a custom recorded message to the recipient.

---

## Features

- Initiate outbound calls from the **Admin → Call Blast** menu page
- Dropdown picks from all recordings in `/var/lib/asterisk/sounds/custom/`
- Dropdown picks trunks automatically from FreePBX
- Supports both **PJSIP** (recommended) and legacy **SIP** trunks
- Custom Caller ID per call
- Atomic `.call` file writing (no partial reads by Asterisk)
- Installs and removes its own Asterisk dialplan context cleanly

---

## Requirements

| Requirement | Version |
|---|---|
| FreePBX | 16+ |
| Asterisk | 16+ |
| PHP | 7.4+ |

---

## Installation

### 1. Clone or download this repo

```bash
cd /var/www/html/admin/modules/
git clone https://github.com/YOUR_USERNAME/callblast.git callblast
```

### 2. Set ownership

```bash
chown -R asterisk:asterisk /var/www/html/admin/modules/callblast/
```

### 3. Install via fwconsole

```bash
fwconsole ma install callblast
fwconsole reload
```

Or via the FreePBX GUI: **Admin → Module Admin → Upload Local Module** (zip the folder first).

---

## Usage

1. **Record your message** — Go to **Admin → System Recordings** and record or upload a `.wav` file. This saves it to `/var/lib/asterisk/sounds/custom/`.

2. **Open Call Blast** — Go to **Admin → Call Blast** in the top nav.

3. **Fill in the form:**
   - **Destination Number** — the number to dial (e.g. `15551234567`)
   - **Outbound Trunk** — which trunk to dial out on
   - **Trunk Protocol** — PJSIP (modern) or SIP (legacy chan_sip)
   - **Message Recording** — the recording to play when answered
   - **Caller ID** — what the recipient sees (e.g. `My System <15559876543>`)

4. **Click Send Call Now** — Asterisk will dial the number immediately.

---

## How It Works

```
FreePBX GUI
    └─► page.callblast.php (form submit)
            └─► Callblast.class.php::initiateCall()
                    └─► Writes .call file → /var/spool/asterisk/outgoing/
                            └─► Asterisk picks it up automatically
                                    └─► Dials number via trunk
                                            └─► On answer: plays recording
                                                    └─► Hangs up
```

The dialplan context installed by this module looks like this:

```ini
[callblast-playback]
exten => s,1,Answer()
 same => n,Wait(1)
 same => n,Playback(${CALLBLAST_MSG})
 same => n,Hangup()
```

The recording path is passed as the `CALLBLAST_MSG` channel variable in the `.call` file.

---

## Troubleshooting

**Call doesn't fire at all**
- Check `/var/log/asterisk/full` for errors
- Verify `/var/spool/asterisk/outgoing/` is writable by the `asterisk` user
- Run `asterisk -rx "core show channels"` to check if Asterisk is running

**Call fires but no audio**
- Confirm the recording exists: `ls /var/lib/asterisk/sounds/custom/`
- Make sure the file format is supported (`.wav` 8kHz 16-bit mono recommended)

**Wrong trunk / call fails**
- If using PJSIP, trunk name must match the endpoint name in PJSIP settings
- If using legacy SIP, switch the Protocol dropdown to SIP

**Dialplan not found after install**
- Run `asterisk -rx "dialplan reload"` manually
- Check `/etc/asterisk/extensions_custom.conf` for the `[callblast-playback]` context

---

## File Structure

```
callblast/
├── module.xml              # FreePBX module manifest
├── Callblast.class.php     # BMO class (business logic)
├── page.callblast.php      # Admin GUI page
├── install.php             # Runs on install — writes dialplan
├── uninstall.php           # Runs on uninstall — removes dialplan
└── README.md               # This file
```

---

## License

GPLv2 — see [GNU GPL v2](http://www.gnu.org/licenses/gpl-2.0.txt)
