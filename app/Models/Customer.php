<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $table = 'customers';

    protected $fillable = [
        'customer',
        'gst_no',
        'email',
        'pincode',
        'city',
        'state',
        'district',
        'address',
        'mobile_no',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $dates = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    // Optional: default status
    protected $attributes = [
        'status' => 'active',
    ];
}
