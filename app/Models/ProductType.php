<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductType extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'product_type';

    protected $fillable = [
        'name',
        'product_id',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    // Each product type belongs to a product
    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
