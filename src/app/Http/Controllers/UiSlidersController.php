<?php

namespace App\Http\Controllers;

use App\Helpers\CodeGenerator;
use App\Models\SystemParameterUiSlider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class UiSlidersController extends Controller
{
    private function extractSingleFile(Request $request)
    {
        // accept "file" or "image" to be flexible
        $f = $request->file('file') ?? $request->file('image');

        // if frontend sends file[] by mistake, take first
        if (is_array($f)) {
            $f = $f[0] ?? null;
        }

        return $f;
    }

    public function index()
    {
        $rows = SystemParameterUiSlider::query()
            ->orderBy('Sort_Order')
            ->orderByDesc('id')
            ->get();

        // return also computed url (optional; frontend can use r2Url + Image_Path)
        $rows->transform(function ($r) {
            $r->image_url = $r->Image_Path ? Storage::disk('r2')->url($r->Image_Path) : null;
            return $r;
        });

        return response()->json($rows);
    }

    public function store(Request $request)
    {
        $request->validate([
            'Title' => ['nullable', 'string', 'max:150'],
            'Title_Ar' => ['nullable', 'string', 'max:150'],
            'Description' => ['nullable', 'string'],
            'Description_Ar' => ['nullable', 'string'],
            'Button_Text' => ['nullable', 'string', 'max:50'],
            'Button_Text_Ar' => ['nullable', 'string', 'max:50'],
            'Link_Url' => ['nullable', 'string', 'max:500'],
            'Sort_Order' => ['nullable', 'integer', 'min:0'],
            'Is_Active' => ['nullable', 'boolean'],
            'Active_From' => ['nullable', 'date'],
            'Active_To' => ['nullable', 'date'],
            'file' => ['required'], // validate below using file extractor
        ]);

        $file = $this->extractSingleFile($request);
        if (!$file) {
            return response()->json(['message' => 'No image file provided.'], 422);
        }

        // validate as image
        $request->validate([
            'file' => ['file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'], // 5MB
        ]);

        // Upload to R2 (same concept as product images) :contentReference[oaicite:1]{index=1}
        $path = Storage::disk('r2')->put('Sliders', $file, 'public');

        $row = SystemParameterUiSlider::create([
            'Slider_Code' => CodeGenerator::createCode('SLDR', 'System_Parameter_UI_Sliders_T', 'Slider_Code'),

            'Title' => $request->input('Title'),
            'Title_Ar' => $request->input('Title_Ar'),
            'Description' => $request->input('Description'),
            'Description_Ar' => $request->input('Description_Ar'),
            'Button_Text' => $request->input('Button_Text'),
            'Button_Text_Ar' => $request->input('Button_Text_Ar'),
            'Link_Url' => $request->input('Link_Url'),

            'Image_Path' => $path,
            'Image_Size' => $file->getSize(),
            'Image_Extension' => $file->getClientOriginalExtension(),
            'Image_Type' => $file->getMimeType(),

            'Sort_Order' => (int) ($request->input('Sort_Order', 0)),
            'Is_Active' => $request->boolean('Is_Active', true),
            'Active_From' => $request->input('Active_From'),
            'Active_To' => $request->input('Active_To'),

            'Created_By' => Auth::id(),
            'Created_Date' => now(),
        ]);

        $row->image_url = Storage::disk('r2')->url($row->Image_Path);

        return response()->json(['data' => $row], 201);
    }

    public function update(Request $request, $id)
    {
        $row = SystemParameterUiSlider::findOrFail($id);

        $request->validate([
            'Title' => ['nullable', 'string', 'max:150'],
            'Title_Ar' => ['nullable', 'string', 'max:150'],
            'Description' => ['nullable', 'string'],
            'Description_Ar' => ['nullable', 'string'],
            'Button_Text' => ['nullable', 'string', 'max:50'],
            'Button_Text_Ar' => ['nullable', 'string', 'max:50'],
            'Link_Url' => ['nullable', 'string', 'max:500'],
            'Sort_Order' => ['nullable', 'integer', 'min:0'],
            'Is_Active' => ['nullable', 'boolean'],
            'Active_From' => ['nullable', 'date'],
            'Active_To' => ['nullable', 'date'],
            'file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        // optional image replacement
        $file = $this->extractSingleFile($request);
        if ($file) {
            // delete old
            if ($row->Image_Path) {
                Storage::disk('r2')->delete($row->Image_Path);
            }

            $path = Storage::disk('r2')->put('Sliders', $file, 'public');

            $row->Image_Path = $path;
            $row->Image_Size = $file->getSize();
            $row->Image_Extension = $file->getClientOriginalExtension();
            $row->Image_Type = $file->getMimeType();
        }

        foreach ([
            'Title', 'Title_Ar', 'Description', 'Description_Ar',
            'Button_Text', 'Button_Text_Ar', 'Link_Url',
            'Active_From', 'Active_To'
        ] as $k) {
            if ($request->has($k)) $row->{$k} = $request->input($k);
        }

        if ($request->has('Sort_Order')) $row->Sort_Order = (int) $request->input('Sort_Order');
        if ($request->has('Is_Active')) $row->Is_Active = $request->boolean('Is_Active');

        $row->save();

        $row->image_url = $row->Image_Path ? Storage::disk('r2')->url($row->Image_Path) : null;

        return response()->json(['data' => $row]);
    }

    public function destroy($id)
    {
        $row = SystemParameterUiSlider::findOrFail($id);

        if ($row->Image_Path) {
            Storage::disk('r2')->delete($row->Image_Path);
        }

        $row->delete();

        return response()->json(['message' => 'Slider deleted.']);
    }

    public function toggle($id)
    {
        $row = SystemParameterUiSlider::findOrFail($id);
        $row->Is_Active = !$row->Is_Active;
        $row->save();

        return response()->json(['data' => $row]);
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer'],
            'items.*.Sort_Order' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($request->input('items') as $item) {
            SystemParameterUiSlider::where('id', $item['id'])
                ->update(['Sort_Order' => (int) $item['Sort_Order']]);
        }

        return response()->json(['message' => 'Reordered successfully.']);
    }
}
