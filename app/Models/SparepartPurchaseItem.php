<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SparepartPurchaseItem extends Model
{
    
     use HasFactory;

    protected $table = 'sparepart_purchase_items';

    protected $fillable = [
        'purchase_id',
        'product_id',
        'sparepart_id',
        'quantity',
        'warranty_status',
        'serial_no',
          'created_by',
           'updated_by',
           'deleted_by'

    ];

    public function purchase()
    {
        return $this->belongsTo(SparepartPurchase::class, 'purchase_id');
    }

    public function sparepart()
    {
        return $this->belongsTo(Sparepart::class, 'sparepart_id');
    }

    public function category()
    {
        return $this->belongsTo(product::class, 'product_id');
    }
}
