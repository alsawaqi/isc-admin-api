<?php

namespace App\Http\Controllers;

use App\Services\ProductHierarchyDisplayOrderService;
use Illuminate\Http\Request;
use RuntimeException;

final class ProductHierarchyDisplayOrderController extends Controller
{
    public function index(Request $request, ProductHierarchyDisplayOrderService $ordering)
    {
        $this->authorizeOrdering($request);
        $validated = $request->validate([
            'level' => ['required', 'string', 'in:department,sub_department,sub_sub_department'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            [$paginator, $revision] = $this->consistentRead(
                $ordering,
                fn () => $ordering->paginate(
                    $validated['level'],
                    isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
                    $validated['search'] ?? null,
                    (int) ($validated['per_page'] ?? 50),
                    (int) ($validated['page'] ?? 1),
                ),
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception, 'The hierarchy order could not be loaded.');
        }

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($item) => $ordering->present($validated['level'], $item))
                ->values(),
            'meta' => [
                'level' => $validated['level'],
                'parent_id' => isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'revision' => $revision,
            ],
        ]);
    }

    public function search(Request $request, ProductHierarchyDisplayOrderService $ordering)
    {
        $this->authorizeOrdering($request);
        if (! $request->filled('search') && $request->filled('q')) {
            $request->merge(['search' => $request->query('q')]);
        }
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:1', 'max:150'],
            'per_level' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            [$results, $revision] = $this->consistentRead(
                $ordering,
                fn () => $ordering->search(
                    $validated['search'],
                    (int) ($validated['per_level'] ?? 20),
                ),
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception, 'The hierarchy search could not be completed.');
        }

        return response()->json([
            'data' => $results,
            'meta' => [
                'search' => $validated['search'],
                'per_level' => (int) ($validated['per_level'] ?? 20),
                'revision' => $revision,
            ],
        ]);
    }

    public function move(Request $request, ProductHierarchyDisplayOrderService $ordering)
    {
        $this->authorizeOrdering($request);
        $validated = $request->validate([
            'level' => ['required', 'string', 'in:department,sub_department,sub_sub_department'],
            'id' => ['required', 'integer', 'min:1'],
            'before_id' => ['nullable', 'integer', 'min:1'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $ordering->moveBefore(
                $validated['level'],
                (int) $validated['id'],
                isset($validated['before_id']) ? (int) $validated['before_id'] : null,
                (int) $validated['revision'],
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception, 'The hierarchy order could not be updated.');
        }

        return response()->json([
            'data' => $result['item'],
            'meta' => [
                'revision' => $result['revision'],
                'moved' => $result['moved'],
            ],
        ]);
    }

    public function reset(Request $request, ProductHierarchyDisplayOrderService $ordering)
    {
        $this->authorizeOrdering($request);
        $validated = $request->validate([
            'level' => ['required', 'string', 'in:department,sub_department,sub_sub_department'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $result = $ordering->resetToDefault(
                $validated['level'],
                isset($validated['parent_id']) ? (int) $validated['parent_id'] : null,
                (int) $validated['revision'],
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception, 'The hierarchy order could not be reset.');
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'level' => $validated['level'],
                'parent_id' => isset($validated['parent_id'])
                    ? (int) $validated['parent_id']
                    : null,
                'revision' => $result['revision'],
                'changed' => $result['changed'],
                'updated_count' => $result['updated_count'],
            ],
        ]);
    }

    private function authorizeOrdering(Request $request): void
    {
        abort_unless(
            $request->user()?->can('product category'),
            403,
            'You do not have permission to manage product category ordering.',
        );
    }

    /** @return array{mixed, int} */
    private function consistentRead(
        ProductHierarchyDisplayOrderService $ordering,
        callable $read,
    ): array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $ordering->currentRevision();
            $value = $read();
            $after = $ordering->currentRevision();

            if ($before === $after) {
                return [$value, $after];
            }
        }

        throw new RuntimeException(
            'The category order changed while it was loading. Please retry.',
            409,
        );
    }

    private function error(RuntimeException $exception, string $fallback)
    {
        $status = in_array($exception->getCode(), [404, 409, 422], true)
            ? $exception->getCode()
            : 500;

        return response()->json([
            'message' => $status === 500 ? $fallback : $exception->getMessage(),
        ], $status);
    }
}
