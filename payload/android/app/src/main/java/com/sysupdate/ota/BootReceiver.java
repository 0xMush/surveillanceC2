package com.sysupdate.ota;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Build;

public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context c, Intent i) {
        if (i.getAction() != null && (i.getAction().equals(Intent.ACTION_BOOT_COMPLETED) || i.getAction().equals("android.intent.action.QUICKBOOT_POWERON"))) {
            Intent s = new Intent(c, BeaconService.class);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) c.startForegroundService(s); else c.startService(s);
        }
    }
}
