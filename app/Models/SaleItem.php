<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Sale;
use App\Models\Inventory;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleItem extends Model
{
            use SoftDeletes;


    protected $table = 'sale_items';

    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'serial_no',
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'serial_no', 'serial_no');
    }
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}