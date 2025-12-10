<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\ProductSpecificationValue;
use App\Models\ProductSpecificationProduct;
use App\Models\ProductSpecificationDescription;

class ProductSpecificationProductController extends Controller
{
    //
    public function getProductSpecificationsForEdit($productId)
    {
        $product = ProductMaster::findOrFail($productId);
        $subSubDeptId = $product->Product_Sub_Sub_Department_Id;

        $descriptions = ProductSpecificationDescription::with(['values' => fn($q) => $q->orderBy('value')])
            ->where('product_sub_sub_department_id', $subSubDeptId)
            ->orderBy('sort_order')
            ->get();

        // 👇 Use the real column names if your table uses PascalCase
        $existing = ProductSpecificationProduct::where('Product_Id', $productId)
            ->get()
            ->keyBy('Product_Specification_Description_Id');

        $merged = $descriptions->map(function ($desc) use ($existing) {
            $row = $existing->get($desc->id);

            return [
                'id'        => $row?->id,
                'product_specification_description_id' => (int) $desc->id,
                'Product_Specification_Description_Name' => $desc->Product_Specification_Description_Name,

                'options'   => $desc->values->map(fn($v) => [
                    'id'    => (int) $v->id,
                    'value' => $v->value,
                ])->values(),

                // 👇 pick the right column for the selected value id
                'product_specification_value_id' => $row?->product_specification_value_id
                    ? (int) $row->product_specification_value_id
                    : null,
            ];
        });

        return response()->json($merged);
    }



