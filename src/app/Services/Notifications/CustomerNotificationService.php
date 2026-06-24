<?php

namespace App\Services\Notifications;

use App\Models\ConxDatabaseNotification;
use App\Models\OrdersPlaced;
use App\Models\ProductMaster;
use App\Models\SupportTicket;
use App\Support\Notifications\CustomerNotificationPayload;
use Illuminate\Support\Facades\DB;

class CustomerNotificationService
{
    /**
     * @param array<string, mixed> $data
     */
    public function notifyUser(int $userId, string $type, array $data): ConxDatabaseNotification
    {
        return ConxDatabaseNotification::create([
            'type' => $type,
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => $userId,
            'data' => $data,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function notifyCustomer(?int $customerId, string $type, array $data, ?int $fallbackUserId = null): ?ConxDatabaseNotification
    {
        $userId = $this->resolveCustomerUserId($customerId, $fallbackUserId);

        return $userId ? $this->notifyUser($userId, $type, $data) : null;
    }

    public function notifyOrderStatus(OrdersPlaced $order, string $status): ?ConxDatabaseNotification
    {
        return $this->notifyCustomer(
            customerId: $order->Customers_Id ? (int) $order->Customers_Id : null,
            type: 'customer.order_update',
            data: CustomerNotificationPayload::orderUpdate(
                orderId: (int) $order->id,
                orderCode: $order->Order_Code,
                status: $status,
            ),
        );
    }

    public function notifyReturnRefund(OrdersPlaced $order, int $returnedQuantity, string|int|float $refundedAmount): ?ConxDatabaseNotification
    {
        return $this->notifyCustomer(
            customerId: $order->Customers_Id ? (int) $order->Customers_Id : null,
            type: 'customer.return_refund',
            data: CustomerNotificationPayload::returnRefund(
                orderId: (int) $order->id,
                orderCode: $order->Order_Code,
                returnedQuantity: $returnedQuantity,
                refundedAmount: $refundedAmount,
            ),
        );
    }

    public function notifyTicketReply(SupportTicket $ticket): ?ConxDatabaseNotification
    {
        return $this->notifyCustomer(
            customerId: $ticket->Customer_Id ? (int) $ticket->Customer_Id : null,
            type: 'customer.ticket_reply',
            data: CustomerNotificationPayload::ticketReply(
                ticketId: (int) $ticket->id,
                reference: $ticket->Ticket_Reference,
                subject: $ticket->Subject,
            ),
            fallbackUserId: $ticket->User_Id ? (int) $ticket->User_Id : null,
        );
    }

    public function notifyBackInStock(ProductMaster $product): int
    {
        $alerts = DB::table('Customer_Back_In_Stock_Alerts_T')
            ->where('Products_Id', $product->id)
            ->where('Status', 'active')
            ->whereNull('Notified_At')
            ->get();

        $sent = 0;

        foreach ($alerts as $alert) {
            $userId = $this->resolveCustomerUserId(
                customerId: $alert->Customer_Id ? (int) $alert->Customer_Id : null,
                fallbackUserId: $alert->User_Id ? (int) $alert->User_Id : null,
            );

            if (!$userId) {
                continue;
            }

            $this->notifyUser(
                userId: $userId,
                type: 'customer.back_in_stock',
                data: CustomerNotificationPayload::backInStock(
                    productId: (int) $product->id,
                    productName: (string) $product->Product_Name,
                    slug: $product->Slug,
                ),
            );

            DB::table('Customer_Back_In_Stock_Alerts_T')
                ->where('id', $alert->id)
                ->update([
                    'Status' => 'notified',
                    'Notified_At' => now(),
                    'updated_at' => now(),
                ]);

            $sent++;
        }

        return $sent;
    }

    private function resolveCustomerUserId(?int $customerId, ?int $fallbackUserId = null): ?int
    {
        if ($customerId) {
            $userId = DB::table('Customers_Master_T')
                ->where('id', $customerId)
                ->value('User_Id');

            if ($userId) {
                return (int) $userId;
            }
        }

        return $fallbackUserId ?: null;
    }
}
