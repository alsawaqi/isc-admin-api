<?php

namespace App\Http\Controllers;
use App\Models\ConxDatabaseNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        // Adjust guard/model to your admin
        $admin = $request->user(); // or $request->user('admin');

        $rows = ConxDatabaseNotification::query()
            ->where('notifiable_type', 'App\\Models\\Admin')
            ->where('notifiable_id', $admin->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $notifications = $rows->map(function ($n) {
            $data = is_array($n->data) ? $n->data : json_decode($n->data ?? '{}', true);

            return [
                'id'         => $n->id,
                'title'      => $data['title']   ?? 'Notification',
                'message'    => $data['message'] ?? null,
                'order_id'   => $data['order_id'] ?? null,
                'url'        => $data['url'] ?? null,
                'read_at'    => $n->read_at,
                'created_at' => $n->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $notifications,
        ]);
    }

    public function markAllRead(Request $request)
    {
        $admin = $request->user(); // or user('admin')

        ConxDatabaseNotification::query()
            ->where('notifiable_type', 'App\\Models\\Admin')
            ->where('notifiable_id', $admin->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);

        return response()->json(['status' => 'ok']);
    }
}
