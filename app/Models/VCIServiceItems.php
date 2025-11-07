<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Vendor;



class VCIServiceItems extends Model
{

protected $table = 'service_vci_items';
protected $schema = 'public';

    protected $fillable = [
    'service_vci_id',
    'vci_serial_no',
    'tested_date',
    'issue_found',
    'action_taken',
    'remarks',
    'testing_assigned_to',
    'testing_status',
    'product_id',
    'upload_image',
    'created_by',
    'updated_by',
    'deleted_by',
    'warranty_status',  
    'urgent',         

];


  public function serviceVCI()
{
    return $this->belongsTo(VCIService::class, 'service_vci_id', 'id');
}

public function product()
{
    return $this->belongsTo(Product::class, 'product_id', 'id');
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

}