<?php
declare(strict_types=1);

function now(): string {
    return date('Y-m-d H:i:s');
}

function jsonInput(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function jsonOut(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $msg, int $code = 400): void {
    jsonOut(['error' => $msg], $code);
}

function requireMethod(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        jsonError('Method not allowed', 405);
    }
}

function validateBeaconSecret(): void {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) {
        $all = getallheaders();
        $header = $all['Authorization'] ?? $all['authorization'] ?? '';
    }
    if (!str_starts_with($header, 'Bearer ')) {
        jsonError('Unauthorized', 401);
    }
    if (substr($header, 7) !== BEACON_SECRET) {
        jsonError('Invalid token', 401);
    }
}

function deviceDir(string $uuid): string {
    return UPLOAD_DIR . '/devices/' . preg_replace('/[^a-zA-Z0-9\-]/', '', $uuid);
}

function ensureDeviceDir(string $uuid): string {
    $dir = deviceDir($uuid);
    foreach (['', '/files', '/media'] as $sub) {
        $d = $dir . $sub;
        is_dir($d) || mkdir($d, 0755, true);
    }
    return $dir;
}

function personDir(string $id): string {
    return UPLOAD_DIR . '/persons/' . preg_replace('/[^a-zA-Z0-9\-_]/', '', $id);
}

function ensurePersonDir(string $id): string {
    $dir = personDir($id);
    is_dir($dir) || mkdir($dir, 0755, true);
    return $dir;
}

function detectOS(string $osString): string {
    $os = strtolower($osString);
    if (str_contains($os, 'win')) return 'windows';
    if (str_contains($os, 'darwin') || str_contains($os, 'mac')) return 'macos';
    if (str_contains($os, 'linux') || str_contains($os, 'debian') || str_contains($os, 'ubuntu') || str_contains($os, 'centos') || str_contains($os, 'kali')) return 'linux';
    return 'unknown';
}
