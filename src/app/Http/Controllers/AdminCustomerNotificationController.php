<?php

namespace App\Http\Controllers;

use App\Services\Notifications\CustomerNotificationService;
use Illuminate\Http\Request;

class AdminCustomerNotificationController extends Controller
{
    public function store(Request $request, CustomerNotificationService $notifications)
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:1000'],
            'url' => ['nullable', 'string', 'max:300'],
        ]);

        if (empty($validated['user_id']) && empty($validated['customer_id'])) {
            return response()->json([
                'message' => 'Select a customer or user for the notification.',
            ], 422);
        }

        $payload = [
            'category' => 'admin_message',
            'title' => $validated['title'],
            'message' => $validated['message'],
            'url' => $validated['url'] ?? '/account?tab=notifications',
        ];

        $notification = !empty($validated['user_id'])
            ? $notifications->notifyUser((int) $validated['user_id'], 'customer.admin_message', $payload)
            : $notifications->notifyCustomer((int) $validated['customer_id'], 'customer.admin_message', $payload);

        if (!$notification) {
            return response()->json([
                'message' => 'Customer user account was not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Customer notification sent.',
            'id' => $notification->id,
        ], 201);
    }
}
