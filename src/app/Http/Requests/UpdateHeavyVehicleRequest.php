<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHeavyVehicleRequest extends FormRequest
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
            'Shippers_Vehicle_Type' => ['sometimes','string','max:255'],
            'Shippers_Vehicle_Capacity_Ton' => ['sometimes','nullable','numeric','gte:0'],
        ];
    }
}
