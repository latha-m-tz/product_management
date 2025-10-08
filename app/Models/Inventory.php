<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inventory extends Model
{
    use SoftDeletes;

    protected $table = 'inventory';

    protected $fillable = [
        'product_id',
        'product_type_id',
        'firmware_version',
        'tested_date',
        'serial_no',
        'tested_by',
        'tested_status',
        'test_remarks',
        'from_serial',
        'to_serial',
        'quantity',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'tested_date' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

public function productType()
{
    return $this->belongsTo(ProductType::class, 'product_type_id');
}

    public function tester()
    {
        return $this->belongsTo(User::class, 'tested_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

   public function saleItems()
    {
        return $this->hasMany(SaleItem::class, 'serial_no', 'serial_no');
    }
    public function serviceItems()
    {
        return $this->hasMany(VCIServiceItem::class, 'vci_serial_no', 'serial_no');
    }
    
    public function sales()
{
    return $this->hasManyThrough(Sale::class, SaleItem::class, 'testing_id', 'id', 'id', 'sale_id');
}

}