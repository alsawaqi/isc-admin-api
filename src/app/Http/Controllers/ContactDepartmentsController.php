<?php

namespace App\Http\Controllers;

use Nette\Utils\Json;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\ContactDepartments;

class ContactDepartmentsController extends Controller
{
    //


    public function index(Request $request) : JsonResponse
    {
          
        $search   = $request->query('search');
        $sortBy   = $request->query('sortBy', 'id');      // default
        $sortDir  = $request->query('sortDir', 'desc');   // default
        $perPage  = (int) $request->query('per_page', 10);


        $query = ContactDepartments::query();


        if ($search) {
            $query->where('Department_Name', 'like', "%{$search}%");
        }



         // whitelist sortable columns
        if (! in_array($sortBy, ['id', 'Transaction_Number', 'created_at'])) {
            $sortBy = 'id';
        }

        $query->orderBy($sortBy, $sortDir);

        return response()->json(
            $query->paginate($perPage)
        );

        
    }



    public function index_all() : JsonResponse
    {
        return response()->json(
            ContactDepartments::orderBy('id', 'DESC')->get()
        );
    }   

    public function store(Request $request)
    {
        $request->validate([
            'Department_Name' => 'required|string|max:255',
            'Department_Initials' => 'required|string|max:10',
        ]);

        try{
         $contactDepartment = ContactDepartments::create([
            'Department_Name' => $request->Department_Name,
            'Department_Initials' => $request->Department_Initials,
        ]);
        }catch(\Exception $e){
            return response()->json(['message' => 'Error creating contact department', 'error' => $e->getMessage()], 500);
        }

      
       

        return response()->json(['message' => 'Contact department created successfully', 'data' => $contactDepartment], 201);
    }   
}
