<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    // use HasFactory;
        use SoftDeletes;

    protected $table = 'sales';
    
    protected $fillable = [
        'customer_id',
        'product_id',
        'challan_no',
        'challan_date',
        'shipment_date',
        'shipment_name', 
        'notes', 
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id', 'id');
    }
    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}