<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShipperContactRequest extends FormRequest
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
            'Shippers_Contact_Name' => ['sometimes','string','max:255'],
            'Shippers_Contact_Position' => ['sometimes','string','max:255'],
            'Shippers_Contact_Office_No' => ['sometimes','string','max:50'],
            'Shippers_Contact_GSM_No' => ['sometimes','string','max:50'],
            'Shippers_Contact_Email_Address' => ['sometimes','email','max:255'],
            'Shippers_Is_Primary' => ['boolean'],
        ];
    }
}
