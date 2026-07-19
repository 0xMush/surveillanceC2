<?php
declare(strict_types=1);

function handleLogin(): void {
    requireMethod('POST');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!checkRateLimit($ip)) jsonError('Too many attempts. Try again later.', 429);
    $input = jsonInput();
    $u = trim($input['username'] ?? '');
    $p = $input['password'] ?? '';
    if (loginUser($u, $p)) {
        jsonOut(['status' => 'ok', 'csrf_token' => getCsrfToken()]);
    } else {
        recordLoginAttempt($ip);
        jsonError('Invalid credentials', 401);
    }
}

function handleLogout(): void {
    logoutUser();
    jsonOut(['status' => 'ok']);
}

function renderLoginForm(): never { ?>
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
.lg .lr{color:#ffab00;font-size:11px;text-align:center;margin-top:10px;display:none}
</style></head><body>
<div class="lg"><h1>&#9670; C2 <span>Panel</span></h1>
<form id="login-form">
<label>Username</label><input id="lu" autocomplete="username">
<label>Password</label><input id="lp" type="password" placeholder="password" autocomplete="current-password">
<button type="submit">Sign In</button>
<div class="er" id="ler">Invalid credentials</div>
<div class="lr" id="lrr">Too many attempts. Try again later.</div>
</form></div>
<script>
document.getElementById('login-form').addEventListener('submit', async function(e){
    e.preventDefault();
    const r=await fetch('api.php?action=login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:document.getElementById('lu').value,password:document.getElementById('lp').value})});
    if(r.ok){const d=await r.json();if(d.csrf_token)localStorage.setItem('csrf_token',d.csrf_token);window.location.reload();}
    else if(r.status===429){document.getElementById('lrr').style.display='block';document.getElementById('ler').style.display='none';}
    else{document.getElementById('ler').style.display='block';document.getElementById('lrr').style.display='none';}
});
</script>
</body></html>
<?php exit; }
