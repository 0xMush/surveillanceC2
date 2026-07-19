<?php
declare(strict_types=1);
session_start();
$envFile = __DIR__ . '/.env';
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
define('BASE_DIR', __DIR__);
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('MEDIA_DIR', __DIR__ . '/uploads/media');
define('PHOTO_DIR', __DIR__ . '/uploads/persons');
define('STORAGE_DIR', __DIR__ . '/data');
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
DB::connect()->initSchema();
$users = DB::connect()->all('users');
if (!empty($users)) {
    header('Location: index.php');
    exit;
}
$step = 'form';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['username']) && !empty($_POST['password'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    if (strlen($password) < 8) { $error = 'Password must be at least 8 characters.'; }
    else {
        DB::connect()->insert('users', [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'created_at' => now(),
        ]);
        if (!is_file($envFile) || !str_contains(file_get_contents($envFile), 'BEACON_SECRET')) {
            $secret = bin2hex(random_bytes(48));
            file_put_contents($envFile, "BEACON_SECRET={$secret}\n");
        }
        $_SESSION['setup_complete'] = true;
        $step = 'done';
        $secret = file_get_contents($envFile);
        if (preg_match('/BEACON_SECRET=(.+)/', $secret, $m)) $secret = $m[1];
        else $secret = bin2hex(random_bytes(48));
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>C2 Panel - Setup</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;background:#080c14;color:#c8d0dc;display:flex;align-items:center;justify-content:center;min-height:100vh}
.card{background:#0f1620;border:1px solid #1e2a3a;border-radius:10px;padding:28px;max-width:440px;width:90vw}
h1{font-size:16px;margin-bottom:20px;letter-spacing:1px;color:#00e5ff}
h1 span{color:#00c853}
.card.done h1{color:#00c853}
label{display:block;font-size:10px;color:#6b7f99;margin:10px 0 3px;text-transform:uppercase;letter-spacing:.5px}
input{width:100%;background:#161f2e;border:1px solid #1e2a3a;border-radius:4px;padding:8px 10px;color:#c8d0dc;font-size:13px;outline:none}
input:focus{border-color:#00c853}
.btn{margin-top:16px;width:100%;padding:8px;border:none;border-radius:4px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;cursor:pointer}
.btn-g{background:#00c853;color:#000}
.btn-g:hover{background:#00e060}
.err{color:#ff1744;font-size:11px;margin-top:6px}
.done-box{font-family:monospace;font-size:11px;background:#080c14;border:1px solid #1e2a3a;border-radius:4px;padding:10px;word-break:break-all;margin:10px 0;line-height:1.6}
small{font-size:10px;color:#6b7f99;display:block;margin-top:12px}
a{color:#2979ff}
.sec{font-size:12px;color:#6b7f99;margin:6px 0;text-transform:none;letter-spacing:0}
.warn{background:#ffab0020;border:1px solid #ffab0040;border-radius:4px;padding:8px;font-size:11px;color:#ffab00;margin:10px 0}
.g{color:#00c853}
</style>
</head>
<body>
<?php if ($step === 'form'): ?>
<div class="card">
    <h1>&#9670; C2 <span>Setup</span></h1>
    <?php if (!empty($error)): ?><div class="err"><?=htmlspecialchars($error)?></div><?php endif; ?>
    <form method="post">
        <label>Username</label>
        <input name="username" required autocomplete="off" value="<?=htmlspecialchars($_POST['username'] ?? 'admin')?>">
        <label>Password <span class="sec">(min 8 chars)</span></label>
        <input type="password" name="password" required minlength="8">
        <button class="btn btn-g">Create Admin &raquo;</button>
    </form>
    <small>After setup, this page will self-destruct and you'll be redirected to the panel.</small>
</div>
<?php else: ?>
<div class="card done">
    <h1>&#9670; C2 <span>Panel</span></h1>
    <div class="g" style="font-size:18px;margin-bottom:8px">&#10003; Setup Complete</div>
    <div class="warn">&#9888; Save these credentials. After proceeding, this page <b>self-destructs</b> and cannot be recovered.</div>
    <label style="margin-top:14px">Admin Login</label>
    <div class="done-box"><b>URL:</b> <a href="index.php">index.php</a><br><b>User:</b> <?=htmlspecialchars($username)?><br><b>Pass:</b> <?=htmlspecialchars($_POST['password'])?></div>
    <label style="margin-top:14px">Beacon Secret (for .env)</label>
    <div class="done-box"><?=htmlspecialchars($secret)?></div>
    <p style="font-size:11px;color:#6b7f99;margin-top:8px">The beacon secret is also stored in <code>.env</code>. Beacons authenticate using <code>Authorization: Bearer &lt;secret&gt;</code>.</p>
    <a href="index.php" class="btn btn-g" style="display:block;text-decoration:none;text-align:center">&#10148; Go to Panel</a>
    <?php @unlink(__FILE__); ?>
</div>
<?php endif; ?>
</body>
</html>
