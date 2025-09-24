<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VCIService extends Model
{
    //  use HasFactory;

    protected $table = 'service_vci';

    protected $fillable = [
        'challan_no',
        'challan_date',
        'courier_name',
        'hsn_code',
        'quantity',
        'status',
        'sent_date',
        'received_date',
        'from_place',
        'to_place',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function items()
    {
        return $this->hasMany(VCIServiceItems::class, 'service_vci_id');
    }
}