# Phase 1 Structured Returns/RMA

Phase 1 adds line-item return and refund handling for completed orders.

## Admin Flow

Completed order details now support a `Return / refund selected` action.

For each selected order line, the admin can enter:

- return quantity
- refund amount
- restock toggle
- line-specific reason

The action also requires:

- main reason
- admin signature

## Database Writes

Original sale values stay intact.

`Orders_Placed_Details_T` now stores cumulative reporting fields:

- `Sold_Amount`
- `Returned_Quantity`
- `Refunded_Amount`
- `Net_Amount`
- `Return_State`
- `Refund_State`
- `Last_Returned_At`
- `Last_Refunded_At`

Each adjustment is also written to:

`Orders_Placed_Details_Adjustments_T`

This keeps an immutable history for partial returns, refund-only cases, return-only cases, and return-with-refund cases.

## Vendor Payout Adjustment

Vendor order records keep original totals and add net payout fields:

- `Returned_Quantity`
- `Refunded_Amount`
- `Net_Sub_Total`
- `Adjusted_Commission_Amount`
- `Net_Payout_Amount`
- `Payout_Adjustment_Amount`

If the vendor payout has not been marked paid, the payout screen now uses the adjusted net values.

If a payout was already paid, `Payout_Adjustment_Amount` shows the amount that should be reconciled later.

## Stock Restock

When the admin enables restock and enters a return quantity:

- `Products_Master_T.Product_Stock` is increased
- `Product_Stock_Movements_T` receives a `return_restock` movement

Refund-only actions never restock stock.

## API

Endpoint:

`POST /api/orders-placed/{id}/return-refund`

Expected multipart payload:

- `signature`: image file
- `note`: main reason
- `items`: JSON array

Item shape:

```json
{
  "order_detail_id": 123,
  "quantity": 1,
  "refund_amount": 10.000,
  "restock": true,
  "reason": "Damaged package"
}
```

## Tests

Unit tests cover:

- partial return with refund
- refund without return
- full return and full refund
- rejected over-return quantity
- vendor commission and payout adjustment
- lifecycle state separation
