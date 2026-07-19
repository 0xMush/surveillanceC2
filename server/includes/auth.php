<?php
declare(strict_types=1);

function loginUser(string $username, string $password): bool {
    $user = DB::connect()->findOne('users', 'username', $username);
    if ($user && password_verify($password, $user['password_hash'] ?? '')) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['created'] = time();
        return true;
    }
    return false;
}

function logoutUser(): void {
    $_SESSION = [];
    session_destroy();
}

function isAuthenticated(): bool {
    return !empty($_SESSION['user_id']);
}

function requireAuth(): void {
    if (!isAuthenticated()) jsonError('Authentication required', 401);
    if (time() - ($_SESSION['created'] ?? 0) > 86400) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['created'] = time();
    }
}

function getCsrfToken(): string {
    return $_SESSION['csrf_token'] ?? '';
}

function validateCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || $token !== getCsrfToken()) {
        jsonError('Invalid CSRF token', 403);
    }
}

function checkRateLimit(string $ip): bool {
    $cutoff = time() - 900;
    $attempts = DB::connect()->all('login_attempts');
    $recent = array_filter($attempts, fn($a) => ($a['ip_address'] ?? '') === $ip && strtotime($a['attempted_at'] ?? '0') > $cutoff);
    return count($recent) < 5;
}

function recordLoginAttempt(string $ip): void {
    DB::connect()->insert('login_attempts', ['ip_address' => $ip, 'attempted_at' => now()]);
}
