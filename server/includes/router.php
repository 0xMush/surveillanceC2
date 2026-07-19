<?php
declare(strict_types=1);

function routeRequest(): void {
    $action = $_REQUEST['action'] ?? '';

    $public = ['login', 'beacon', 'result'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['file', 'media_upload'])) {
        $public[] = $action;
    }

    if (!in_array($action, $public)) {
        requireAuth();
        validateCsrf();
    }

    $h = __DIR__ . '/../handlers/';

    switch ($action) {
        case 'login': require_once $h . 'auth.php'; handleLogin(); break;
        case 'logout': require_once $h . 'auth.php'; handleLogout(); break;
        case 'csrf': jsonOut(['token' => getCsrfToken()]); break;
        case 'beacon': require_once $h . 'beacon.php'; handleBeaconCheckin(); break;
        case 'task': require_once $h . 'task.php'; handleTask(); break;
        case 'result': require_once $h . 'result.php'; handleResult(); break;
        case 'file': require_once $h . 'file.php'; handleFile(); break;
        case 'media_upload': require_once $h . 'media.php'; handleMediaUpload(); break;
        case 'media': require_once $h . 'media.php'; handleMedia(); break;
        case 'rename': require_once $h . 'device.php'; handleRename(); break;
        case 'savenotes': require_once $h . 'device.php'; handleSaveNotes(); break;
        case 'beacons': require_once $h . 'device.php'; handleListBeacons(); break;
        case 'tasks': require_once $h . 'task.php'; handleListTasks(); break;
        case 'results': require_once $h . 'result.php'; handleListResults(); break;
        case 'files': require_once $h . 'file.php'; handleListFiles(); break;
        case 'browse_cache': require_once $h . 'filebrowser.php'; handleBrowseCache(); break;
        case 'file_delete': require_once $h . 'filebrowser.php'; handleFileDelete(); break;
        case 'delete_upload': require_once $h . 'file.php'; handleDeleteUpload(); break;
        case 'browse_req': require_once $h . 'filebrowser.php'; handleBrowseRequest(); break;
        case 'ls_device': require_once $h . 'device.php'; handleLsDevice(); break;
        case 'device_read': require_once $h . 'device.php'; handleDeviceRead(); break;
        case 'device_info': require_once $h . 'device.php'; handleDeviceInfo(); break;
        case 'terminal': require_once $h . 'device.php'; handleTerminal(); break;
        case 'remove_device': require_once $h . 'device.php'; handleRemoveDevice(); break;
        case 'persons': require_once $h . 'persons.php'; handlePersons(); break;
        case 'person': require_once $h . 'persons.php'; handlePerson(); break;
        case 'person_delete': require_once $h . 'persons.php'; handlePersonDelete(); break;
        case 'person_link': require_once $h . 'persons.php'; handlePersonLink(); break;
        case 'person_photo': require_once $h . 'persons.php'; handlePersonPhoto(); break;
        case 'person_photo_get': require_once $h . 'persons.php'; handlePersonPhotoGet(); break;
        case 'human_files': require_once $h . 'persons.php'; handleHumanFiles(); break;
        case 'human_file_read': require_once $h . 'persons.php'; handleHumanFileRead(); break;
        case 'payloads': require_once $h . 'payload.php'; handlePayloads(); break;
        case 'payload': require_once $h . 'payload.php'; handlePayload(); break;
        case 'payload_delete': require_once $h . 'payload.php'; handlePayloadDelete(); break;
        case 'payload_generate': require_once $h . 'payload.php'; handlePayloadGenerate(); break;
        default: jsonError('Unknown action', 404);
    }
}
