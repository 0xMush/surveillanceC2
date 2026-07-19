<?php
declare(strict_types=1);
session_start();

define('BASE_DIR', dirname(__DIR__));
define('DATA_DIR', BASE_DIR . '/data');
define('UPLOAD_DIR', BASE_DIR . '/uploads');
define('MEDIA_DIR', BASE_DIR . '/uploads/media');
define('PHOTO_DIR', BASE_DIR . '/uploads/persons');
define('STORAGE_DIR', BASE_DIR . '/data');

$envFile = BASE_DIR . '/.env';
$ENV = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            $p = strpos($line, '=');
            $k = trim(substr($line, 0, $p));
            $v = trim(substr($line, $p + 1));
            $ENV[$k] = $v;
        }
    }
}

define('BEACON_SECRET', $ENV['BEACON_SECRET'] ?? '');

foreach ([DATA_DIR, UPLOAD_DIR, MEDIA_DIR, PHOTO_DIR] as $dir) {
    is_dir($dir) || mkdir($dir, 0755, true);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/router.php';
