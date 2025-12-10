<?php

namespace App\Http\Controllers;


use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\ProductSpecificationValue;
use App\Models\ProductSpecificationDescription;


class ProductSpecificationDescriptionController extends Controller
{
    //
    public function index(Request $request)
    {
        $request->validate([
            'product_sub_sub_department_id' => ['required', 'integer', 'exists:Products_Sub_Sub_Department_T,id'],
        ]);

        $rows = ProductSpecificationDescription::with(['values' => function ($q) {
            $q->orderBy('value');
        }])
            ->where('product_sub_sub_department_id', $request->product_sub_sub_department_id)
            ->orderBy('sort_order')
            ->get();

        // Return the exact shape the front-end expects
        return $rows->map(fn($d) => [
            'id' => $d->id,
            'Product_Specification_Description_Name' => $d->Product_Specification_Description_Name,
            'values' => $d->values->map(fn($v) => ['id' => $v->id, 'value' => $v->value])->values(),
        ]);
    }



    public function store(Request $request)
    {
        $validated = $request->validate([
            'sub_sub_category_id'   => ['required', 'integer', 'exists:Products_Sub_Sub_Department_T,id'],
            'specs'                 => ['required', 'array', 'min:1'],
            'specs.*.name'          => ['required', 'string', 'max:255'],
            'specs.*.input_type'    => ['required', 'in:text,number,select,multiselect,boolean'],
            'specs.*.is_required'   => ['boolean'],
            'specs.*.is_active'     => ['boolean'],
            'specs.*.sort_order'    => ['integer'],

            // allow string arrays, and require when select/multiselect
            'specs.*.values'        => ['nullable', 'array'],
            'specs.*.values.*'      => ['nullable', 'string', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($validated) {
                foreach ($validated['specs'] as $i => $spec) {

                    // Normalize values: handle both ["Red","Blue"] and [{"value":"Red"}, {"value":"Blue"}]
                    $values = collect($spec['values'] ?? [])
                        ->map(function ($v) {
                            if (is_array($v)) {
                                return trim((string)($v['value'] ?? ''));
                            }
                            return trim((string)$v);
                        })
                        ->filter() // remove empties
                        ->unique(fn($v) => mb_strtolower($v)) // case-insensitive unique
                        ->values();

                    $desc = ProductSpecificationDescription::create([
                        'product_sub_sub_department_id'          => $validated['sub_sub_category_id'],
                        'Product_Specification_Description_Name' => $spec['name'],
                        'input_type'   => $spec['input_type'],
                        // keep options_json for UI filters if you want
                        'options_json' => $values->isNotEmpty()
                            ? json_encode($values->all(), JSON_UNESCAPED_UNICODE)
                            : null,
                        'is_required'  => $spec['is_required'] ?? false,
                        'sort_order'   => $spec['sort_order'] ?? ($i + 1),
                        'is_active'    => $spec['is_active'] ?? false,
                        'Created_By'   => Auth::id(),
                    ]);

                    // Seed Product_Specification_Value_T
                    foreach ($values as $val) {
                        ProductSpecificationValue::firstOrCreate(
                            [
                                'product_specification_description_id' => $desc->id,
                                'value' => $val,
                            ],
                            [
                                'normalized_value' => mb_strtolower($val),
                                'slug' => Str::slug($val),
                            ]
                        );
                    }
                }
            });

            return response()->json(['message' => 'Specifications saved'], 201);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to save specifications: ' . $e->getMessage()], 500);
        }
    }

    public function getfilter(Request $request)
    {
        $request->validate([
            'sub_sub_category_id' => ['required', 'integer', 'exists:Products_Sub_Sub_Department_T,id'],
        ]);

        try {
            $descs = ProductSpecificationDescription::with(['values' => function ($q) {
                $q->orderBy('value');
            }])
                ->where('product_sub_sub_department_id', $request->sub_sub_category_id)
                ->get();




            return $descs->map(fn($d) => [
                'id'         => $d->id,
                'name'       => $d->Product_Specification_Description_Name,
                'input_type' => $d->input_type,
                'is_required' => (bool) $d->is_required,
                'is_active'  => (bool) $d->is_active,
                'sort_order' => (int) ($d->sort_order ?? 0),
                'values'     => $d->values->map(fn($v) => ['id' => $v->id, 'value' => $v->value])->values(),
            ]);
        } catch (\Throwable $e) {
            Log::error('tree error', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            // DEV ONLY: show exact error so you can see it in Network tab
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function bulkUpsert(Request $request)
    {
        $validated = $request->validate([
            'sub_sub_category_id'       => ['required', 'integer', 'exists:Products_Sub_Sub_Department_T,id'],
            'specs'                     => ['required', 'array'],
            'specs.*.id'                => ['nullable', 'integer'],
            'specs.*.name'              => ['required', 'string', 'max:255'],
            'specs.*.input_type'        => ['required', 'in:text,number,select,multiselect,boolean'],
            'specs.*.is_required'       => ['boolean'],
            'specs.*.is_active'         => ['boolean'],
            'specs.*.sort_order'        => ['integer'],
            'specs.*.values'            => ['array'],
            'specs.*.values.*.id'       => ['nullable', 'integer'],
            'specs.*.values.*.value'    => ['required', 'string', 'max:255'],
            'remove_description_ids'    => ['array'],
            'remove_description_ids.*'  => ['integer'],
            'remove_value_ids'          => ['array'],
            'remove_value_ids.*'        => ['integer'],
        ]);

        DB::transaction(function () use ($validated) {
            // 1) Deletes first (values, then descriptions)
            if (!empty($validated['remove_value_ids'])) {
                ProductSpecificationValue::whereIn('id', $validated['remove_value_ids'])->delete();
            }
            if (!empty($validated['remove_description_ids'])) {
                ProductSpecificationDescription::whereIn('id', $validated['remove_description_ids'])->delete();
            }

            // 2) Upsert descriptions + values
            foreach ($validated['specs'] as $i => $spec) {
                // upsert description
                if (!empty($spec['id'])) {
                    $desc = ProductSpecificationDescription::where('id', $spec['id'])
                        ->where('product_sub_sub_department_id', $validated['sub_sub_category_id'])
                        ->firstOrFail();

                    $desc->update([
                        'Product_Specification_Description_Name' => $spec['name'],
                        'input_type'   => $spec['input_type'],
                        'options_json' => collect($spec['values'] ?? [])->pluck('value')->values(), // keep for UI
                        'is_required'  => $spec['is_required'] ?? false,
                        'is_active'    => $spec['is_active'] ?? false,
                        'sort_order'   => $spec['sort_order'] ?? ($i + 1),
                    ]);
                } else {
                    $desc = ProductSpecificationDescription::create([
                        'product_sub_sub_department_id'          => $validated['sub_sub_category_id'],
                        'Product_Specification_Description_Name' => $spec['name'],
                        'input_type'   => $spec['input_type'],
                        'options_json' => collect($spec['values'] ?? [])->pluck('value')->values(),
                        'is_required'  => $spec['is_required'] ?? false,
                        'is_active'    => $spec['is_active'] ?? false,
                        'sort_order'   => $spec['sort_order'] ?? ($i + 1),
                    ]);
                }

                // upsert values for this description
                $incoming = collect($spec['values'] ?? [])
                    ->map(fn($v) => ['id' => $v['id'] ?? null, 'value' => trim($v['value'])])
                    ->filter(fn($v) => $v['value'] !== '')
                    ->unique(fn($v) => mb_strtolower($v['value'])) // case-insensitive unique
                    ->values();

                // Update/create each incoming
                foreach ($incoming as $val) {
                    if ($val['id']) {
                        ProductSpecificationValue::where('id', $val['id'])
                            ->where('product_specification_description_id', $desc->id)
                            ->update([
                                'value'            => $val['value'],
                                'normalized_value' => mb_strtolower($val['value']),
                                'slug'             => Str::slug($val['value']),
                            ]);
                    } else {
                        ProductSpecificationValue::firstOrCreate(
                            [
                                'product_specification_description_id' => $desc->id,
                                'value' => $val['value'],
                            ],
                            [
                                'normalized_value' => mb_strtolower($val['value']),
                                'slug' => Str::slug($val['value']),
                            ]
                        );
                    }
                }
            }
        });

        return response()->json(['message' => 'Saved'], 200);
    }


    public function destroy($id)
    {
        ProductSpecificationDescription::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function destroyValue($id)
    {
        ProductSpecificationValue::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
