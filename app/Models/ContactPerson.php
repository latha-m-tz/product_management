<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactPerson extends Model
{
        use SoftDeletes;
    protected $table = 'vendor_contact_person';
    
    protected $fillable = [
        'vendor_id', 'name', 'designation', 'mobile_no',
        'alt_mobile_no', 'email', 'status','is_main', 
    ];

    
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }


}
