<?php

namespace Tests\Unit\Notifications;

use App\Support\Notifications\BackInStockAlertPlanner;
use PHPUnit\Framework\TestCase;

class BackInStockAlertPlannerTest extends TestCase
{
    public function test_it_notifies_only_when_stock_moves_from_unavailable_to_available(): void
    {
        $this->assertTrue(BackInStockAlertPlanner::shouldNotify(previousStock: 0, newStock: 5, status: 'available'));
        $this->assertTrue(BackInStockAlertPlanner::shouldNotify(previousStock: -2, newStock: 1, status: 'available'));

        $this->assertFalse(BackInStockAlertPlanner::shouldNotify(previousStock: 2, newStock: 5, status: 'available'));
        $this->assertFalse(BackInStockAlertPlanner::shouldNotify(previousStock: 0, newStock: 0, status: 'out_of_stock'));
        $this->assertFalse(BackInStockAlertPlanner::shouldNotify(previousStock: 0, newStock: 5, status: 'discontinued'));
    }
}
