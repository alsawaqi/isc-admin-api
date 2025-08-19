<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHeavyRateRequest extends FormRequest
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
            'Shippers_Vehicle_Id' => ['sometimes','integer','exists:Heavy_Vehicles_T,id'],
            'Shippers_Flat_Rate'  => ['sometimes','nullable','numeric','gte:0'],
            'Shippers_Hourly_Rate'=> ['sometimes','nullable','numeric','gte:0'],
            'Shippers_Min_Hours'  => ['sometimes','nullable','integer','gte:0'],
            'Shippers_Currency'   => ['sometimes','nullable','string','size:3'],
        ];
    }
}
