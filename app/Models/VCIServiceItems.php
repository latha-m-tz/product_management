<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VCIServiceItems extends Model
{
    // use HasFactory;

    protected $table = 'service_vci_items';

    protected $fillable = [
        'vci_serial_no',
        'service_vci_id',
        'tested_date',
        'issue_found',
        'action_taken',
        'remarks',
        'testing_assigned_to',
        'testing_status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function serviceShipment()
    {
        return $this->belongsTo(ServiceShipment::class, 'service_vci_id');
    }
}