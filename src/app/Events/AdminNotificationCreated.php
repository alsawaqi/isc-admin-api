<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class AdminNotificationCreated implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public array $data
    ) {}

    // public channel
    public function broadcastOn()
    {
        return new Channel('admin.notifications');
    }

    public function broadcastAs()
    {
        return 'new-notification';
    }
}