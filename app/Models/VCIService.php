<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VCIService extends Model
{

    protected $table = 'service_vci';
    protected $fillable = [
    'challan_no',
    'challan_date',
    'courier_name',
    'quantity',
    'status',
    'sent_date',
    'received_date',
    'from_place',
    'to_place',
    'tracking_number',   
    'challan_1',
    'challan_2',        
    'receipt_upload',    
    'created_by',
    'updated_by',
    'deleted_by',
];


    public function items()
    {
        return $this->hasMany(VCIServiceItems::class, 'service_vci_id');
    }
}