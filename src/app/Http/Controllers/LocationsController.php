<?php

namespace App\Http\Controllers;

use App\Models\Locations;
use Illuminate\Http\Request;
use App\Helpers\CodeGenerator;
use Illuminate\Support\Facades\Auth;

class LocationsController extends Controller
{
    

    public function index(Request $request)
    { 

        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);


            $query = Locations::query();

            $query->with('city');

        if ($search) {
            $query->where('Location_Name', 'like', "%{$search}%");
        }

            // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Location_Name', 'created_at'])) {
            $sortBy = 'id'; 
        }


        $query->orderBy($sortBy, $sortDir);


        return response()->json(
            $query->paginate($perPage)
        );


    }
    
    
    
    public function store(Request $request)
    {


      $Location_Code = CodeGenerator::createCode('LOCAL', 'Geox_Location_Master_T', 'Location_Code');
 
       $location =   Locations::create([
            'Location_Code' => $Location_Code,
            'City_Id' => $request->City_Id,
            'Location_Name' => $request->Location_Name,
            'Location_Name_Ar' => $request->Location_Name_Ar,
            'Created_By' => Auth::id(),
        ]);


        return response()->json($location, 201);


    }


    public function update(Request $request, Locations $location)
    {
        $location->update([
            'City_Id' => $request->City_Id,
            'Location_Name' => $request->Location_Name,
            'Location_Name_Ar' => $request->Location_Name_Ar,
           
        ]);

        return response()->json($location);
    }

    public function destroy(Locations $location)
    {
        $location->delete();

        return response()->json(null, 204);
    }
}
