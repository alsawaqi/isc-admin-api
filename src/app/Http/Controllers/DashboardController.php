<?php

namespace App\Http\Controllers;

use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
 public function revenueChart(Request $request)
{
    $range = $request->get('range', 'weekly');

    $to = now();
    $from = match ($range) {
        'today'  => now()->startOfDay(),
        'monthly'=> now()->subDays(30)->startOfDay(),
        'yearly' => now()->subMonths(12)->startOfDay(),
        default  => now()->subDays(7)->startOfDay(),
    };

    // Determine the grouping bucket based on range
    $bucket = match ($range) {
        'today'  => "DATEADD(hour, DATEDIFF(hour, 0, created_at), 0)",
        'yearly' => "DATEFROMPARTS(YEAR(created_at), MONTH(created_at), 1)",
        default  => "CONVERT(date, created_at)",
    };

    // Query the orders placed table
    $rows = DB::table('Orders_Placed_T')
        
        ->selectRaw("$bucket as bucket")
        ->selectRaw("
            SUM(
                CASE WHEN Status = 'delivered' THEN Total_Price ELSE 0 END
            ) as confirmed_amount
        ")
        ->selectRaw("
            SUM(
                CASE WHEN Status = 'pending' THEN Total_Price ELSE 0 END
            ) as pending_amount
        ")
        ->groupBy(DB::raw($bucket))
        ->orderBy(DB::raw($bucket))
        ->get();

    return response()->json([
        'categories' => $rows->pluck('bucket')->map(fn($v) => (string)$v)->toArray(),
        'confirmed'  => $rows->pluck('confirmed_amount')->map(fn($v) => (float)$v)->toArray(),
        'pending'    => $rows->pluck('pending_amount')->map(fn($v) => (float)$v)->toArray(),
    ]);
}

public function customersDonut()
{
    $rows = DB::table('Customers_Master_T')
        ->whereNull('deleted_at')
        ->selectRaw("ISNULL(Customer_Code, 'Unknown') as label")
        ->selectRaw("COUNT(*) as total")
        ->groupBy(DB::raw("ISNULL(Customer_Code, 'Unknown')"))
        ->orderByDesc('total')
        ->limit(6)
        ->get();

     return response()->json([
        'labels' => $rows->pluck('label')->toArray(),
        'series' => $rows->pluck('total')->map(fn($v) => (int)$v)->toArray(),
    ]);
}


public function kpis()
{
    $now = now();
    $weekStart = now()->subDays(7);
    $lastWeekStart = now()->subDays(14);

    // ---------- Products ----------
    $totalProducts = (int) DB::table('Products_Master_T')->count();

    $productsThisWeek = (int) DB::table('Products_Master_T')
        ->where('created_at', '>=', $weekStart)
        ->count();

    $productsLastWeek = (int) DB::table('Products_Master_T')
        ->whereBetween('created_at', [$lastWeekStart, $weekStart])
        ->count();

    // ---------- Customers ----------
    $totalCustomers = (int) DB::table('Customers_Master_T')
        ->whereNull('deleted_at')
        ->count();

    $customersThisWeek = (int) DB::table('Customers_Master_T')
        ->whereNull('deleted_at')
        ->where('created_at', '>=', $weekStart)
        ->count();

    $customersLastWeek = (int) DB::table('Customers_Master_T')
        ->whereNull('deleted_at')
        ->whereBetween('created_at', [$lastWeekStart, $weekStart])
        ->count();

    // ---------- Orders ----------
    $totalOrders = (int) DB::table('Orders_Placed_T')->count();

    $ordersThisWeek = (int) DB::table('Orders_Placed_T')
        ->where('created_at', '>=', $weekStart)
        ->count();

    $ordersLastWeek = (int) DB::table('Orders_Placed_T')
        ->whereBetween('created_at', [$lastWeekStart, $weekStart])
        ->count();

    // ---------- Sales (sum of Total_Price) ----------
    // Treat "sales" as orders that are moving/finished (exclude cancelled/returned/on-hold/pending)
    $salesStatuses = ['processing','packed','dispatched','shipped','delivered'];

    $totalSales = (float) DB::table('Orders_Placed_T')
        ->whereIn('Status', $salesStatuses)
        ->sum('Total_Price');

    $salesThisWeek = (float) DB::table('Orders_Placed_T')
        ->whereIn('Status', $salesStatuses)
        ->where('created_at', '>=', $weekStart)
        ->sum('Total_Price');

    $salesLastWeek = (float) DB::table('Orders_Placed_T')
        ->whereIn('Status', $salesStatuses)
        ->whereBetween('created_at', [$lastWeekStart, $weekStart])
        ->sum('Total_Price');

    return response()->json([
        'totals' => [
            'products'  => $totalProducts,
            'customers' => $totalCustomers,
            'orders'    => $totalOrders,
            'sales'     => $totalSales,
        ],
        'delta' => [
            'products'  => $productsThisWeek - $productsLastWeek,
            'customers' => $customersThisWeek - $customersLastWeek,
            'orders'    => $ordersThisWeek - $ordersLastWeek,
            'sales'     => $salesThisWeek - $salesLastWeek,
        ],
    ]);
}


public function recentOrders(Request $request)
{
    $limit = (int) ($request->get('limit', 5));
    if ($limit <= 0) $limit = 5;
    if ($limit > 20) $limit = 20;

    $rows = DB::table('Orders_Placed_T as o')
        ->leftJoin('Customers_Master_T as c', 'c.id', '=', 'o.Customers_Id')
        ->select([
            'o.id',
            'o.Order_Code',
            'o.Total_Price',
            'o.Status',
            'o.created_at',
        ])
        ->selectRaw("COALESCE(c.Customer_Full_Name, '—') as customer_name")
        // items_count
        ->addSelect([
            'items_count' => DB::table('Orders_Placed_Details_T as d')
                ->selectRaw('COUNT(*)')
                ->whereColumn('d.Orders_Placed_Id', 'o.id'),
        ])
        // total_qty
        ->addSelect([
            'total_qty' => DB::table('Orders_Placed_Details_T as d')
                ->selectRaw('ISNULL(SUM(d.Quantity), 0)')
                ->whereColumn('d.Orders_Placed_Id', 'o.id'),
        ])
        // first_product_name (top 1 by detail id)
        ->addSelect([
            'first_product_name' => DB::table('Orders_Placed_Details_T as d')
                ->join('Products_Master_T as p', 'p.id', '=', 'd.Products_Id')
                ->whereColumn('d.Orders_Placed_Id', 'o.id')
                ->orderBy('d.id', 'asc')
                ->limit(1)
                ->select('p.Product_Name'),
        ])
        ->orderByDesc('o.created_at')
        ->limit($limit)
        ->get();

    return response()->json([
        'data' => $rows,
    ]);
}


public function transactionsFeed(Request $request)
{
    $period = $request->get('period', 'this_month'); // this_month | last_month
    $limit  = (int) ($request->get('limit', 6));
    if ($limit <= 0) $limit = 6;
    if ($limit > 20) $limit = 20;

    // Use created_at as you have it everywhere (simpler + consistent)
    if ($period === 'last_month') {
        $from = now()->subMonthNoOverflow()->startOfMonth();
        $to   = now()->subMonthNoOverflow()->endOfMonth();
    } else {
        $from = now()->startOfMonth();
        $to   = now()->endOfMonth();
    }

    $rows = DB::table('Sales_Transactions_Details_T as d')
        ->join('Sales_Transaction_Header_T as h', 'h.id', '=', 'd.Sales_Transaction_Header_Id')
        ->leftJoin('Orders_Placed_T as o', 'o.id', '=', 'h.Orders_Placed_Id')
        ->leftJoin('Customers_Master_T as c', 'c.id', '=', 'o.Customers_Id')
        ->whereBetween('d.created_at', [$from, $to])
        ->select([
            'd.id',
            'd.Payment_Method',
            'd.Payment_Status',
            'd.Payment_Amount',
            'd.Payment_Currency',
            'd.Card_Brand',
            'd.Card_Last4',
            'd.COD_Collected',
            'd.COD_Collected_At',
            'd.Transfer_Reference',
            'd.created_at',
        ])
        ->selectRaw("COALESCE(o.Order_Code, '—') as Order_Code")
        ->selectRaw("COALESCE(c.Customer_Full_Name, '—') as Customer_Full_Name")
        ->orderByDesc('d.created_at')
        ->limit($limit)
        ->get();

    return response()->json([
        'data' => $rows
    ]);
}



public function ordersTrend(Request $request)
{
    $days = (int) ($request->get('days', 14));
    if ($days < 7) $days = 7;
    if ($days > 60) $days = 60;

    $currentFrom = now()->subDays($days - 1)->startOfDay();
    $currentTo   = now()->endOfDay();

    $prevFrom = now()->subDays(($days * 2) - 1)->startOfDay();
    $prevTo   = now()->subDays($days)->endOfDay();

    // group per day (SQL Server)
    $rows = DB::table('Orders_Placed_T')
        ->whereBetween('created_at', [$currentFrom, $currentTo])
        ->selectRaw("CONVERT(date, created_at) as d")
        ->selectRaw("SUM(Total_Price) as total_amount")
        ->selectRaw("COUNT(*) as total_orders")
        ->groupBy(DB::raw("CONVERT(date, created_at)"))
        ->orderBy(DB::raw("CONVERT(date, created_at)"))
        ->get();

    // map to fill missing days with zeros
    $map = $rows->keyBy(fn($r) => (string) $r->d);

    $categories = [];
    $seriesAmount = [];
    $seriesCount = [];

    foreach (CarbonPeriod::create($currentFrom->copy()->startOfDay(), $currentTo->copy()->startOfDay()) as $date) {
        $key = $date->toDateString(); // YYYY-MM-DD
        $categories[] = $date->format('M d'); // for x-axis
        $seriesAmount[] = (float) (($map[$key]->total_amount ?? 0));
        $seriesCount[]  = (int)   (($map[$key]->total_orders ?? 0));
    }

    $currentTotal = (float) DB::table('Orders_Placed_T')
        ->whereBetween('created_at', [$currentFrom, $currentTo])
        ->sum('Total_Price');

    $prevTotal = (float) DB::table('Orders_Placed_T')
        ->whereBetween('created_at', [$prevFrom, $prevTo])
        ->sum('Total_Price');

    $pct = null;
    if ($prevTotal > 0) {
        $pct = (($currentTotal - $prevTotal) / $prevTotal) * 100;
    }

    return response()->json([
        'summary' => [
            'current_total' => $currentTotal,
            'prev_total' => $prevTotal,
            'pct_change' => $pct, // null if prev_total = 0
        ],
        'chart' => [
            'categories' => $categories,
            'amount' => $seriesAmount,
            'count' => $seriesCount,
        ],
    ]);
}



public function topProducts(Request $request)
{
    $limit = (int) ($request->get('limit', 5));
    if ($limit <= 0) $limit = 5;
    if ($limit > 20) $limit = 20;

    // If you want to exclude cancelled/returned orders from "top selling"
    $validStatuses = ['processing','packed','dispatched','shipped','delivered'];

    $rows = DB::table('Orders_Placed_Details_T as d')
        ->join('Orders_Placed_T as o', 'o.id', '=', 'd.Orders_Placed_Id')
        ->join('Products_Master_T as p', 'p.id', '=', 'd.Products_Id')
        ->whereIn('o.Status', $validStatuses) // comment this line if you want ALL orders
        ->groupBy('d.Products_Id', 'p.Product_Name', 'p.Product_Price')
        ->select([
            'd.Products_Id as id',
            'p.Product_Name',
            'p.Product_Price',
        ])
        ->selectRaw('SUM(d.Quantity) as sold_qty')
        ->selectRaw('COUNT(DISTINCT d.Orders_Placed_Id) as total_orders')
        ->orderByDesc(DB::raw('SUM(d.Quantity)'))
        ->limit($limit)
        ->get();

    return response()->json([
        'data' => $rows
    ]);
}


public function stockReport(Request $request)
{
    $limit = (int) ($request->get('limit', 5));
    if ($limit <= 0) $limit = 5;
    if ($limit > 50) $limit = 50;

    // If you want only active products
    // $activeStatus = 'active'; // depends on your Products_Master_T.Status values

    // Pick products that are most important to see:
    // 1) Out of stock first
    // 2) then low stock
    // 3) then highest stock (optional)
    $rows = DB::table('Products_Master_T')
        // ->where('Status', $activeStatus)
        ->select('id', 'Product_Name', 'Product_Price', 'Product_Stock')
        ->orderByRaw("CASE 
            WHEN Product_Stock <= 0 THEN 0
            WHEN Product_Stock <= 10 THEN 1
            ELSE 2
        END ASC")
        ->orderBy('Product_Stock', 'ASC') // within the bucket
        ->limit($limit)
        ->get();

    return response()->json([
        'data' => $rows
    ]);
}

private function dashboardRange(Request $request): array
{
    $range = (string) $request->get('range', '30d');
    $to = now()->endOfDay();

    $from = match ($range) {
        'today' => now()->startOfDay(),
        '7d' => now()->subDays(6)->startOfDay(),
        '90d' => now()->subDays(89)->startOfDay(),
        'year' => now()->startOfYear(),
        default => now()->subDays(29)->startOfDay(),
    };

    return [$from, $to, $range];
}

private function previousRange(Carbon $from, Carbon $to): array
{
    $days = max(1, $from->diffInDays($to) + 1);

    return [
        $from->copy()->subDays($days)->startOfDay(),
        $from->copy()->subDay()->endOfDay(),
    ];
}

private function hasColumn(string $table, string $column): bool
{
    static $cache = [];
    $key = $table.'.'.$column;

    if (!array_key_exists($key, $cache)) {
        $cache[$key] = Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }

    return $cache[$key];
}

private function orderStatusesForRevenue(): array
{
    return [
        'processing',
        'packed',
        'dispatched',
        'shipped',
        'ready_for_collection',
        'delivered',
        'completed',
    ];
}

private function percentChange(float $current, float $previous): ?float
{
    if ($previous == 0.0) {
        return null;
    }

    return (($current - $previous) / $previous) * 100;
}

private function orderSum(Carbon $from, Carbon $to, string $column, ?array $statuses = null): float
{
    if (!$this->hasColumn('Orders_Placed_T', $column)) {
        return 0.0;
    }

    $query = DB::table('Orders_Placed_T')->whereBetween('created_at', [$from, $to]);

    if ($statuses) {
        $query->whereIn('Status', $statuses);
    }

    return (float) $query->sum($column);
}

public function operationsSummary(Request $request)
{
    [$from, $to, $range] = $this->dashboardRange($request);
    [$previousFrom, $previousTo] = $this->previousRange($from, $to);
    $salesStatuses = $this->orderStatusesForRevenue();

    $ordersBase = DB::table('Orders_Placed_T')->whereBetween('created_at', [$from, $to]);
    $previousOrdersBase = DB::table('Orders_Placed_T')->whereBetween('created_at', [$previousFrom, $previousTo]);

    $revenue = (float) (clone $ordersBase)->whereIn('Status', $salesStatuses)->sum('Total_Price');
    $previousRevenue = (float) (clone $previousOrdersBase)->whereIn('Status', $salesStatuses)->sum('Total_Price');

    $orders = (int) (clone $ordersBase)->count();
    $previousOrders = (int) (clone $previousOrdersBase)->count();

    $customers = (int) DB::table('Customers_Master_T')
        ->whereNull('deleted_at')
        ->whereBetween('created_at', [$from, $to])
        ->count();

    $previousCustomers = (int) DB::table('Customers_Master_T')
        ->whereNull('deleted_at')
        ->whereBetween('created_at', [$previousFrom, $previousTo])
        ->count();

    $averageOrder = $orders > 0 ? $revenue / $orders : 0.0;
    $previousAverageOrder = $previousOrders > 0 ? $previousRevenue / $previousOrders : 0.0;

    $shipping = $this->orderSum($from, $to, 'Shipping_Price');
    $productDiscounts = $this->orderSum($from, $to, 'Product_Discount_Amount');
    $loyaltyDiscounts = $this->orderSum($from, $to, 'Loyalty_Discount_Amount');
    $loyaltyPoints = $this->orderSum($from, $to, 'Loyalty_Points_Redeemed');
    $vat = $this->hasColumn('Orders_Placed_T', 'VAT')
        ? $this->orderSum($from, $to, 'VAT')
        : $this->orderSum($from, $to, 'Tax');

    $subtotalColumn = $this->hasColumn('Orders_Placed_T', 'Original_Sub_Total_Price')
        ? 'Original_Sub_Total_Price'
        : ($this->hasColumn('Orders_Placed_T', 'Sub_Total_Price') ? 'Sub_Total_Price' : 'Total_Price');

    $subtotal = $this->orderSum($from, $to, $subtotalColumn);

    $statusBreakdown = (clone $ordersBase)
        ->selectRaw("LOWER(ISNULL(Status, 'unknown')) as label")
        ->selectRaw('COUNT(*) as total')
        ->groupBy(DB::raw("LOWER(ISNULL(Status, 'unknown'))"))
        ->orderByDesc('total')
        ->get()
        ->map(fn ($row) => [
            'label' => $row->label,
            'total' => (int) $row->total,
        ]);

    $fulfillmentExpression = $this->hasColumn('Orders_Placed_T', 'Delivery_Type')
        ? "LOWER(ISNULL(NULLIF(Delivery_Type, ''), CASE WHEN Shippers_Id IS NOT NULL THEN 'ship_to_address' WHEN Location_Id IS NOT NULL THEN 'local_pickup' ELSE 'not_set' END))"
        : "LOWER(CASE WHEN Shippers_Id IS NOT NULL THEN 'ship_to_address' WHEN Location_Id IS NOT NULL THEN 'local_pickup' ELSE 'not_set' END)";

    $fulfillmentBreakdown = (clone $ordersBase)
        ->selectRaw("$fulfillmentExpression as label")
        ->selectRaw('COUNT(*) as total')
        ->groupBy(DB::raw($fulfillmentExpression))
        ->orderByDesc('total')
        ->get()
        ->map(fn ($row) => [
            'label' => $row->label,
            'total' => (int) $row->total,
        ]);

    $paymentMethods = DB::table('Sales_Transactions_Details_T as d')
        ->join('Sales_Transaction_Header_T as h', 'h.id', '=', 'd.Sales_Transaction_Header_Id')
        ->leftJoin('Orders_Placed_T as o', 'o.id', '=', 'h.Orders_Placed_Id')
        ->whereBetween('d.created_at', [$from, $to])
        ->selectRaw("LOWER(ISNULL(NULLIF(d.Payment_Method, ''), 'unknown')) as label")
        ->selectRaw('COUNT(*) as total')
        ->selectRaw('SUM(ISNULL(d.Payment_Amount, 0)) as amount')
        ->groupBy(DB::raw("LOWER(ISNULL(NULLIF(d.Payment_Method, ''), 'unknown'))"))
        ->orderByDesc('amount')
        ->get()
        ->map(fn ($row) => [
            'label' => $row->label,
            'total' => (int) $row->total,
            'amount' => (float) $row->amount,
        ]);

    $paymentStatuses = DB::table('Sales_Transactions_Details_T')
        ->whereBetween('created_at', [$from, $to])
        ->selectRaw("LOWER(ISNULL(NULLIF(Payment_Status, ''), 'unknown')) as label")
        ->selectRaw('COUNT(*) as total')
        ->selectRaw('SUM(ISNULL(Payment_Amount, 0)) as amount')
        ->groupBy(DB::raw("LOWER(ISNULL(NULLIF(Payment_Status, ''), 'unknown'))"))
        ->orderByDesc('amount')
        ->get()
        ->map(fn ($row) => [
            'label' => $row->label,
            'total' => (int) $row->total,
            'amount' => (float) $row->amount,
        ]);

    $topCustomers = DB::table('Orders_Placed_T as o')
        ->leftJoin('Customers_Master_T as c', 'c.id', '=', 'o.Customers_Id')
        ->whereBetween('o.created_at', [$from, $to])
        ->groupBy('o.Customers_Id', 'c.Customer_Full_Name', 'c.Customer_Code')
        ->selectRaw('o.Customers_Id as customer_id')
        ->selectRaw("COALESCE(c.Customer_Full_Name, 'Walk-in customer') as customer_name")
        ->selectRaw('COALESCE(c.Customer_Code, ?) as customer_code', ['-'])
        ->selectRaw('COUNT(o.id) as total_orders')
        ->selectRaw('SUM(ISNULL(o.Total_Price, 0)) as total_spent')
        ->orderByDesc(DB::raw('SUM(ISNULL(o.Total_Price, 0))'))
        ->limit(8)
        ->get()
        ->map(fn ($row) => [
            'customer_id' => $row->customer_id,
            'customer_name' => $row->customer_name,
            'customer_code' => $row->customer_code,
            'total_orders' => (int) $row->total_orders,
            'total_spent' => (float) $row->total_spent,
        ]);

    $vendorSummary = [
        'orders' => 0,
        'sales' => 0.0,
        'commission' => 0.0,
        'paid_out' => 0.0,
        'top_vendors' => [],
    ];

    if (Schema::hasTable('Orders_Placed_Vendors_T')) {
        $vendorSummary['orders'] = (int) DB::table('Orders_Placed_Vendors_T')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $vendorSummary['sales'] = (float) DB::table('Orders_Placed_Vendors_T')
            ->whereBetween('created_at', [$from, $to])
            ->sum('Total');

        $vendorSummary['commission'] = (float) DB::table('Orders_Placed_Vendors_T')
            ->whereBetween('created_at', [$from, $to])
            ->sum('Commission_Amount');

        if ($this->hasColumn('Orders_Placed_Vendors_T', 'Payout_Amount')) {
            $vendorSummary['paid_out'] = (float) DB::table('Orders_Placed_Vendors_T')
                ->whereBetween('created_at', [$from, $to])
                ->sum('Payout_Amount');
        }

        $vendorSummary['top_vendors'] = DB::table('Orders_Placed_Vendors_T as ov')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'ov.Vendor_Id')
            ->whereBetween('ov.created_at', [$from, $to])
            ->groupBy('ov.Vendor_Id', 'v.Vendor_Name', 'v.Vendor_Code')
            ->selectRaw('ov.Vendor_Id as vendor_id')
            ->selectRaw("COALESCE(v.Vendor_Name, 'ISC stock') as vendor_name")
            ->selectRaw('COALESCE(v.Vendor_Code, ?) as vendor_code', ['-'])
            ->selectRaw('COUNT(ov.id) as vendor_orders')
            ->selectRaw('SUM(ISNULL(ov.Total, 0)) as total_sales')
            ->selectRaw('SUM(ISNULL(ov.Commission_Amount, 0)) as commission')
            ->orderByDesc(DB::raw('SUM(ISNULL(ov.Total, 0))'))
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'vendor_id' => $row->vendor_id,
                'vendor_name' => $row->vendor_name,
                'vendor_code' => $row->vendor_code,
                'vendor_orders' => (int) $row->vendor_orders,
                'total_sales' => (float) $row->total_sales,
                'commission' => (float) $row->commission,
            ]);
    }

    return response()->json([
        'range' => [
            'key' => $range,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
        ],
        'cards' => [
            'revenue' => [
                'value' => $revenue,
                'previous' => $previousRevenue,
                'delta' => $this->percentChange($revenue, $previousRevenue),
            ],
            'orders' => [
                'value' => $orders,
                'previous' => $previousOrders,
                'delta' => $this->percentChange((float) $orders, (float) $previousOrders),
            ],
            'customers' => [
                'value' => $customers,
                'previous' => $previousCustomers,
                'delta' => $this->percentChange((float) $customers, (float) $previousCustomers),
            ],
            'average_order' => [
                'value' => $averageOrder,
                'previous' => $previousAverageOrder,
                'delta' => $this->percentChange($averageOrder, $previousAverageOrder),
            ],
        ],
        'financials' => [
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'vat' => $vat,
            'product_discounts' => $productDiscounts,
            'loyalty_discounts' => $loyaltyDiscounts,
            'loyalty_points' => $loyaltyPoints,
            'grand_total' => (float) (clone $ordersBase)->sum('Total_Price'),
        ],
        'status_breakdown' => $statusBreakdown,
        'fulfillment_breakdown' => $fulfillmentBreakdown,
        'payment_methods' => $paymentMethods,
        'payment_statuses' => $paymentStatuses,
        'top_customers' => $topCustomers,
        'vendor_summary' => $vendorSummary,
    ]);
}

