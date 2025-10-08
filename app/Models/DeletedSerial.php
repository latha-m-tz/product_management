<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeletedSerial extends Model
{
    protected $table = 'deleted_serials';
    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'product_type_id',
        'serial_no',
        'deleted_at',
    ];

    public function inventory()
    {
        return $this->belongsTo(Inventory::class, 'inventory_id');
    }
}
