<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Testing extends Model
{
    use HasFactory;

    protected $table = 'testing';

    protected $fillable = [
        'product_id',
        'assemble_id',
        'tested_by',
        'tested_date',
        'serial_no',
        'status',
        'remarks',
        'created_by',
        'updated_by',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function assemble()
    {
        return $this->belongsTo(Assemble::class);
    }

    public function tester()
    {
        return $this->belongsTo(User::class, 'tested_by');
    }
}
