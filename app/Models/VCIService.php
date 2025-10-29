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
    'challan_files',
    'receipt_files',      
    'created_by',
    'updated_by',
    'deleted_by',
];

protected $casts = [
    'challan_files' => 'array',
    'receipt_files' => 'array',
];

public function serviceVCI()
{
    return $this->belongsTo(VCIService::class, 'service_vci_id', 'id');
}
public function items()
{
    return $this->hasMany(VCIServiceItems::class, 'service_vci_id', 'id');
}

}