<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class NewOrderNotification extends Notification implements ShouldBroadcast
{
    public function __construct(public array $orderData)
    {
    }

    public function via($notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title'         => 'New order received',
            'order_id'      => $this->orderData['id'] ?? null,
            'customer_name' => $this->orderData['customer_name'] ?? null,
            'total'         => $this->orderData['total'] ?? null,
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return [
            'data' => [
                'title'         => 'New order received',
                'order_id'      => $this->orderData['id'] ?? null,
                'customer_name' => $this->orderData['customer_name'] ?? null,
                'total'         => $this->orderData['total'] ?? null,
            ],
        ];
    }

    public function broadcastOn(): array
    {
        return ['admin.notifications'];
    }
}