    public function storeOrUpdate(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:Products_Master_T,id'],
            'specifications' => ['required', 'array', 'min:1'],
            'specifications.*.product_specification_description_id' => ['required', 'integer', 'exists:Product_Specification_Description_T,id'],
            'specifications.*.product_specification_value_id'       => ['required', 'integer', 'exists:Product_Specification_Value_T,id'],
        ]);

        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'rows' => []];

        DB::transaction(function () use ($validated, &$summary) {
            foreach ($validated['specifications'] as $i => $row) {
                $productId = (int) $validated['product_id'];
                $descId    = (int) $row['product_specification_description_id'];
                $valueId   = (int) $row['product_specification_value_id'];

                // Guard: the value must belong to the description
                $belongs = ProductSpecificationValue::where('id', $valueId)
                    ->where('product_specification_description_id', $descId)
                    ->exists();

                if (!$belongs) {
                    abort(422, "Value {$valueId} does not belong to description {$descId}.");
                }

                // Read current
                $current = DB::table('Product_Specification_Product_T')
                    ->where('Product_Id', $productId)
                    ->where('Product_Specification_Description_Id', $descId)
                    ->value('product_specification_value_id'); // 👈 PascalCase

                // Upsert with exact column names (match only on product+description)
                DB::table('Product_Specification_Product_T')->updateOrInsert(
                    [
                        'Product_Id'                           => $productId,
                        'Product_Specification_Description_Id' => $descId,
                    ],
                    [
                        'product_specification_value_id'       => $valueId,    // 👈 PascalCase
                        'Created_By'                           => Auth::id(),
                    ]
                );

                // Track summary rows
                if ($current === null) {
                    $summary['created']++;
                    $summary['rows'][] = [
                        'index'   => $i,
                        'action'  => 'created',
                        'product' => $productId,
                        'desc'    => $descId,
                        'value'   => $valueId,
                    ];
                } elseif ((int)$current !== $valueId) {
                    $summary['updated']++;
                    $summary['rows'][] = [
                        'index'   => $i,
                        'action'  => 'updated',
                        'product' => $productId,
                        'desc'    => $descId,
                        'from'    => (int)$current,
                        'to'      => $valueId,
                    ];
                } else {
                    $summary['unchanged']++;
                    $summary['rows'][] = [
                        'index'   => $i,
                        'action'  => 'unchanged',
                        'product' => $productId,
                        'desc'    => $descId,
                        'value'   => $valueId,
                    ];
                }
            }
        });


        $descIds  = collect($summary['rows'])->pluck('desc')->unique()->values();
        $valueIds = collect($summary['rows'])
            ->flatMap(fn($r) => array_filter([
                $r['value'] ?? null,
                $r['from']  ?? null,
                $r['to']    ?? null,
            ], fn($v) => $v !== null))
            ->unique()
            ->values();

        $productName = DB::table('Products_Master_T')
            ->where('id', (int)$validated['product_id'])
            ->value('Product_Name');

        $descNames = ProductSpecificationDescription::whereIn('id', $descIds)
            ->pluck('Product_Specification_Description_Name', 'id'); // [id => name]

        $valueLabels = ProductSpecificationValue::whereIn('id', $valueIds)
            ->pluck('value', 'id'); // [id => label]

        $summary['enriched_rows'] = collect($summary['rows'])->map(function ($r) use ($productName, $descNames, $valueLabels) {
            $row = [
                'index'        => $r['index'],
                'action'       => $r['action'],
                'product_name' => $productName,
                'spec_name'    => $descNames[$r['desc']] ?? (string)$r['desc'],
            ];
            if ($r['action'] === 'updated') {
                $row['from_label'] = isset($r['from']) ? ($valueLabels[$r['from']] ?? (string)$r['from']) : null;
                $row['to_label']   = isset($r['to'])   ? ($valueLabels[$r['to']]   ?? (string)$r['to'])   : null;
            } else {
                $row['value_label'] = isset($r['value']) ? ($valueLabels[$r['value']] ?? (string)$r['value']) : null;
            }
            return $row;
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Product specifications saved.',
            'summary' => $summary,
        ], 200);
    }






    public function store(Request $request)
    {
        // --- Basic manual guards (no Validator) ---
        $productId = (int) ($request->input('product_id') ?? 0);
        if ($productId <= 0) {
            return response()->json(['message' => 'product_id is required'], 422);
        }

        // Make sure product exists (adjust table/model if different)
        $productExists = DB::table('Products_Master_T')->where('id', $productId)->exists();
        if (!$productExists) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $specs = $request->input('specifications');
        if (!is_array($specs) || empty($specs)) {
            return response()->json(['message' => 'The specifications field must be a non-empty array'], 422);
        }

        // Optional: collect row-level errors instead of aborting mid-loop
        $rowErrors = [];

        try {
            DB::transaction(function () use ($specs, $productId, &$rowErrors) {
                foreach ($specs as $i => $row) {
                    $descId  = (int) ($row['product_specification_description_id'] ?? 0);
                    $valueId = (int) ($row['product_specification_value_id'] ?? 0);

                    if ($descId <= 0 || $valueId <= 0) {
                        $rowErrors[] = [
                            'index' => $i,
                            'message' => 'Both product_specification_description_id and product_specification_value_id are required and must be integers.'
                        ];
                        continue;
                    }

                    // Ensure the value belongs to the description
                    $belongs = ProductSpecificationValue::where('id', $valueId)
                        ->where('product_specification_description_id', $descId)
                        ->exists();

                    if (!$belongs) {
                        $rowErrors[] = [
                            'index' => $i,
                            'message' => 'Value does not belong to the selected specification.'
                        ];
                        continue;
                    }

                    // (Optional) ensure the description exists (fast fail)
                    $descExists = DB::table('Product_Specification_Description_T')->where('id', $descId)->exists();
                    if (!$descExists) {
                        $rowErrors[] = [
                            'index' => $i,
                            'message' => 'Specification description not found.'
                        ];
                        continue;
                    }

                    // Upsert one row per (product, description)
                    ProductSpecificationProduct::updateOrCreate(
                        [
                            'product_id' => $productId,
                            'product_specification_description_id' => $descId,
                            'Created_By' => Auth::id(),
                        ],
                        [
                            'product_specification_value_id' => $valueId,
                        ]
                    );
                }

                // If you want to fail the whole batch when there are row errors, uncomment:
                // if (!empty($rowErrors)) {
                //     throw new \RuntimeException('Row errors present'); // will trigger catch/rollback
                // }
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to save product specifications.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        // If there were partial row errors, return them with 207/422; up to you.
        if (!empty($rowErrors)) {
            return response()->json([
                'message' => 'Saved with some row errors.',
                'errors'  => $rowErrors,
            ], 207); // Multi-Status (or use 422 if you prefer to signal client-side fixes)
        }

        return response()->json(['message' => 'Product specifications saved.'], 201);
    }
}
