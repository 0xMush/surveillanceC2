# C2 Surveillance Panel

PHP backend + multi-platform beacon (Windows/Linux/Android) with a full-featured dark web UI.

> **For authorized red team testing only.** Only use on systems you own or have explicit written authorization to test.

---

## Features

### Panel (Web UI)
| Feature | Description |
|---------|-------------|
| AJAX login | bcrypt-hashed passwords, CSRF protection, rate limiting |
| Device list | Real-time status (active/dead), OS badges, search/filter |
| Terminal | Full shell history, clear, cancel pending tasks |
| File browser | Browse device filesystem, upload/download/delete files |
| C2 storage | View all pulled files, push files to device |
| Media viewer | Screenshots, camera photos with preview |
| Quick actions | OS-aware buttons (Windows/Linux/Android specific) |
| Notes | Per-device notes with save |
| Human profiles | Link devices to person profiles |
| Help panel | Dynamic command reference per OS |
| Dark theme | Full responsive UI |

### Windows Beacon (eacon_persist.c)
| Feature | Details |
|---------|---------|
| Shell execution | sh <cmd> — full sh commands |
| Process list | ps — all running processes with PID/name/cmdline |
| File browser | rowse <path> — recursive directory listing |
| File ops | pull <file> — upload to C2, push <id> <path> — download from C2 |
| Screenshot | screenshot — DPI-aware, full virtual screen capture |
| Camera | camera — VFW webcam capture |
| Persistence | persist — Registry Run key, Startup folder (.vbs), Scheduled Task, exe copy |
| Self-destruct | selfdestruct — removes all artifacts, registry, tasks, scripts |
| Kill | die / killself — stops beacon |
| Anti-debug | PEB BeingDebugged check, CheckRemoteDebuggerPresent |
| Anti-sandbox | VM detection, CPU/RAM/disk thresholds, known sandbox DLLs |
| Runtime API | No import table traces — resolves all APIs at runtime |
| UUID persistence | Stored in %APPDATA%\.appdata.dat |
| Burst mode | Re-checks C2 in 2-3s after tasks (no full sleep delay) |

### Windows Beacon (eacon_auto.c)
Same as eacon_persist.c but auto-persists on first execution (flag file .persist_flag). No operator action needed.

### Linux Beacon (eacon_linux.c)
| Feature | Details |
|---------|---------|
| Shell execution | sh <cmd> — full sh commands |
| Process list | ps — all processes with PID/name/cmdline |
| File browser | rowse <path> — directory listing |
| File ops | pull/push/ead/delete |
| Drives | drives — mounted filesystems |
| Network | ifconfig / ip — network interfaces |
| Persistence | crontab @reboot, systemd user service, init.d (root), .bashrc |
| Self-destruct | Removes all persistence, deletes UUID + binary |
| Anti-debug | Passive TracerPid check |
| Anti-sandbox | VM artifact detection, CPU/RAM/disk checks |
| UUID persistence | Stored in /tmp/.appdata.dat |
| Daemon | Forks to background, no terminal dependency |

### Android Beacon (APK)
| Feature | Details |
|---------|---------|
| Shell | shell <cmd> — full sh commands |
| Process list | ps — running processes |
| File browser | rowse <path> — directory listing |
| File ops | pull/push/ead/delete |
| Camera | cam back / cam front — headless Camera2, max resolution, auto-exposure |
| Screenshot | ss — MediaProjection, stored token (one-time consent) |
| Persist | Battery exemption + foreground service + boot receiver |
| Self-destruct | Cleans data + shows uninstall prompt |
| System app disguise | Package name com.sysupdate.ota, app name "System Update" |

---

## Requirements

### Panel
- PHP 7.4+
- Apache with mod_rewrite (or Nginx with rewrite rules)
- XAMPP recommended (ships with PHP + Apache)

### Compiling Beacons

