<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVendorUserRequest extends FormRequest
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
         $id = $this->route('id'); // vendor user id from URL

        return [
            'Vendor_Id' => ['required', 'integer', Rule::exists('Vendors_Master_T', 'Id')],

            'User_Name' => ['required', 'string', 'max:150'],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('Secx_Vendors_Users_Master_T', 'email')->ignore($id, 'id'),
            ],

            // password is optional on update (only change if not empty)
            'password' => ['nullable', 'string', 'min:8'],

            'Phone' => ['nullable', 'string', 'max:50'],
            'Gsm'   => ['nullable', 'string', 'max:50'],

            'Company_Code' => ['nullable', 'string', 'max:50'],
            'Merchant_Id'  => ['nullable', 'string', 'max:12'],

            'Status' => ['nullable', Rule::in(['active', 'inactive', 'blocked', 'pending'])],
        ];
    }
}
