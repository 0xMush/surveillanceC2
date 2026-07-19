<?php
declare(strict_types=1);

function handleFile(): void {
    $db = DB::connect();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_FILES['file'])) {
            validateBeaconSecret();
            $uuid = $_POST['beacon_uuid'] ?? '';
            if (empty($uuid)) jsonError('Missing beacon_uuid');
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) jsonError('Upload failed', 500);
            $f = $_FILES['file'];
            $name = preg_replace('/^.*[\\\\\\/]/', '', basename($f['name']));
            $dest = UPLOAD_DIR . '/' . time() . '_' . $name;
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($fi, $f['tmp_name']);
            finfo_close($fi);
            $allowed = ['text/plain','text/html','text/css','text/javascript','application/json','application/xml','application/pdf','application/zip','application/x-gzip','image/jpeg','image/png','image/gif','image/webp','application/octet-stream'];
            if (!in_array($mime, $allowed)) jsonError('File type not allowed: ' . $mime, 400);
            move_uploaded_file($f['tmp_name'], $dest);
            $id = $db->insert('files', ['beacon_uuid' => $uuid, 'filename' => $name, 'path' => $dest, 'size' => $f['size'], 'created_at' => now()]);
            jsonOut(['id' => $id, 'filename' => $name, 'size' => $f['size'], 'status' => 'uploaded'], 201);
        } else {
            validateBeaconSecret();
            $input = jsonInput();
            $uuid = trim($input['beacon_uuid'] ?? '');
            $name = preg_replace('/^.*[\\\\\\/]/', '', basename($input['filename'] ?? ''));
            $data = $input['data'] ?? '';
            if (empty($uuid) || empty($name) || empty($data)) jsonError('Missing fields');
            $raw = base64_decode($data);
            $dest = UPLOAD_DIR . '/' . time() . '_' . $name;
            file_put_contents($dest, $raw);
            $id = $db->insert('files', ['beacon_uuid' => $uuid, 'filename' => $name, 'path' => $dest, 'size' => strlen($raw), 'created_at' => now()]);
            jsonOut(['id' => $id, 'status' => 'uploaded'], 201);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id) {
            $f = $db->findOne('files', 'id', $id);
            if (!$f) jsonError('Not found', 404);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $f['filename'] . '"');
            header('Content-Length: ' . $f['size']);
            readfile($f['path']); exit;
        }
        handleListFiles();
    } else { jsonError('Method not allowed', 405); }
}

function handleListFiles(): void {
    $db = DB::connect();
    $uuid = $_GET['beacon_uuid'] ?? null;
    $files = $db->all('files');
    if ($uuid) $files = array_values(array_filter($files, fn($f) => ($f['beacon_uuid'] ?? '') === $uuid));
    usort($files, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    jsonOut($files);
}
