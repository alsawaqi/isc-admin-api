<?php

namespace App\Http\Controllers;

use App\Models\Designation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DesignationsController extends Controller
{
    public function index(Request $request)
    {
        $search  = $request->query('search');
        $sortBy  = $request->query('sortBy', 'id');      // default
        $sortDir = $request->query('sortDir', 'desc');   // default
        $perPage = (int) $request->query('per_page', 10);

        $query = Designation::query();
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('Designation_Name', 'like', "%{$search}%")
                  ->orWhere('Designation_Name_Ar', 'like', "%{$search}%");
            });
        }

        // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Designation_Name', 'created_at'])) {
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
            Designation::where('Is_Active', true)
                ->orderBy('Designation_Name')
                ->get(['id', 'Designation_Name', 'Designation_Name_Ar'])
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'name_ar'   => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $designation = Designation::create([
            'Designation_Name'    => $request->name,
            'Designation_Name_Ar' => $request->name_ar,
            'Is_Active'           => $request->boolean('is_active', true),
            'Created_By'          => $request->user()->id,
        ]);

        return response()->json(['message' => 'Designation created successfully', 'data' => $designation], 201);
    }

    public function update(Request $request, Designation $designation)
    {
        $request->validate([
            'name'      => 'required|string|max:100',
            'name_ar'   => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $designation->Designation_Name = $request->name;
        $designation->Designation_Name_Ar = $request->name_ar;
        if ($request->has('is_active')) {
            $designation->Is_Active = $request->boolean('is_active');
        }
        $designation->save();

        return response()->json(['message' => 'Designation updated successfully', 'data' => $designation], 200);
    }

    public function toggle(Designation $designation)
    {
        $designation->Is_Active = ! $designation->Is_Active;
        $designation->save();

        return response()->json(['message' => 'Designation status updated successfully', 'data' => $designation], 200);
    }

    public function destroy(Designation $designation)
    {
        if ($this->isInUse($designation->id)) {
            return response()->json(['message' => 'Designation is in use and cannot be deleted'], 422);
        }

        $designation->delete();

        return response()->json(['message' => 'Designation deleted successfully'], 200);
    }

    private function isInUse(int $designationId): bool
    {
        return Schema::hasColumn('Shipper_Contacts_T', 'Designation_Id')
            && DB::table('Shipper_Contacts_T')->where('Designation_Id', $designationId)->exists();
    }
}
