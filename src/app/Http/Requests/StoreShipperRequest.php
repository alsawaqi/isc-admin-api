<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipperRequest extends FormRequest
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
             
            'Shippers_Contact_Position' => ['nullable','string','max:255'],
            'Shippers_Contact_Office_No' => ['nullable','string','max:50'],
            'Shippers_Contact_GSM_No' => ['nullable','string','max:50'],
            'Shippers_Contact_Email_Address' => ['nullable','email','max:255'],
            'Shippers_Is_Primary' => ['boolean'],
        ];
    }
}
