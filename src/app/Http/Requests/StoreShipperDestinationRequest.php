<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipperDestinationRequest extends FormRequest
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
            'Shippers_Destination_Country'             => ['nullable','string','max:100'],
            'Shippers_Destination_Region'              => ['nullable','string','max:100'],
            'Shippers_Destination_District'            => ['nullable','string','max:100'],
            'Shippers_Destination_Rate_Applicability'  => ['nullable','in:weight,volume,both,special'],
            'Shippers_Destination_Country_Preference'  => ['nullable','string','max:100'],
            'Shippers_Destination_Region_Preference'   => ['nullable','string','max:100'],
            'Shippers_Destination_District_Preference' => ['nullable','string','max:100'],
        ];
    }
}
