<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipperResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'Shippers_Code'            => $this->Shippers_Code,
            'Shippers_Name'            => $this->Shippers_Name,
            'Shippers_Address'         => $this->Shippers_Address,
            'Shippers_Office_No'       => $this->Shippers_Office_No,
            'Shippers_GSM_No'          => $this->Shippers_GSM_No,
            'Shippers_Email_Address'   => $this->Shippers_Email_Address,
            'Shippers_Official_Website_Address' => $this->Shippers_Official_Website_Address,
            'Shippers_GPS_Location'    => $this->Shippers_GPS_Location,
            'Shippers_Scope'           => $this->Shippers_Scope,
            'Shippers_Type'            => $this->Shippers_Type,
            'Shippers_Rate_Mode'       => $this->Shippers_Rate_Mode,
            'Shippers_Is_Active'       => (bool)$this->Shippers_Is_Active,
            'Shippers_Meta'            => $this->Shippers_Meta,
            'contacts_count'           => $this->whenCounted('contacts'),
            'created_at'               => $this->created_at,
            'updated_at'               => $this->updated_at,
        ];
    }
}
