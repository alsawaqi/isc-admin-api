<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;

class StoreShipperContactRequest extends FormRequest
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
        $titleRule = ['nullable', 'integer'];
        if (Schema::hasTable('Titles_Master_T')) {
            $titleRule[] = 'exists:Titles_Master_T,id';
        }

        $designationRule = ['nullable', 'integer'];
        if (Schema::hasTable('Designations_Master_T')) {
            $designationRule[] = 'exists:Designations_Master_T,id';
        }

        return [
            'Shippers_Contact_Name' => ['required','string','max:255'],
            'Shippers_Contact_Position' => ['nullable','string','max:255'],
            'Shippers_Contact_Office_No' => ['nullable','string','max:50'],
            'Shippers_Contact_GSM_No' => ['nullable','string','max:50'],
            'Shippers_Contact_Email_Address' => ['nullable','email','max:255'],
            'Shippers_Is_Primary' => ['boolean'],
            'Contact_Department_Id' => ['nullable','integer'],
            'Title_Id' => $titleRule,
            'Designation_Id' => $designationRule,
        ];
    }
}
