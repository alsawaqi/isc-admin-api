<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipperWeightRateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return  true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'Shippers_Standard_Shipping_Weight_Size' => ['nullable','string','max:50'], // code/bucket
            'Shippers_Standard_Shipping_Weight_Rate' => ['required','numeric','between:0,9999999999.999'],
            'Shippers_Currency'                      => ['nullable','string','size:3'],

            'Shippers_Min_Weight_Kg' => ['nullable','numeric','gte:0'],
            'Shippers_Max_Weight_Kg' => ['nullable','numeric','gt:Shippers_Min_Weight_Kg'],
            'Shippers_Base_Fee'      => ['nullable','numeric','gte:0'],
            'Shippers_Per_Kg_Fee'    => ['nullable','numeric','gte:0'],
            'Shippers_Flat_Fee'      => ['nullable','numeric','gte:0'],
        ];
    }
}
