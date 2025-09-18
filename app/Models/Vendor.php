<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
        use SoftDeletes;

    protected $table = 'vendors';
       protected $fillable = [
        'vendor', 'gst_no', 'email', 'pincode',
        'city', 'state', 'district', 'address',
        'mobile_no', 'alt_mobile_no', 'status'
    ];

    public function contactPersons()
    {
        return $this->hasMany(ContactPerson::class, 'vendor_id');
    }

}
