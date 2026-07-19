<?php
declare(strict_types=1);

function handleTask(): void {
    $db = DB::connect();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = jsonInput();
        $uuid = trim($input['beacon_uuid'] ?? '');
        $cmd = trim($input['command'] ?? '');
        if (empty($uuid) || empty($cmd)) jsonError('Missing beacon_uuid or command');
        $id = $db->insert('tasks', [
            'beacon_uuid' => $uuid, 'command' => $cmd, 'status' => 'pending',
            'created_at' => now(), 'assigned_at' => null, 'completed_at' => null,
        ]);
        jsonOut(['id' => $id, 'status' => 'created'], 201);
    } else { handleListTasks(); }
}

function handleListTasks(): void {
    $db = DB::connect();
    $uuid = $_GET['beacon_uuid'] ?? null;
    $status = $_GET['status'] ?? null;
    $tasks = $db->all('tasks');
    if ($uuid) $tasks = array_values(array_filter($tasks, fn($t) => ($t['beacon_uuid'] ?? '') === $uuid));
    if ($status) $tasks = array_values(array_filter($tasks, fn($t) => ($t['status'] ?? '') === $status));
    usort($tasks, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    jsonOut($tasks);
}
