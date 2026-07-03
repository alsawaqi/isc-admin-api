<?php

namespace App\Http\Controllers;

use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TitlesController extends Controller
{
    public function index(Request $request)
    {
        $search  = $request->query('search');
        $sortBy  = $request->query('sortBy', 'id');      // default
        $sortDir = $request->query('sortDir', 'desc');   // default
        $perPage = (int) $request->query('per_page', 10);

        $query = Title::query();
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('Title_Name', 'like', "%{$search}%")
                  ->orWhere('Title_Name_Ar', 'like', "%{$search}%");
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Title_Name', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    public function index_all()
    {
        return response()->json(
            Title::where('Is_Active', true)
                ->orderBy('Title_Name')
                ->get(['id', 'Title_Name', 'Title_Name_Ar'])
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'name_ar'   => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $title = Title::create([
            'Title_Name'    => $request->name,
            'Title_Name_Ar' => $request->name_ar,
            'Is_Active'     => $request->boolean('is_active', true),
            'Created_By'    => $request->user()->id,
        ]);

        return response()->json(['message' => 'Title created successfully', 'data' => $title], 201);
    }

    public function update(Request $request, Title $title)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'name_ar'   => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $title->Title_Name = $request->name;
        $title->Title_Name_Ar = $request->name_ar;
        if ($request->has('is_active')) {
            $title->Is_Active = $request->boolean('is_active');
        }
        $title->save();

        return response()->json(['message' => 'Title updated successfully', 'data' => $title], 200);
    }

    public function toggle(Title $title)
    {
        $title->Is_Active = ! $title->Is_Active;
        $title->save();

        return response()->json(['message' => 'Title status updated successfully', 'data' => $title], 200);
    }

    public function destroy(Title $title)
    {
        if ($this->isInUse($title->id)) {
            return response()->json(['message' => 'Title is in use and cannot be deleted'], 422);
        }

        $title->delete();

        return response()->json(['message' => 'Title deleted successfully'], 200);
    }

    private function isInUse(int $titleId): bool
    {
        if (Schema::hasColumn('Customers_Contact_T', 'Title_Id')
            && DB::table('Customers_Contact_T')->where('Title_Id', $titleId)->exists()) {
            return true;
        }

        if (Schema::hasColumn('Shipper_Contacts_T', 'Title_Id')
            && DB::table('Shipper_Contacts_T')->where('Title_Id', $titleId)->exists()) {
            return true;
        }

        return false;
    }
}
