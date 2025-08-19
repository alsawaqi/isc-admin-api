<?php

namespace App\Http\Requests\Geo;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDistrictRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
         $id = $this->route('district')?->District_Id ?? $this->route('district');
        return [
            'Region_Id'      => ['sometimes','integer','exists:Geox_Region_Master_T,Region_Id'],
            'District_Code'  => ['sometimes','string','max:20', Rule::unique('Geox_District_Master_T','District_Code')->ignore($id, 'District_Id')],
            'District_Name'  => ['sometimes','string','max:255'],
            'District_Name_Ar' => ['nullable','string','max:255'],
        ];
    }
}
