<?php
declare(strict_types=1);

function handleBrowseCache(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = jsonInput();
        $uuid = trim($input['beacon_uuid'] ?? '');
        $path = $input['path'] ?? '/';
        $entries = $input['entries'] ?? [];
        if (empty($uuid)) jsonError('Missing beacon_uuid');
        $db = DB::connect();
        $entriesJson = json_encode($entries, JSON_UNESCAPED_UNICODE);
        $existing = $db->findFirst('browse_cache', ['beacon_uuid' => $uuid, 'path' => $path]);
        if ($existing) {
            $db->update('browse_cache', 'id', $existing['id'], ['entries' => $entriesJson, 'updated_at' => now()]);
        } else {
            $db->insert('browse_cache', ['beacon_uuid' => $uuid, 'path' => $path, 'entries' => $entriesJson, 'updated_at' => now()]);
        }
        jsonOut(['status' => 'ok']);
    } else {
        $uuid = $_GET['beacon_uuid'] ?? '';
        $path = $_GET['path'] ?? '/';
        if (empty($uuid)) jsonError('Missing beacon_uuid');
        $db = DB::connect();
        $cached = $db->findFirst('browse_cache', ['beacon_uuid' => $uuid, 'path' => $path]);
        if ($cached && !empty($cached['entries'])) {
            $entries = json_decode($cached['entries'], true) ?? [];
            jsonOut(['entries' => $entries, 'cached' => true, 'updated_at' => $cached['updated_at'] ?? '']);
        } else {
            jsonOut(['entries' => null, 'cached' => false]);
        }
    }
}

function handleBrowseRequest(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = trim($input['beacon_uuid'] ?? '');
    $path = $input['path'] ?? 'C:\\';
    if (empty($uuid)) jsonError('Missing beacon_uuid');
    $db = DB::connect();
    $id = $db->insert('tasks', [
        'beacon_uuid' => $uuid,
        'command' => 'browse ' . $path,
        'status' => 'pending',
        'created_at' => now(),
        'assigned_at' => null,
        'completed_at' => null,
    ]);
    jsonOut(['task_id' => $id, 'status' => 'created'], 201);
}

function handleFileDelete(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = trim($input['beacon_uuid'] ?? '');
    $path = $input['path'] ?? '';
    if (empty($uuid) || empty($path)) jsonError('Missing fields');
    $db = DB::connect();
    $id = $db->insert('tasks', [
        'beacon_uuid' => $uuid,
        'command' => 'delete ' . $path,
        'status' => 'pending',
        'created_at' => now(),
        'assigned_at' => null,
        'completed_at' => null,
    ]);
    jsonOut(['task_id' => $id, 'status' => 'created'], 201);
}
