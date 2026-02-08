<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVendorUserRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            // ✅ vendor must exist
            'Vendor_Id' => ['required','integer', Rule::exists('Vendors_Master_T', 'id')],

            'User_Name' => ['required','string','max:150'],

            // ✅ must be unique (very important)
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('Secx_Vendors_Users_Master_T', 'email'),
            ],

            'password'  => ['required','string','min:8'],

            // optional profile/contact
            'Phone' => ['nullable','string','max:50'],
            'Gsm'   => ['nullable','string','max:50'],

            // optional legacy columns
            'Company_Code' => ['nullable','string','max:50'],
            'Merchant_Id'  => ['nullable','string','max:12'],

            // ✅ keep status controlled (don’t allow random strings)
            'Status' => ['nullable', Rule::in(['active','inactive','blocked','pending'])],
        ];
    }
}
