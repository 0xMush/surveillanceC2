<?php
declare(strict_types=1);

function handlePersons(): void {
    $db = DB::connect();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $list = $db->all('persons');
        usort($list, fn($a, $b) => strcmp($a['name'] ?? '', $b['name'] ?? ''));
        foreach ($list as &$p) {
            $p['social'] = is_string($p['social'] ?? '') ? (json_decode($p['social'], true) ?? []) : ($p['social'] ?? []);
            $p['linked_devices'] = is_string($p['linked_devices'] ?? '') ? (json_decode($p['linked_devices'], true) ?? []) : ($p['linked_devices'] ?? []);
        }
        jsonOut($list);
    } else {
        requireMethod('POST');
        $input = jsonInput(); $name = trim($input['name'] ?? '');
        if (empty($name)) jsonError('Missing name');
        $id = 'p_'.uniqid(); $n = now();
        $db->insert('persons', ['id'=>$id,'name'=>$name,'photo'=>$input['photo']??'','info'=>$input['info']??'','notes'=>$input['notes']??'','social'=>json_encode($input['social']??[]),'linked_devices'=>'[]','created_at'=>$n,'updated_at'=>$n]);
        jsonOut(['id' => $id, 'status' => 'created'], 201);
    }
}

function handlePerson(): void {
    $db = DB::connect();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = jsonInput(); $id = $input['id'] ?? '';
        if (empty($id)) jsonError('Missing id');
        if (!$db->findOne('persons', 'id', $id)) jsonError('Not found', 404);
        $up = [];
        foreach (['name','photo','info','notes'] as $f) { if (isset($input[$f])) $up[$f] = $input[$f]; }
        if (isset($input['social'])) $up['social'] = json_encode($input['social']);
        if (isset($input['linked_devices'])) $up['linked_devices'] = json_encode($input['linked_devices']);
        if (!empty($up)) { $up['updated_at'] = now(); $db->update('persons', 'id', $id, $up); }
        jsonOut(['status' => 'ok']);
    } else {
        $id = $_GET['id'] ?? '';
        if (empty($id)) jsonError('Missing id');
        $p = $db->findOne('persons', 'id', $id);
        if (!$p) jsonError('Not found', 404);
        $p['social'] = is_string($p['social']??'') ? (json_decode($p['social'], true)??[]) : ($p['social']??[]);
        $p['linked_devices'] = is_string($p['linked_devices']??'') ? (json_decode($p['linked_devices'], true)??[]) : ($p['linked_devices']??[]);
        jsonOut($p);
    }
}

function handlePersonDelete(): void {
    requireMethod('POST');
    $input = jsonInput(); $id = $input['id'] ?? '';
    if (empty($id)) jsonError('Missing id');
    DB::connect()->delete('persons', 'id', $id);
    jsonOut(['status' => 'ok']);
}

function handlePersonLink(): void {
    requireMethod('POST');
    $input = jsonInput(); $pid = $input['person_id'] ?? ''; $bid = $input['beacon_uuid'] ?? ''; $unlink = !empty($input['unlink']);
    if (empty($pid) || empty($bid)) jsonError('Missing fields');
    $db = DB::connect();
    $p = $db->findOne('persons', 'id', $pid);
    if (!$p) jsonError('Person not found', 404);
    $devs = is_string($p['linked_devices']??'') ? (json_decode($p['linked_devices'], true)??[]) : ($p['linked_devices']??[]);
    if ($unlink) $devs = array_values(array_filter($devs, fn($d) => $d !== $bid));
    elseif (!in_array($bid, $devs)) $devs[] = $bid;
    $db->update('persons', 'id', $pid, ['linked_devices'=>json_encode($devs), 'updated_at'=>now()]);
    jsonOut(['status' => 'ok']);
}

