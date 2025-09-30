<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Vendor;



class VCIServiceItems extends Model
{
    // use HasFactory;

    protected $table = 'service_vci_items';

    protected $fillable = [
        'vci_serial_no',
        'service_vci_id',
        'tested_date',
        'issue_found',
        'action_taken',
        'remarks',
        'testing_assigned_to',
        'testing_status',
        'product_id',
        'product_type_id',
        'vendor_id',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function serviceVCI()
    {
        return $this->belongsTo(VCIService::class, 'service_vci_id');
    }
     public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}