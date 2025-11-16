<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VCIService extends Model
{

protected $table = 'service_vci';

protected $fillable = [
    'vendor_id',
    'challan_no',
    'challan_date',
    'tracking_no',
    'receipt_files',      
    'created_by',
    'updated_by',
    'deleted_by',
];

protected $casts = [
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
public function vendor()
{
    return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
}
}