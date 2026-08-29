<?php
declare(strict_types=1);

function isAjaxRequest(): bool {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') return true;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    return str_contains($ct, 'application/json');
}

function handleLogin(): void {
    requireMethod('POST');
    $input = jsonInput();
    $user = trim($input['username'] ?? $_POST['username'] ?? '');
    $pass = $input['password'] ?? $_POST['password'] ?? '';
    if (empty($user) || empty($pass)) {
        if (isAjaxRequest()) jsonError('Username and password required');
        $_SESSION['login_error'] = 'Username and password required';
        header('Location: index.php');
        exit;
    }
    // Brute-force guard: max 8 failed attempts per 10 minutes per username+ip
    $db = DB::connect();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $cutoff = date('Y-m-d H:i:s', time() - 600);
    $fails = 0;
    foreach ($db->all('login_attempts') as $a) {
        if (($a['username'] ?? '') === $user && ($a['ip'] ?? '') === $ip
            && empty($a['success']) && ($a['at'] ?? '') > $cutoff) $fails++;
    }
    if ($fails >= 8) {
        if (isAjaxRequest()) jsonError('Too many attempts. Wait 10 minutes.', 429);
        $_SESSION['login_error'] = 'Too many attempts. Wait 10 minutes.';
        header('Location: index.php');
        exit;
    }

    $found = null;
    foreach ($db->all('users') as $u) {
        if ($u['username'] === $user && password_verify($pass, $u['password_hash'])) {
            $found = $u;
            break;
        }
    }
    if (!$found) {
        $db->insert('login_attempts', ['username' => $user, 'ip' => $ip, 'at' => now(), 'success' => false]);
        if (isAjaxRequest()) jsonError('Invalid credentials', 401);
        $_SESSION['login_error'] = 'Invalid credentials';
        header('Location: index.php');
        exit;
    }
    $db->insert('login_attempts', ['username' => $user, 'ip' => $ip, 'at' => now(), 'success' => true]);
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if (isAjaxRequest()) jsonOut(['status' => 'ok', 'csrf' => $_SESSION['csrf']]);
    header('Location: index.php');
    exit;
}

function handleLogout(): void {
    session_regenerate_id(true);
    session_destroy();
    if (isAjaxRequest()) jsonOut(['status' => 'ok']);
    header('Location: index.php');
    exit;
}
