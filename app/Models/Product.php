<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'product';

    protected $fillable = [
        'name', 'created_by', 'updated_by', 'deleted_by'
    ];

    // One product has many product types
    public function productTypes()
    {
        return $this->hasMany(ProductType::class, 'product_id');
    }
}
