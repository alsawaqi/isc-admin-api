<?php

namespace App\Http\Requests\Geo;

use Illuminate\Foundation\Http\FormRequest;

class StoreDistrictRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'Region_Id'      => ['required','integer','exists:Geox_Region_Master_T,Region_Id'],
            'District_Code'  => ['required','string','max:20','unique:Geox_District_Master_T,District_Code'],
            'District_Name'  => ['required','string','max:255'],
            'District_Name_Ar' => ['nullable','string','max:255'],
        ];
    }
}
