<?php

namespace App\Http\Controllers;

use App\Services\ProductImportAnalysis;
use App\Services\ProductImportService;
use App\Services\ProductImportWorkbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductImportController extends Controller
{
    public function lookups(Request $request, ProductImportAnalysis $analysis)
    {
        return response()->json(['data' => [...$analysis->lookups(), 'can_create_brands' => $request->user()->can('product brands')]]);
    }

    public function preview(Request $request, ProductImportWorkbook $reader, ProductImportService $service)
    {
        if (is_string($request->input('options'))) {
            $request->merge(['options' => json_decode($request->input('options'), true)]);
        }
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'], 'options' => ['required', 'array'],
            'options.price_column' => ['required', 'in:min,max'], 'options.blank_stock' => ['required', 'in:reject,zero'],
            'options.product_type_id' => ['nullable', 'integer', 'min:1'], 'options.category_map' => ['sometimes', 'array', 'max:2000'],
            'options.category_map.*' => ['nullable', 'integer', 'min:1'], 'options.brand_map' => ['sometimes', 'array', 'max:2000'],
            'options.brand_map.*' => ['nullable', 'regex:/^(?:new|[1-9][0-9]*)$/'],
        ]);
        try {
            $file = $request->file('file');
            $workbook = $reader->parse($file->getRealPath());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $result = $service->preview($workbook, $data['options'], $request->user(), $file->getClientOriginalName(), hash_file('sha256', $file->getRealPath()));

        return response()->json(['data' => $result]);
    }

    public function commit(Request $request, ProductImportService $service)
    {
        $data = $request->validate(['preview_token' => ['required', 'uuid']]);
        try {
            return response()->json(['data' => $service->commit($data['preview_token'], $request->user())]);
        } catch (RuntimeException $e) {
            if (! in_array($e->getCode(), [403, 404, 409, 422], true)) {
                throw $e;
            }

            return response()->json(['message' => $e->getMessage()], $e->getCode());
        }
    }

    public function history(Request $request)
    {
        return response()->json(['data' => DB::table('Product_Import_Jobs_T')->where('User_Id', $request->user()->id)->where('Status', 'committed')
            ->orderByDesc('id')->limit(20)->get(['id', 'File_Name', 'Committed_At', 'Result'])->map(function ($job) {
                $job->Result = json_decode($job->Result, true);

                return $job;
            })]);
    }
}
