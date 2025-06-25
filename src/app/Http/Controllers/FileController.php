<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    //


     public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:5120',
        ]);

        $path = Storage::disk('r2')->put('uploads', $request->file('file'));

        if (!$path) {
            return back()->withErrors(['file' => 'File upload failed. Check your R2 configuration.']);
        }

        return back()->with([
            'success' => 'File uploaded successfully!',
            'path' => $path,
            'url' => Storage::disk('r2')->url($path),
        ]);
    }

}
