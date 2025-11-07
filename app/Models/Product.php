<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    // ✅ Table name
    protected $table = 'product';

    // ✅ Fillable columns
    protected $fillable = [
        'name',
        'requirement_per_product',
        'product_type_name',
        'sparepart_ids',
        'sparepart_requirements', // ✅ newly added JSON column
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    // ✅ Automatically cast JSON column to array/object in Laravel
    protected $casts = [
        'sparepart_requirements' => 'array',
    ];

    // ✅ Relationships
    public function productTypes()
    {
        return $this->hasMany(ProductType::class, 'product_id');
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function spareparts()
    {
        return $this->belongsToMany(Sparepart::class, 'product_sparepart', 'product_id', 'sparepart_id')
                    ->withPivot('required_quantity') // optional per-part requirement
                    ->withTimestamps();
    }
}