public function intentInsights(Request $request)
{
    [$from, $to, $range] = $this->dashboardRange($request);
    $cartHasDeletedAt = $this->hasColumn('Customers_Carts_T', 'deleted_at');
    $favoriteHasDeletedAt = $this->hasColumn('Favorites_Master_T', 'deleted_at');

    $cartActiveExpression = $cartHasDeletedAt
        ? 'SUM(CASE WHEN c.deleted_at IS NULL THEN 1 ELSE 0 END)'
        : 'COUNT(*)';

    $cartRemovedExpression = $cartHasDeletedAt
        ? 'SUM(CASE WHEN c.deleted_at IS NULL THEN 0 ELSE 1 END)'
        : '0';

    $favoriteActiveExpression = $favoriteHasDeletedAt
        ? 'SUM(CASE WHEN f.deleted_at IS NULL THEN 1 ELSE 0 END)'
        : 'COUNT(*)';

    $favoriteRemovedExpression = $favoriteHasDeletedAt
        ? 'SUM(CASE WHEN f.deleted_at IS NULL THEN 0 ELSE 1 END)'
        : '0';

    $cartProducts = DB::table('Customers_Carts_T as c')
        ->leftJoin('Products_Master_T as p', 'p.id', '=', 'c.Products_Id')
        ->whereBetween('c.created_at', [$from, $to])
        ->groupBy('c.Products_Id')
        ->selectRaw('c.Products_Id as product_id')
        ->selectRaw("COALESCE(MAX(p.Product_Name), CONCAT('Product #', c.Products_Id)) as product_name")
        ->selectRaw('COALESCE(MAX(p.Product_Code), ?) as product_code', ['-'])
        ->selectRaw('COUNT(*) as total_events')
        ->selectRaw('SUM(ISNULL(c.Quantity, 0)) as total_quantity')
        ->selectRaw("$cartActiveExpression as active_count")
        ->selectRaw("$cartRemovedExpression as removed_count")
        ->orderByDesc(DB::raw('COUNT(*)'))
        ->limit(10)
        ->get()
        ->map(fn ($row) => [
            'product_id' => $row->product_id,
            'product_name' => $row->product_name,
            'product_code' => $row->product_code,
            'total_events' => (int) $row->total_events,
            'total_quantity' => (int) $row->total_quantity,
            'active_count' => (int) $row->active_count,
            'removed_count' => (int) $row->removed_count,
        ]);

    $favoriteProducts = DB::table('Favorites_Master_T as f')
        ->leftJoin('Products_Master_T as p', 'p.id', '=', 'f.Products_Id')
        ->whereBetween('f.created_at', [$from, $to])
        ->groupBy('f.Products_Id')
        ->selectRaw('f.Products_Id as product_id')
        ->selectRaw("COALESCE(MAX(p.Product_Name), CONCAT('Product #', f.Products_Id)) as product_name")
        ->selectRaw('COALESCE(MAX(p.Product_Code), ?) as product_code', ['-'])
        ->selectRaw('COUNT(*) as total_events')
        ->selectRaw("$favoriteActiveExpression as active_count")
        ->selectRaw("$favoriteRemovedExpression as removed_count")
        ->orderByDesc(DB::raw('COUNT(*)'))
        ->limit(10)
        ->get()
        ->map(fn ($row) => [
            'product_id' => $row->product_id,
            'product_name' => $row->product_name,
            'product_code' => $row->product_code,
            'total_events' => (int) $row->total_events,
            'active_count' => (int) $row->active_count,
            'removed_count' => (int) $row->removed_count,
        ]);

    return response()->json([
        'range' => [
            'key' => $range,
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
        ],
        'cart_products' => $cartProducts,
        'favorite_products' => $favoriteProducts,
        'totals' => [
            'cart_events' => $cartProducts->sum('total_events'),
            'cart_removed' => $cartProducts->sum('removed_count'),
            'favorite_events' => $favoriteProducts->sum('total_events'),
            'favorite_removed' => $favoriteProducts->sum('removed_count'),
        ],
    ]);
}

}
