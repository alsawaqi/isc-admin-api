<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
     protected $table = 'Support_Tickets_T';

    protected $fillable = [
        'Ticket_Reference',
        'User_Id',
        'Customer_Id',
        'Subject',
        'Ticket_Type',
        'Order_Id',
        'Ticket_Status',
    ];

    public function messages()
    {
        return $this->hasMany(SupportTicketMessage::class, 'Ticket_Id');
    }

   // ✅ one-liner: fetch the latest message (so you don’t get a collection)
   public function lastMessage()
{
    return $this->hasOne(SupportTicketMessage::class, 'Ticket_Id')->latestOfMany();
}

    // ✅ your new relation
    public function customer()
    {
        return $this->belongsTo(Customers::class, 'Customer_Id');
    }
}
