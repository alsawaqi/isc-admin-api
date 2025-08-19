<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipperContactResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                             => $this->id,
            'Shippers_Id'                    => $this->Shippers_Id,
            'Shippers_Contact_Name'          => $this->Shippers_Contact_Name,
            'Shippers_Contact_Position'      => $this->Shippers_Contact_Position,
            'Shippers_Contact_Office_No'     => $this->Shippers_Contact_Office_No,
            'Shippers_Contact_GSM_No'        => $this->Shippers_Contact_GSM_No,
            'Shippers_Contact_Email_Address' => $this->Shippers_Contact_Email_Address,
            'Shippers_Is_Primary'            => (bool)$this->Shippers_Is_Primary,
            'created_at'                     => $this->created_at,
            'updated_at'                     => $this->updated_at,
        ];
    }
}