| Target | Toolchain | OS |
|--------|-----------|-----|
| Windows | MinGW-w64 (gcc.exe) | Windows |
| Linux | GCC (gcc) | Linux/macOS (cross-compile) or on target |
| Android | Android SDK 34 + JDK 17 | Windows/Linux/macOS |

---

## Installation

### 1. Deploy the Panel

`ash
# Clone or copy to your web server
git clone https://github.com/youruser/c2-panel.git
# Or copy githubVERSION/ to htdocs/c2/
`

### 2. Run Setup

`ash
# Via browser
http://yourserver/c2/setup.php

# Or via CLI
cd /path/to/c2
php setup.php
`

Setup will:
- Generate BEACON_SECRET (64 hex chars) and SESSION_SECRET (32 hex chars)
- Create .env file
- Create data/ and uploads/ directories
- Initialize JSON database files
- Create admin user with bcrypt-hashed password

### 3. Delete setup.php

`ash
rm setup.php  # Recommended after first setup
`

### 4. Login

Open http://yourserver/c2/ and login with the credentials from setup.

---

## Compiling Beacons

### Windows (beacon_persist.c)

`ash
# Requires MinGW-w64 in PATH
gcc -o beacon.exe beacon_persist.c -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s
`

**Flags:**
- -lws2_32 — Winsock (HTTP communications)
- -lgdi32 — GDI (screenshot)
- -lvfw32 — Video for Windows (camera)
- -mwindows — No console window
- -O2 -s — Optimize + strip symbols

### Windows (beacon_auto.c)

`ash
gcc -o beacon.exe beacon_auto.c -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s
`

### Linux (beacon_linux.c)

`ash
# On target Linux machine
gcc -o beacon beacon_linux.c -s -Os

# Or cross-compile from Windows/macOS (requires Linux sysroot)
`

**Flags:**
- -s — Strip symbols
- -Os — Optimize for size

### Android (APK)

**Option A: Android Studio**
1. Open payload/android/ in Android Studio
2. Edit Config.java — set HOST and SECRET
3. Build → Generate Signed APK → use elease.keystore (password: ndroid)

**Option B: Command line**
`ash
# Requires: JDK 17, Android SDK (build-tools 34, platform android-34)

# 1. Compile resources
aapt2 compile -o compiled/ res/values/strings.xml

# 2. Link
aapt2 link -o proto.apk --manifest AndroidManifest.xml \
    -I android.jar --java gen compiled/*.flat

# 3. Compile Java
javac -source 11 -target 11 \
    -classpath android.jar \
    -d obj/ gen/**/*.java app/src/main/java/**/*.java

# 4. DEX
d8 --lib android.jar --output dex/ obj/**/*.class

# 5. Build APK
cp proto.apk unsigned.apk
# Add classes.dex to unsigned.apk (zip)
# Sign with apksigner
apksigner sign --ks release.keystore --ks-pass pass:android \
    --ks-key-alias key0 --out beacon.apk unsigned.apk
`

---

## Configuration

### Before Compiling Beacons

Edit the config section in each beacon source:

**C Beacons (Windows/Linux):**
`c
#define CFG_HOST    "YOUR_SERVER_IP"    // Your server IP or hostname
#define CFG_PORT    "8080"              // Port
#define CFG_SECRET  "YOUR_BEACON_SECRET_HERE"  // From .env
`

**Android (Config.java):**
`java
public static final String HOST = "YOUR_SERVER_IP";
public static final int PORT = 8080;
public static final String SECRET = "YOUR_BEACON_SECRET_HERE";
`

### After Setup — .env

`env
BEACON_SECRET=abc123...  # Auto-generated by setup.php
SESSION_SECRET=def456... # Auto-generated by setup.php
DB_PATH=data
UPLOAD_DIR=uploads
`

---

## Command Reference

### All Platforms
| Command | Description |
|---------|-------------|
| ping | Alive check with timestamp |
| hostname | Device info (model, OS, fingerprint) |
| ps | List running processes |
| drives | List storage devices/partitions |
| shell <cmd> | Execute shell command |
| die / killself | Stop beacon process |
| selfdestruct | Remove all persistence + data |

