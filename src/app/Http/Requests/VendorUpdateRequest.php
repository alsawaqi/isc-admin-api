<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorUpdateRequest extends FormRequest
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
            'Vendor_Name' => ['required','string','max:255'],
            'Trade_Name'  => ['nullable','string','max:255'],
            'CR_Number'   => ['nullable','string','max:100'],
            'VAT_Number'  => ['nullable','string','max:100'],
            'Email_1'     => ['nullable','email','max:255'],
            'Phone_No'    => ['nullable','string','max:50'],
            'Business_Type' => ['nullable','string','max:80'],
            'Contact_Person_Name' => ['nullable','string','max:150'],
            'Contact_Person_Title' => ['nullable','string','max:100'],
            'Contact_Person_Email' => ['nullable','email','max:255'],
            'Contact_Person_Phone' => ['nullable','string','max:50'],

            'Address_Line1' => ['nullable','string','max:255'],
            'Address_Line2' => ['nullable','string','max:255'],
            'Postal_Code'   => ['nullable','string','max:30'],
            'PO_Box'        => ['nullable','string','max:30'],
            'Bank_Name' => ['nullable','string','max:150'],
            'Bank_Account_Name' => ['nullable','string','max:150'],
            'Bank_Account_Number' => ['nullable','string','max:80'],
            'Bank_IBAN' => ['nullable','string','max:80'],
            'Bank_Swift_Code' => ['nullable','string','max:40'],
            'Payout_Method' => ['nullable','in:bank_transfer,manual,cheque'],
            'Payout_Status' => ['nullable','in:not_configured,pending_review,approved,rejected'],

            // ✅ new geo fields
            'Country_Id'  => ['nullable','integer'],
            'Region_Id'   => ['nullable','integer'],
            'District_Id' => ['nullable','integer'],
            'City_Id'     => ['nullable','integer'],

            'Status'      => ['required','in:active,pending,suspended,blocked'],
            'Approval_Status' => ['nullable','in:pending,accepted,under_review,approved,rejected'],
            'Approval_Note' => ['nullable','string','max:2000'],
            'Is_Active'   => ['boolean'],
          ];
    }
}
