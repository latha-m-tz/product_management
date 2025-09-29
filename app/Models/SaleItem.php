<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Sale;
use App\Models\Inventory;

class SaleItem extends Model
{
    

    protected $table = 'sale_items';

    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'serial_no',
        'created_at',
        'updated_at',
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