function handlePersonPhoto(): void {
    requireMethod('POST');
    $input = jsonInput(); $id = $input['id'] ?? ''; $data = $input['data'] ?? '';
    if (empty($id) || empty($data)) jsonError('Missing fields');
    $db = DB::connect();
    if (!$db->findOne('persons', 'id', $id)) jsonError('Not found', 404);
    $pdir = ensurePersonDir($id);
    file_put_contents($pdir.'/photo.jpg', base64_decode($data));
    $db->update('persons', 'id', $id, ['photo'=>'persons/'.$id.'/photo.jpg','updated_at'=>now()]);
    jsonOut(['status' => 'ok']);
}

function handlePersonPhotoGet(): void {
    requireMethod('GET');
    $id = $_GET['id'] ?? '';
    if (empty($id)) jsonError('Missing id');
    $fp = personDir($id).'/photo.jpg';
    if (!is_file($fp)) jsonError('Not found', 404);
    header('Content-Type: image/jpeg'); readfile($fp); exit;
}

function handleHumanFiles(): void {
    requireMethod('GET');
    $id = $_GET['id'] ?? '';
    if (empty($id)) jsonError('Missing id');
    $p = DB::connect()->findOne('persons', 'id', $id);
    if (!$p) jsonError('Not found', 404);
    $entries = [];
    $pdir = personDir($id);
    if (is_file($pdir.'/photo.jpg')) $entries[] = ['path'=>'photo.jpg','name'=>'photo.jpg','type'=>'file','size'=>filesize($pdir.'/photo.jpg'),'modified'=>$p['updated_at']];
    if (!empty($p['notes'])) $entries[] = ['path'=>'notes.txt','name'=>'notes.txt','type'=>'file','size'=>strlen($p['notes']),'modified'=>$p['updated_at']];
    if (!empty($p['info'])) $entries[] = ['path'=>'info.txt','name'=>'info.txt','type'=>'file','size'=>strlen($p['info']),'modified'=>$p['updated_at']];
    $social = is_string($p['social']??'') ? (json_decode($p['social'], true)??[]) : ($p['social']??[]);
    if (!empty($social)) $entries[] = ['path'=>'social.json','name'=>'social.json','type'=>'file','size'=>strlen(json_encode($social,JSON_PRETTY_PRINT)),'modified'=>$p['updated_at']];
    $linked = is_string($p['linked_devices']??'') ? (json_decode($p['linked_devices'], true)??[]) : ($p['linked_devices']??[]);
    if (!empty($linked)) $entries[] = ['path'=>'linked_devices.json','name'=>'linked_devices.json','type'=>'file','size'=>strlen(json_encode($linked,JSON_PRETTY_PRINT)),'modified'=>$p['updated_at']];
    jsonOut(['entries' => $entries]);
}

function handleHumanFileRead(): void {
    requireMethod('GET');
    $id = $_GET['id'] ?? ''; $file = $_GET['file'] ?? '';
    if (empty($id) || empty($file)) jsonError('Missing parameters');
    $p = DB::connect()->findOne('persons', 'id', $id);
    if (!$p) jsonError('Not found', 404);
    if ($file === 'photo.jpg') {
        $fp = personDir($id).'/photo.jpg';
        if (!is_file($fp)) jsonError('Not found', 404);
        header('Content-Type: image/jpeg'); readfile($fp); exit;
    }
    if ($file === 'linked_devices.json') {
        $linked = is_string($p['linked_devices']??'') ? (json_decode($p['linked_devices'], true)??[]) : ($p['linked_devices']??[]);
        header('Content-Type: application/json; charset=utf-8'); echo json_encode($linked, JSON_PRETTY_PRINT); exit;
    }
    $content = match ($file) {
        'notes.txt' => $p['notes'] ?? '',
        'info.txt' => $p['info'] ?? '',
        'social.json' => json_encode(is_string($p['social']??'') ? (json_decode($p['social'], true)??[]) : ($p['social']??[]), JSON_PRETTY_PRINT),
        default => null,
    };
    if ($content === null) jsonError('Not found', 404);
    header('Content-Type: text/plain; charset=utf-8'); echo $content; exit;
}
