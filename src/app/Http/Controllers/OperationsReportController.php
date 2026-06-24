<?php

namespace App\Http\Controllers;

use App\Support\Reports\OperationsReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class OperationsReportController extends Controller
{
    private const REPORTS = [
        'net_sales',
        'refunds',
        'returns',
        'cancellation_reasons',
        'vendor_performance',
        'stock_movement',
        'payouts',
    ];

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $requested = $filters['report'];
        $reports = $requested === 'all' ? self::REPORTS : [$requested];

        $payload = [];
        foreach ($reports as $report) {
            $payload[$report] = $this->rowsFor($report, $filters);
        }

        return response()->json([
            'success' => true,
            'filters' => $filters,
            'summary' => OperationsReportBuilder::summaryFromRows([
                'net_sales' => $payload['net_sales'] ?? $this->rowsFor('net_sales', $filters),
                'payouts' => $payload['payouts'] ?? $this->rowsFor('payouts', $filters),
            ]),
            'reports' => $payload,
        ]);
    }

    public function export(Request $request)
    {
        $filters = $this->filters($request, allowAll: false);
        $rows = $this->rowsFor($filters['report'], $filters);
        $csv = OperationsReportBuilder::toCsv($rows);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="operations-' . $filters['report'] . '.csv"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request, bool $allowAll = true): array
    {
        $reportRules = $allowAll
            ? Rule::in(array_merge(['all'], self::REPORTS))
            : Rule::in(self::REPORTS);

        $data = $request->validate([
            'report' => ['nullable', 'string', $reportRules],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'vendor_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        return [
            'report' => $data['report'] ?? ($allowAll ? 'all' : 'net_sales'),
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
            'vendor_id' => $data['vendor_id'] ?? null,
            'limit' => (int) ($data['limit'] ?? 250),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function rowsFor(string $report, array $filters): array
    {
        return match ($report) {
            'net_sales' => $this->netSales($filters),
            'refunds' => $this->adjustments($filters, 'refund'),
            'returns' => $this->adjustments($filters, 'return'),
            'cancellation_reasons' => $this->cancellationReasons($filters),
            'vendor_performance' => $this->vendorPerformance($filters),
            'stock_movement' => $this->stockMovement($filters),
            'payouts' => $this->payouts($filters),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function netSales(array $filters): array
    {
        if (!Schema::hasTable('Orders_Placed_Details_T')) {
            return [];
        }

        $sold = $this->detailMoneyExpression('Sold_Amount', 'COALESCE(d.Subtotal, d.Price * d.Quantity, 0)');
        $refunded = $this->detailMoneyExpression('Refunded_Amount', '0');
        $net = $this->detailMoneyExpression('Net_Amount', "{$sold} - {$refunded}");
        $returnedQty = Schema::hasColumn('Orders_Placed_Details_T', 'Returned_Quantity') ? 'COALESCE(d.Returned_Quantity, 0)' : '0';

        $q = DB::table('Orders_Placed_Details_T as d')
            ->leftJoin('Products_Master_T as p', 'p.id', '=', 'd.Products_Id')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'p.Vendor_Id')
            ->selectRaw('
                d.Products_Id as product_id,
                COALESCE(p.Product_Name, CONCAT(\'Product #\', d.Products_Id)) as product,
                COALESCE(v.Vendor_Name, \'ISC\') as vendor,
                SUM(' . $sold . ') as sold_amount,
                SUM(' . $refunded . ') as refunded_amount,
                SUM(' . $returnedQty . ') as returned_quantity,
                SUM(' . $net . ') as net_amount,
                COUNT(DISTINCT d.Orders_Placed_Id) as orders_count
            ')
            ->groupBy('d.Products_Id', 'p.Product_Name', 'v.Vendor_Name')
            ->orderByDesc(DB::raw('SUM(' . $net . ')'));

        $this->applyDate($q, 'd.created_at', $filters);
        $this->applyVendor($q, 'p.Vendor_Id', $filters);

        return $this->normalizeRows($q->limit($filters['limit'])->get());
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function adjustments(array $filters, string $kind): array
    {
        if (!Schema::hasTable('Orders_Placed_Details_Adjustments_T')) {
            return [];
        }

        $q = DB::table('Orders_Placed_Details_Adjustments_T as a')
            ->leftJoin('Products_Master_T as p', 'p.id', '=', 'a.Products_Id')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'a.Vendor_Id')
            ->selectRaw('
                a.Adjustment_Type as adjustment_type,
                COALESCE(NULLIF(a.Reason, \'\'), \'No reason supplied\') as reason,
                COALESCE(p.Product_Name, CONCAT(\'Product #\', a.Products_Id)) as product,
                COALESCE(v.Vendor_Name, \'ISC\') as vendor,
                SUM(COALESCE(a.Quantity, 0)) as quantity,
                SUM(COALESCE(a.Restock_Quantity, 0)) as restock_quantity,
                SUM(COALESCE(a.Amount, 0)) as amount,
                COUNT(*) as adjustments_count
            ')
            ->where('a.Adjustment_Type', 'like', "%{$kind}%")
            ->groupBy('a.Adjustment_Type', 'a.Reason', 'p.Product_Name', 'a.Products_Id', 'v.Vendor_Name')
            ->orderByDesc(DB::raw('SUM(COALESCE(a.Amount, 0))'));

        $this->applyDate($q, 'a.created_at', $filters);
        $this->applyVendor($q, 'a.Vendor_Id', $filters);

        return $this->normalizeRows($q->limit($filters['limit'])->get());
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function cancellationReasons(array $filters): array
    {
        if (!Schema::hasTable('Orders_Placed_Details_Cancelled_T')) {
            return [];
        }

        $q = DB::table('Orders_Placed_Details_Cancelled_T as c')
            ->leftJoin('Orders_Placed_Details_T as d', 'd.id', '=', 'c.Orders_Placed_Details_Id')
            ->leftJoin('Products_Master_T as p', 'p.id', '=', 'd.Products_Id')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'p.Vendor_Id')
            ->selectRaw('
                COALESCE(NULLIF(c.Cancellation_Reason, \'\'), \'No reason supplied\') as reason,
                COALESCE(v.Vendor_Name, \'ISC\') as vendor,
                COUNT(*) as cancelled_lines,
                SUM(COALESCE(d.Subtotal, d.Price * d.Quantity, 0)) as cancelled_amount
            ')
            ->groupBy('c.Cancellation_Reason', 'v.Vendor_Name')
            ->orderByDesc(DB::raw('COUNT(*)'));

        $this->applyDate($q, 'c.created_at', $filters);
        $this->applyVendor($q, 'p.Vendor_Id', $filters);

        return $this->normalizeRows($q->limit($filters['limit'])->get());
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function vendorPerformance(array $filters): array
    {
        if (!Schema::hasTable('Orders_Placed_Vendors_T')) {
            return [];
        }

        $refunded = Schema::hasColumn('Orders_Placed_Vendors_T', 'Refunded_Amount') ? 'COALESCE(vo.Refunded_Amount, 0)' : '0';
        $returned = Schema::hasColumn('Orders_Placed_Vendors_T', 'Returned_Quantity') ? 'COALESCE(vo.Returned_Quantity, 0)' : '0';
        $net = Schema::hasColumn('Orders_Placed_Vendors_T', 'Net_Sub_Total') ? 'COALESCE(vo.Net_Sub_Total, vo.Sub_Total, 0)' : 'COALESCE(vo.Sub_Total, 0)';
        $commission = Schema::hasColumn('Orders_Placed_Vendors_T', 'Adjusted_Commission_Amount') ? 'COALESCE(vo.Adjusted_Commission_Amount, vo.Commission_Amount, 0)' : 'COALESCE(vo.Commission_Amount, 0)';
        $payout = Schema::hasColumn('Orders_Placed_Vendors_T', 'Net_Payout_Amount') ? 'COALESCE(vo.Net_Payout_Amount, vo.Payout_Amount, 0)' : 'COALESCE(vo.Payout_Amount, 0)';

        $q = DB::table('Orders_Placed_Vendors_T as vo')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'vo.Vendor_Id')
            ->selectRaw("
                vo.Vendor_Id as vendor_id,
                COALESCE(v.Vendor_Name, 'ISC') as vendor,
                COUNT(*) as vendor_orders,
                SUM(COALESCE(vo.Sub_Total, 0)) as gross_sales,
                SUM({$refunded}) as refunded_amount,
                SUM({$returned}) as returned_quantity,
                SUM({$net}) as net_sales,
                SUM({$commission}) as commission_amount,
                SUM({$payout}) as payout_amount,
                SUM(CASE WHEN vo.Payout_Status = 'paid' THEN 1 ELSE 0 END) as paid_payouts
            ")
            ->groupBy('vo.Vendor_Id', 'v.Vendor_Name')
            ->orderByDesc(DB::raw("SUM({$net})"));

        $this->applyDate($q, 'vo.created_at', $filters);
        $this->applyVendor($q, 'vo.Vendor_Id', $filters);

        return $this->normalizeRows($q->limit($filters['limit'])->get());
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function stockMovement(array $filters): array
    {
        if (!Schema::hasTable('Product_Stock_Movements_T')) {
            return [];
        }

        $q = DB::table('Product_Stock_Movements_T as sm')
            ->leftJoin('Products_Master_T as p', 'p.id', '=', 'sm.Products_Id')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'sm.Vendor_Id')
            ->selectRaw('
                sm.Movement_Type as movement_type,
                COALESCE(p.Product_Name, CONCAT(\'Product #\', sm.Products_Id)) as product,
                COALESCE(v.Vendor_Name, \'ISC\') as vendor,
                SUM(COALESCE(sm.Quantity_Delta, 0)) as net_quantity_delta,
                SUM(COALESCE(sm.Quantity, 0)) as moved_quantity,
                COUNT(*) as movement_count,
                MAX(sm.created_at) as last_movement_at
            ')
            ->groupBy('sm.Movement_Type', 'p.Product_Name', 'sm.Products_Id', 'v.Vendor_Name')
            ->orderByDesc(DB::raw('MAX(sm.created_at)'));

        $this->applyDate($q, 'sm.created_at', $filters);
        $this->applyVendor($q, 'sm.Vendor_Id', $filters);

        return $this->normalizeRows($q->limit($filters['limit'])->get());
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function payouts(array $filters): array
    {
        if (!Schema::hasTable('Orders_Placed_Vendors_T')) {
            return [];
        }

        $net = Schema::hasColumn('Orders_Placed_Vendors_T', 'Net_Sub_Total') ? 'COALESCE(vo.Net_Sub_Total, vo.Sub_Total, 0)' : 'COALESCE(vo.Sub_Total, 0)';
        $commission = Schema::hasColumn('Orders_Placed_Vendors_T', 'Adjusted_Commission_Amount') ? 'COALESCE(vo.Adjusted_Commission_Amount, vo.Commission_Amount, 0)' : 'COALESCE(vo.Commission_Amount, 0)';
        $storedPayout = Schema::hasColumn('Orders_Placed_Vendors_T', 'Net_Payout_Amount') ? "COALESCE(vo.Payout_Amount, vo.Net_Payout_Amount, {$net} - {$commission})" : "COALESCE(vo.Payout_Amount, {$net} - {$commission})";

        $q = DB::table('Orders_Placed_Vendors_T as vo')
            ->leftJoin('Vendors_Master_T as v', 'v.id', '=', 'vo.Vendor_Id')
            ->selectRaw("
                vo.id as vendor_order_id,
                vo.Vendor_Order_Code as vendor_order_code,
                vo.Orders_Placed_Id as order_id,
                COALESCE(v.Vendor_Name, 'ISC') as vendor,
                vo.Status as vendor_order_status,
                COALESCE(vo.Payout_Status, 'unpaid') as payout_status,
                {$net} as net_sub_total,
                {$commission} as commission_amount,
                {$storedPayout} as payout_amount,
                vo.Payout_At as payout_at,
                vo.Payout_Reference as payout_reference
            ")
            ->orderByDesc('vo.updated_at');

        $this->applyDate($q, 'vo.updated_at', $filters);
        $this->applyVendor($q, 'vo.Vendor_Id', $filters);

        return $this->normalizeRows($q->limit($filters['limit'])->get());
    }

    private function detailMoneyExpression(string $column, string $fallback): string
    {
        return Schema::hasColumn('Orders_Placed_Details_T', $column)
            ? "COALESCE(d.{$column}, {$fallback})"
            : $fallback;
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array<string, mixed> $filters
     */
    private function applyDate($query, string $column, array $filters): void
    {
        if (!empty($filters['date_from'])) {
            $query->where($column, '>=', $filters['date_from'] . ' 00:00:00');
        }

        if (!empty($filters['date_to'])) {
            $query->where($column, '<=', $filters['date_to'] . ' 23:59:59');
        }
    }

    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @param array<string, mixed> $filters
     */
    private function applyVendor($query, string $column, array $filters): void
    {
        if (!empty($filters['vendor_id'])) {
            $query->where($column, (int) $filters['vendor_id']);
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows($rows): array
    {
        return collect($rows)->map(function (object $row) {
            return collect((array) $row)->mapWithKeys(function (mixed $value, string $key) {
                if (is_numeric($value) && !str_ends_with($key, '_id') && !str_ends_with($key, '_count') && !str_contains($key, 'quantity')) {
                    return [$key => number_format((float) $value, 3, '.', '')];
                }

                return [$key => $value];
            })->all();
        })->values()->all();
    }
}