### File Operations
| Command | Description |
|---------|-------------|
| rowse <path> | Directory listing (JSON) |
| ead <file> | Read file contents |
| pull <file> | Upload file to C2 server |
| push <file_id> <path> | Download file from C2 to device |
| delete <path> | Delete file or directory |

### Windows/Linux
| Command | Description |
|---------|-------------|
| ifconfig / ip | Network interfaces + IP |
| screenshot | Capture screen (DPI-aware) |
| camera | Capture webcam photo (Windows only) |
| persist | Enable persistence mechanisms |

### Android
| Command | Description |
|---------|-------------|
| ifconfig / ip | Network interfaces |
| cam back | Capture photo (rear camera) |
| cam front | Capture photo (front camera) |
| ss | Screenshot (one-time consent required) |
| persist | Battery exemption + foreground service + boot receiver |

---

## Directory Structure

`
c2-panel/
├── api.php                      # API entry point
├── index.php                    # Panel UI + login gate
├── setup.php                    # One-time setup wizard
├── .env.example                 # Environment template
├── .htaccess                    # Apache rewrite + security headers
├── assets/
│   ├── panel.js                 # Panel JavaScript (1297 lines)
│   └── panel.css                # Dark theme CSS
├── handlers/                    # PHP API handlers
│   ├── auth.php                 # Login/logout
│   ├── beacon.php               # Beacon checkin + task dispatch
│   ├── device.php               # Device management
│   ├── file.php                 # File upload/download
│   ├── filebrowser.php          # Browse cache + directory requests
│   ├── media.php                # Screenshot/camera upload
│   ├── payload.php              # Payload generation + serving
│   ├── persons.php              # Human profiles
│   ├── result.php               # Task results
│   └── task.php                 # Task CRUD
├── includes/                    # PHP core
│   ├── auth.php                 # Session, CSRF, login form
│   ├── config.php               # Env loader, constants
│   ├── db.php                   # JSON file-based database
│   ├── helpers.php              # Utility functions (detectOS, etc.)
│   └── router.php               # Request routing
├── payload/                     # Beacon sources
│   ├── windows/
│   │   ├── beacon_persist.c     # Windows beacon (manual persist)
│   │   └── beacon_auto.c        # Windows beacon (auto persist)
│   ├── linux/
│   │   └── beacon_linux.c       # Linux beacon
│   └── android/                 # Android beacon (full source)
│       ├── AndroidManifest.xml
│       ├── build.gradle
│       ├── settings.gradle
│       ├── gradle.properties
│       ├── release.keystore     # APK signing key
│       └── app/
│           ├── build.gradle
│           └── src/main/
│               ├── AndroidManifest.xml
│               ├── java/com/sysupdate/ota/
│               │   ├── BeaconService.java    # Core beacon service
│               │   ├── BootReceiver.java     # Auto-start on boot
│               │   ├── Config.java           # Server config (EDIT THIS)
│               │   ├── MainActivity.java     # Permission requests
│               │   └── ScreenshotActivity.java # Screenshot consent
│               └── res/
│                   ├── drawable/ic_launcher.xml
│                   ├── values/strings.xml
│                   └── xml/network_security_config.xml
├── data/                        # Runtime data (created by setup)
│   └── .gitkeep
└── uploads/                     # File storage (created by setup)
    └── .gitkeep
`

---

## Security Notes

- .env is gitignored — never committed
- data/ and uploads/ are gitignored — no device data in repo
- All beacon secrets are placeholder (YOUR_BEACON_SECRET_HERE) — must be configured before compilation
- CSRF tokens required for all POST requests from the panel
- Rate limiting on login (8 attempts per 10 minutes per IP)
- Apache .htaccess blocks access to data/, includes/, handlers/, .env
- Session-based auth with bcrypt password hashing
- Beacon comms use Bearer token auth (BEACON_SECRET)

---

## License

For authorized security testing and educational purposes only.
