<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ConxNotificationDevice;

class NotificationDeviceController extends Controller
{
    //

    public function storeOrUpdate(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'device_id'       => 'required|string|max:255',  // Beams device ID
            'device_name'     => 'nullable|string|max:255',
            'browser_name'    => 'nullable|string|max:255',
            'browser_version' => 'nullable|string|max:255',
            'os_name'         => 'nullable|string|max:255',
            'os_version'      => 'nullable|string|max:255',
            'user_agent'      => 'nullable|string|max:1024',
        ]);

        $record = ConxNotificationDevice::updateOrCreate(
            [
                'user_id'        => $user->id,
                'beams_device_id' => $data['device_id'],
            ],
            [
                'device_name'     => $data['device_name'] ?? null,
                'browser_name'    => $data['browser_name'] ?? null,
                'browser_version' => $data['browser_version'] ?? null,
                'os_name'         => $data['os_name'] ?? null,
                'os_version'      => $data['os_version'] ?? null,
                'user_agent'      => $data['user_agent'] ?? $request->userAgent(),
                'ip_address'      => $request->ip(),
                'is_enabled'      => true,
                'last_seen_at'    => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'id'     => $record->id,
        ]);
    }


    public function disableCurrentDevice(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $data = $request->validate([
            'device_id' => 'required|string|max:255',   // Beams device id
        ]);

        // Option A: soft-disable (keep history)
        ConxNotificationDevice::where('user_id', $user->id)
            ->where('beams_device_id', $data['device_id'])
            ->update([
                'is_enabled'   => false,
                'last_seen_at' => now(),
            ]);

        // Option B (if you prefer): actually delete the row
        // ConxNotificationDevice::where('user_id', $user->id)
        //     ->where('beams_device_id', $data['device_id'])
        //     ->delete();

        return response()->json(['status' => 'ok']);
    }
}
