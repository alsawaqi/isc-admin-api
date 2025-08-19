<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateShipperVolumeRateRequest extends FormRequest
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
            'Shippers_Standard_Shipping_Volume_Size' => ['sometimes','nullable','string','max:50'],
            'Shippers_Standard_Shipping_Volume_Rate' => ['sometimes','numeric','between:0,9999999999.999'],
            'Shippers_Currency'                      => ['sometimes','nullable','string','size:3'],

            'Shippers_Min_Volume_Cbm' => ['sometimes','nullable','numeric','gte:0'],
            'Shippers_Max_Volume_Cbm' => ['sometimes','nullable','numeric','gt:Shippers_Min_Volume_Cbm'],
            'Shippers_Base_Fee'       => ['sometimes','nullable','numeric','gte:0'],
            'Shippers_Per_Cbm_Fee'    => ['sometimes','nullable','numeric','gte:0'],
            'Shippers_Flat_Fee'       => ['sometimes','nullable','numeric','gte:0'],
        ];
    }
}
