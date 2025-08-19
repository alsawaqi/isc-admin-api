<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShipperRequest extends FormRequest
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
       $id = $this->route('shipper'); // from route-model binding or {shipper}

        return [
            'Shippers_Code'  => ['sometimes','string','max:50', Rule::unique('Shippers_Master_T','Shippers_Code')->ignore($id)],
            'Shippers_Name'  => ['sometimes','string','max:255'],
            'Shippers_Scope' => ['sometimes','in:local,international'],
            'Shippers_Type'  => ['sometimes','in:parcel,courier,postal,heavy,slow,other'],
            'Shippers_Rate_Mode' => ['sometimes','in:weight,volume,both'],

            'Shippers_Email_Address' => ['nullable','email','max:255'],
            'Shippers_Official_Website_Address' => ['nullable','url','max:255'],
            'Shippers_Is_Active' => ['boolean'],
            'Shippers_Meta' => ['nullable','array'],

            'Shippers_Address' => ['nullable','string','max:500'],
            'Shippers_Office_No' => ['nullable','string','max:50'],
            'Shippers_GSM_No' => ['nullable','string','max:50'],
            'Shippers_GPS_Location' => ['nullable','string','max:255'],
        ];
    }
}
