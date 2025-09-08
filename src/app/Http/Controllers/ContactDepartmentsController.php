<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ContactDepartments;

class ContactDepartmentsController extends Controller
{
    //


    public function index()
    {
        $departments = ContactDepartments::orderBy('id','DESC')->get();
        return response()->json($departments);
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
