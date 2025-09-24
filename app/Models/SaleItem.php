<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Sale;
use App\Models\Inventory;

class SaleItem extends Model
{
    // use HasFactory;

    protected $table = 'sale_items';

    protected $fillable = [
        'sale_id',
        'quantity',
        'testing_id',
        'created_at',
        'updated_at',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function testing()
    {
        return $this->belongsTo(Inventory::class, 'testing_id');
    }
}