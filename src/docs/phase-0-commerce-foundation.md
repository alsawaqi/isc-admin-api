# Phase 0 Commerce Foundation

This note defines the shared commerce rules that Phase 1 returns/RMA work should follow.

## Order Item Lifecycle

`Orders_Placed_Details_T.Status` remains the fulfillment status of the line item.

Current allowed fulfillment statuses:

- `pending`
- `processing`
- `packed`
- `dispatched`
- `shipped`
- `ready_for_collection`
- `delivered`
- `cancelled`
- `returned`
- `on-hold`

Return and refund progress must not be hidden inside the fulfillment status alone. A line can be:

- returned with refund
- returned without refund
- refunded without physical return
- partially returned
- partially refunded

The shared PHP contract for these rules is:

- `App\Support\Orders\OrderItemLifecycle`
- `tests/Unit/Orders/OrderItemLifecycleTest.php`

## Analytics Source Of Truth

Sales analysis should continue to use `Orders_Placed_Details_T`.

Phase 1 should add item-level analytics fields to `Orders_Placed_Details_T`, for example:

- `Returned_Quantity`
- `Refunded_Amount`
- `Return_State`
- `Refund_State`
- `Last_Returned_At`
- `Last_Refunded_At`

Derived values:

- net quantity = `Quantity - Returned_Quantity`
- net amount = original line amount - `Refunded_Amount`

The original sale fields should remain unchanged so gross sales, returns, refunds, and net sales can all be reported.

## Audit Trail

Every cancellation, return, refund, and return-with-refund action should write an immutable audit row.

Recommended Phase 1 audit table:

`Orders_Placed_Details_Adjustments_T`

Recommended fields:

- `Orders_Placed_Id`
- `Orders_Placed_Details_Id`
- `Adjustment_Type`: `cancellation`, `return`, `refund`, `return_and_refund`
- `Quantity`
- `Amount`
- `Restock_Quantity`
- `Reason`
- `Actor_User_Id`
- `Signature_Url`
- `Signature_Mime`
- `Metadata`
- timestamps

`Order_Process_Log_T` can still be used for operational timelines, but financial/return facts should live in the adjustment table.

## Parent Order Status

Parent `Orders_Placed_T.Status` should be derived carefully:

- If all active lines are cancelled, parent status becomes `cancelled`.
- If all delivered lines are fully returned and fully refunded, parent can become `returned`.
- If only some lines are returned/refunded, parent should remain completed/delivered with item-level return/refund states.

This avoids losing partial-order detail.

## Test Coverage Baseline

Phase 1 should add feature tests for:

- partial line return
- refund without return
- return with restock
- return without restock
- refund amount cannot exceed line amount
- returned quantity cannot exceed ordered quantity
- parent order remains active when only one line is adjusted
- vendor payout/commission adjustment
- reporting net amount and net quantity

Phase 0 already adds unit coverage for the shared lifecycle contract.
