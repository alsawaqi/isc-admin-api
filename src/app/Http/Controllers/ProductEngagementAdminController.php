<?php

namespace App\Http\Controllers;

use App\Models\ProductQuestion;
use App\Models\ProductQuestionAnswer;
use App\Models\ProductReview;
use App\Models\ProductReviewReply;
use App\Support\Reviews\ProductReviewModeration;
use Illuminate\Http\Request;

class ProductEngagementAdminController extends Controller
{
    public function reviews(Request $request)
    {
        $query = ProductReview::query()
            ->with([
                'product:id,Product_Name,Product_Name_Ar,Slug,Vendor_Id',
                'customer:id,Customer_Full_Name',
                'replies' => fn ($q) => $q->oldest(),
            ])
            ->latest();

        $this->applyCommonFilters($query, $request, ['Body', 'Title']);

        if ($request->filled('rating')) {
            $query->where('Rating', (int) $request->input('rating'));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function moderateReview(Request $request, ProductReview $review)
    {
        $validated = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $review->forceFill(ProductReviewModeration::snapshot(
            $validated['status'],
            $validated['note'] ?? null,
            $request->user()?->id
        ))->save();

        return response()->json(['data' => $review->fresh()]);
    }

    public function replyReview(Request $request, ProductReview $review)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $payload = ProductReviewModeration::replySnapshot('admin', $request->user()?->id, $validated['body']);
        $reply = ProductReviewReply::create([
            'Product_Review_Id' => $review->id,
            ...$payload,
        ]);

        return response()->json(['data' => $reply], 201);
    }

    public function questions(Request $request)
    {
        $query = ProductQuestion::query()
            ->with([
                'product:id,Product_Name,Product_Name_Ar,Slug,Vendor_Id',
                'customer:id,Customer_Full_Name',
                'answers' => fn ($q) => $q->oldest(),
            ])
            ->latest();

        $this->applyCommonFilters($query, $request, ['Question']);

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function moderateQuestion(Request $request, ProductQuestion $question)
    {
        $validated = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $question->forceFill(ProductReviewModeration::snapshot(
            $validated['status'],
            $validated['note'] ?? null,
            $request->user()?->id
        ))->save();

        return response()->json(['data' => $question->fresh()]);
    }

    public function answerQuestion(Request $request, ProductQuestion $question)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $reply = ProductReviewModeration::replySnapshot('admin', $request->user()?->id, $validated['body']);

        $answer = ProductQuestionAnswer::create([
            'Product_Question_Id' => $question->id,
            'Answer_Type' => $reply['Reply_Type'],
            'User_Id' => $reply['User_Id'],
            'Body' => $reply['Body'],
            'Status' => $reply['Status'],
        ]);

        return response()->json(['data' => $answer], 201);
    }

    /**
     * @param list<string> $textColumns
     */
    private function applyCommonFilters($query, Request $request, array $textColumns): void
    {
        if ($request->filled('status')) {
            $query->where('Status', $request->input('status'));
        }

        if ($request->filled('product_id')) {
            $query->where('Products_Id', (int) $request->input('product_id'));
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search, $textColumns) {
                foreach ($textColumns as $index => $column) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $q->{$method}($column, 'like', "%{$search}%");
                }

                $q->orWhereHas('product', fn ($product) => $product->where('Product_Name', 'like', "%{$search}%"));
            });
        }
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 5), 100);
    }
}
