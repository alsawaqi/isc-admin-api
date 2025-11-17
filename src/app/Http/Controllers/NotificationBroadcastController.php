<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\NewOrderNotification;

class NotificationBroadcastController extends Controller
{
    //

     public function broadcast(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required',
            'type' => 'required|string',
            'data' => 'required',
            'created_at' => 'required',
        ]);

        // Broadcast the notification
        event(new NewOrderNotification($validated));

        return response()->json(['message' => 'Notification broadcasted']);
    }
}
