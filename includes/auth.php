<?php
declare(strict_types=1);

function isAuthenticated(): bool {
    return !empty($_SESSION['user']);
}

function requireAuth(): void {
    if (!isAuthenticated()) {
        jsonError('Authentication required', 401);
    }
}

function getCsrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function validateCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals(getCsrfToken(), $token)) {
        jsonError('Invalid CSRF token', 403);
    }
}

function renderLoginForm(): void {
    $error = $_SESSION['login_error'] ?? '';
    unset($_SESSION['login_error']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>C2 Panel &mdash; Login</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='80' font-size='80'>&#9670;</text></svg>">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#07070c;--card:#0e0e16;--line:#1a1a2b;--txt:#d8dae2;--dim:#5a5e6e;--green:#00e05a;--red:#ff4757}
html,body{height:100%}
body{background:var(--bg);color:var(--txt);font-family:'Segoe UI',system-ui,sans-serif;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
/* ambient grid */
body::before{content:'';position:absolute;inset:0;background-image:linear-gradient(var(--line) 1px,transparent 1px),linear-gradient(90deg,var(--line) 1px,transparent 1px);background-size:44px 44px;opacity:.14;mask-image:radial-gradient(ellipse at center,black 20%,transparent 75%);-webkit-mask-image:radial-gradient(ellipse at center,black 20%,transparent 75%)}
body::after{content:'';position:absolute;width:520px;height:520px;border-radius:50%;background:radial-gradient(circle,rgba(0,224,90,.07),transparent 65%);top:50%;left:50%;transform:translate(-50%,-60%);pointer-events:none}
.login-wrap{width:380px;max-width:92vw;position:relative;z-index:1}
.brand{text-align:center;margin-bottom:26px}
.brand .glyph{font-size:40px;color:var(--green);display:block;text-shadow:0 0 24px rgba(0,224,90,.45);animation:pulse 3s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:.85}50%{opacity:1}}
.brand h1{font-size:21px;font-weight:700;letter-spacing:4px;margin-top:10px;color:#fff}
.brand h1 em{color:var(--green);font-style:normal}
.brand p{font-size:11px;color:var(--dim);letter-spacing:2.5px;text-transform:uppercase;margin-top:6px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:30px 28px;box-shadow:0 18px 50px rgba(0,0,0,.55)}
.fld{margin-bottom:16px}
.fld label{display:flex;justify-content:space-between;font-size:10px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:var(--dim);margin-bottom:7px}
.inp{position:relative;display:flex;align-items:center;background:#0a0a12;border:1px solid var(--line);border-radius:9px;transition:border-color .18s, box-shadow .18s}
.inp:focus-within{border-color:var(--green);box-shadow:0 0 0 3px rgba(0,224,90,.09)}
.inp .ic{padding:0 0 0 13px;font-size:13px;color:var(--dim)}
.inp input{flex:1;background:none;border:none;outline:none;padding:12px 14px 12px 9px;color:var(--txt);font-size:13.5px;font-family:inherit;letter-spacing:.4px}
.btn-login{width:100%;background:linear-gradient(135deg,#00e05a,#00b347);border:none;border-radius:9px;padding:13px;color:#04120a;font-family:inherit;font-size:12.5px;font-weight:800;letter-spacing:3px;text-transform:uppercase;cursor:pointer;transition:filter .15s, transform .1s;margin-top:4px;display:flex;align-items:center;justify-content:center;gap:8px}
.btn-login:hover{filter:brightness(1.12)}
.btn-login:active{transform:scale(.985)}
.btn-login[disabled]{opacity:.6;cursor:wait}
.msg{display:none;align-items:flex-start;gap:8px;background:rgba(255,71,87,.08);border:1px solid rgba(255,71,87,.28);color:#ff7684;padding:10px 12px;border-radius:8px;font-size:11.5px;line-height:1.45;margin-bottom:15px}
.msg.on{display:flex}
.hint{margin-top:18px;text-align:center;font-size:10.5px;color:var(--dim);letter-spacing:.6px}
.hint b{color:#8b8fa3;font-weight:600}
.spin{width:13px;height:13px;border:2px solid rgba(4,18,10,.35);border-top-color:#04120a;border-radius:50%;animation:rot .7s linear infinite;display:none}
@keyframes rot{to{transform:rotate(360deg)}}
</style>
</head>
<body>
<div class="login-wrap">
    <div class="brand">
        <span class="glyph">&#9670;</span>
        <h1>C<em>2</em> PANEL</h1>
        <p>Command &amp; Control</p>
    </div>
    <div class="card">
        <?php if ($error): ?><div class="msg on" id="msg"><span>&#9888;</span><span><?= htmlspecialchars($error) ?></span></div>
        <?php else: ?><div class="msg" id="msg"><span>&#9888;</span><span id="msg-txt"></span></div><?php endif; ?>
        <form id="f" autocomplete="on">
            <div class="fld">
                <label>Username</label>
                <div class="inp"><span class="ic">&#128100;</span><input type="text" name="username" id="u" autofocus autocomplete="username" spellcheck="false"></div>
            </div>
            <div class="fld">
                <label>Password</label>
                <div class="inp"><span class="ic">&#128273;</span><input type="password" name="password" id="p" autocomplete="current-password"></div>
            </div>
            <button type="submit" class="btn-login" id="go"><span class="spin" id="sp"></span><span id="go-t">&#9654;&nbsp; Access Terminal</span></button>
        </form>
        <div class="hint">Authorized operators only &middot; All access is <b>logged</b></div>
    </div>
</div>
<script>
const f = document.getElementById('f');
const msg = document.getElementById('msg');
const msgTxt = document.getElementById('msg-txt');
const go = document.getElementById('go');
const sp = document.getElementById('sp');
const goT = document.getElementById('go-t');

function fail(t){
    msgTxt.textContent = t;
    msg.classList.add('on');
    go.disabled = false;
    sp.style.display = 'none';
    goT.innerHTML = '&#9654;&nbsp; Access Terminal';
}

f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const u = document.getElementById('u').value.trim();
    const p = document.getElementById('p').value;
    if (!u || !p) { fail('Enter both username and password.'); return; }
    go.disabled = true;
    sp.style.display = 'inline-block';
    goT.textContent = 'Authenticating...';
    try {
        const r = await fetch('api.php?action=login', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({username: u, password: p})
        });
        let j = null;
        try { j = await r.json(); } catch (_) {}
        if (r.ok && j && j.status === 'ok') {
            localStorage.setItem('csrf_token', j.csrf || '');
            goT.textContent = '&#10003; Granted'.replace('&#10003;', '\u2713');
            location.replace('index.php');
        } else {
            fail((j && j.error) ? j.error : 'Login failed (' + r.status + ')');
        }
    } catch (err) {
        fail('Network error: ' + err.message);
    }
});
</script>
</body>
</html>
<?php
}
