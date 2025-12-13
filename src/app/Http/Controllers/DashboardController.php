<?php

namespace App\Http\Controllers;

use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

}