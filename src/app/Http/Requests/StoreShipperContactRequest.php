<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShipperContactRequest extends FormRequest
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
            'Shippers_Code'  => ['required','string','max:50','unique:Shippers_Master_T,Shippers_Code'],
            'Shippers_Name'  => ['required','string','max:255'],
            'Shippers_Scope' => ['required','in:local,international'],
            'Shippers_Type'  => ['required','in:parcel,courier,postal,heavy,slow,other'],
            'Shippers_Rate_Mode' => ['required','in:weight,volume,both'],

            'Shippers_Email_Address' => ['nullable','email','max:255'],
            'Shippers_Official_Website_Address' => ['nullable','url','max:255'],
            'Shippers_Is_Active' => ['boolean'],
            'Shippers_Meta' => ['nullable','array'],

            // Optional strings
            'Shippers_Address' => ['nullable','string','max:500'],
            'Shippers_Office_No' => ['nullable','string','max:50'],
            'Shippers_GSM_No' => ['nullable','string','max:50'],
            'Shippers_GPS_Location' => ['nullable','string','max:255'],
        ];
    }
}
