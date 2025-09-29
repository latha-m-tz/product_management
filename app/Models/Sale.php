<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    // use HasFactory;

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