<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VCIServiceItems extends Model
{
    use SoftDeletes;

    protected $table = 'service_vci_items';

    protected $fillable = [
        'service_vci_id',
        'vci_serial_no',
        'issue_found',
        'sparepart_id',
        'quantity',
        'upload_image',
        'status',
        'remarks',
        'created_by',
        'updated_by',
        'deleted_by',
        'urgent',
    ];

    public function serviceVCI()
    {
        return $this->belongsTo(VCIService::class, 'service_vci_id');
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class, 'sparepart_id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id', 'id');
    }

    public function delivery()
    {
        return $this->hasOne(ServiceVCIDelivery::class, 'service_vci_items_id');
    }
   
public function product()
{
    return $this->belongsTo(Product::class, 'product_id');
}

}
