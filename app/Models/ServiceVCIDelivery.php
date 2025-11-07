<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceVCIDelivery extends Model
{
    protected $table = 'service_vci_deliveries';

    protected $fillable = [
        'service_vci_id',
        'service_vci_item_id',
        'delivery_challan_no',
        'delivery_date',
        'courier_name',
        'tracking_number',
        'delivered_to',
        'challan_files',
        'receipt_files',
        'status',
    ];

    // Cast file arrays properly
    protected $casts = [
        'challan_files' => 'array',
        'receipt_files' => 'array',
    ];

    // Relationships
    public function serviceVCI()
    {
        return $this->belongsTo(VCIService::class, 'service_vci_id');
    }

    public function serviceItem()
    {
        return $this->belongsTo(VCIServiceItems::class, 'service_vci_item_id');
    }
    public function serviceVciItem()
{
    return $this->belongsTo(VCIServiceItems::class, 'service_vci_item_id');
}

}
