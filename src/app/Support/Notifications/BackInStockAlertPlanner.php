<?php

namespace App\Support\Notifications;

final class BackInStockAlertPlanner
{
    public static function shouldNotify(int $previousStock, int $newStock, ?string $status): bool
    {
        return $previousStock <= 0
            && $newStock > 0
            && strtolower((string) $status) !== 'discontinued';
    }
}
