<?php
declare(strict_types=1);

function handleBrowseCache(): void {
    $db = DB::connect();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $uuid = $_GET['beacon_uuid'] ?? ''; $path = rtrim($_GET['path'] ?? '/', '/') ?: '/';
        if (empty($uuid)) jsonError('Missing beacon_uuid');
        $c = $db->findFirst('browse_cache', ['beacon_uuid' => $uuid, 'path' => $path]);
        jsonOut(['entries' => $c ? json_decode($c['entries'], true) : null]);
    } else {
        requireMethod('POST');
        $input = jsonInput();
        $uuid = $input['beacon_uuid'] ?? ''; $path = rtrim($input['path'] ?? '/', '/') ?: '/';
        $entries = json_encode($input['entries'] ?? []); $n = now();
        if (empty($uuid)) jsonError('Missing beacon_uuid');
        $ex = $db->findFirst('browse_cache', ['beacon_uuid' => $uuid, 'path' => $path]);
        if ($ex) $db->update('browse_cache', 'id', $ex['id'], ['entries' => $entries, 'updated_at' => $n]);
        else $db->insert('browse_cache', ['beacon_uuid' => $uuid, 'path' => $path, 'entries' => $entries, 'updated_at' => $n]);
        jsonOut(['status' => 'ok']);
    }
}

function handleFileDelete(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = $input['beacon_uuid'] ?? ''; $path = $input['path'] ?? '';
    if (empty($uuid) || empty($path)) jsonError('Missing fields');
    $id = DB::connect()->insert('tasks', ['beacon_uuid'=>$uuid,'command'=>'delete '.$path,'status'=>'pending','created_at'=>now(),'assigned_at'=>null,'completed_at'=>null]);
    jsonOut(['task_id' => $id, 'status' => 'created']);
}

function handleBrowseRequest(): void {
    requireMethod('POST');
    $input = jsonInput();
    $uuid = $input['beacon_uuid'] ?? ''; $path = $input['path'] ?? '';
    if (empty($uuid)) jsonError('Missing beacon_uuid');
    $id = DB::connect()->insert('tasks', ['beacon_uuid'=>$uuid,'command'=>'browse '.$path,'status'=>'pending','created_at'=>now(),'assigned_at'=>null,'completed_at'=>null]);
    jsonOut(['task_id' => $id, 'status' => 'created']);
}
