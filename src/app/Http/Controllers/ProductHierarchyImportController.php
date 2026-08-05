<?php

namespace App\Http\Controllers;

use App\Models\ProductHierarchyImportJob;
use App\Services\ProductHierarchyExportService;
use App\Services\ProductHierarchyImportService;
use App\Services\ProductHierarchyXlsxParser;
use App\Support\ProductHierarchyCode;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

class ProductHierarchyImportController extends Controller
{
    public function preview(
        Request $request,
        ProductHierarchyXlsxParser $parser,
        ProductHierarchyImportService $importer
    ) {
        $this->authorizeImport($request);

        $validated = $request->validate([
            'code_period' => ['required', 'string', 'regex:/^20\d{2}-(0[1-9]|1[0-2])$/'],
            'file' => [
                'required',
                'file',
                'max:'.config('product_hierarchy_import.max_upload_kilobytes', 10240),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! $value instanceof UploadedFile || strtolower($value->getClientOriginalExtension()) !== 'xlsx') {
                        $fail('The file must use the .xlsx format.');
                    }
                },
            ],
        ]);

        $file = $request->file('file');
        try {
            $parsed = $parser->parse($file->getRealPath());
            $parsed['code_period'] = ProductHierarchyCode::normalizePeriod($validated['code_period']);
            $analysis = $importer->analyze($parsed);
            $parsed['_allocation_digest'] = $importer->planDigest($analysis);
            unset($analysis['_plan']);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => ['file' => [$exception->getMessage()]],
            ], 422);
        }

        $payload = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $sha256 = hash_file('sha256', $file->getRealPath());
        $token = (string) Str::uuid();
        $expiresAt = now()->addMinutes(config('product_hierarchy_import.preview_ttl_minutes', 15));
        $safeName = basename(str_replace('\\', '/', $file->getClientOriginalName()));

        ProductHierarchyImportJob::pruneForNewPreview((int) $request->user()->id);
        ProductHierarchyImportJob::create([
            'Token' => $token,
            'User_Id' => $request->user()->id,
            'File_Name' => mb_substr($safeName, 0, 255),
            'File_Size' => $file->getSize(),
            'File_Sha256' => $sha256,
            'Payload_Digest' => hash('sha256', $payload),
            'Canonical_Payload' => $payload,
            'Summary' => json_encode($analysis['summary'], JSON_THROW_ON_ERROR),
            'Status' => 'pending',
            'Can_Commit' => $analysis['can_commit'],
            'Expires_At' => $expiresAt,
        ]);

        $analysis['issues_truncated'] = count($analysis['issues']) > 500;
        $analysis['issues'] = array_slice($analysis['issues'], 0, 500);

        return response()->json([
            'success' => true,
            'data' => [
                'preview_token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
                'file' => ['name' => $safeName, 'size' => $file->getSize(), 'sha256' => $sha256, 'sheet' => $parsed['sheet']],
                ...$analysis,
            ],
        ]);
    }

    public function commit(Request $request, ProductHierarchyImportService $importer)
    {
        $this->authorizeImport($request);
        $validated = $request->validate([
            'preview_token' => ['required', 'uuid'],
        ]);

        try {
            $result = $importer->commit($validated['preview_token'], (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            $status = in_array($exception->getCode(), [403, 409, 422], true) ? $exception->getCode() : 500;

            return response()->json([
                'success' => false,
                'message' => $status === 500 ? 'The hierarchy import could not be completed.' : $exception->getMessage(),
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product hierarchy imported successfully.',
            'data' => $result,
        ]);
    }

    public function history(Request $request, ProductHierarchyImportService $importer)
    {
        $this->authorizeImport($request);

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $importer->history((int) ($validated['limit'] ?? 25)),
        ]);
    }

    public function rollback(Request $request, ProductHierarchyImportService $importer, int $job)
    {
        $this->authorizeImport($request);

        try {
            $result = $importer->rollback($job, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            $status = in_array($exception->getCode(), [404, 409, 422], true) ? $exception->getCode() : 500;

            return response()->json([
                'success' => false,
                'message' => $status === 500 ? 'The hierarchy import could not be rolled back.' : $exception->getMessage(),
            ], $status);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product hierarchy import rolled back successfully.',
            'data' => $result,
        ]);
    }

    public function export(Request $request, ProductHierarchyExportService $exporter)
    {
        $this->authorizeImport($request);

        try {
            $export = $exporter->export();
        } catch (RuntimeException $exception) {
            $status = in_array($exception->getCode(), [422], true) ? $exception->getCode() : 500;

            return response()->json([
                'success' => false,
                'message' => $status === 500 ? 'The product hierarchy export could not be created.' : $exception->getMessage(),
            ], $status);
        }

        return response($export['contents'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless(
            $request->user()?->can(config('product_hierarchy_import.permission', 'import product categories')),
            403,
            'You do not have permission to import product categories.'
        );
    }
}
