<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assemble extends Model
{

    protected $table = 'assemble';

    protected $fillable = [
        'product_id',
        // 'sparepart_item_id',
        'serial_no',
        'udf1',
        'udf2',
        'udf3',
        'udf4',
        'udf5',
        'tested_status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $attributes = [
        'tested_status' => 'PENDING',
    ];

    
    public function product() {
        return $this->belongsTo(Product::class, 'product_id');
    }
   public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater() {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter() {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
