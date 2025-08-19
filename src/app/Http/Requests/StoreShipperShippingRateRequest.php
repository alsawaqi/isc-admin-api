<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipperShippingRateRequest extends FormRequest
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
            'Shippers_Destination_Rate_Volume'     => ['required','boolean'],
            'Shippers_Destination_Rate_Weight'     => ['required','boolean'],
            'Shippers_Destination_Rate_Applicable' => ['required','boolean'],

            // optional normalized FKs
            'Shippers_Destination_Country_Id'  => ['nullable','integer','exists:Geox_Country_Master_T,Country_Id'],
            'Shippers_Destination_Region_Id'   => ['nullable','integer','exists:Geox_Region_Master_T,id'],
            'Shippers_Destination_District_Id' => ['nullable','integer','exists:Geox_District_Master_T,id'],
        ];
    }
}
