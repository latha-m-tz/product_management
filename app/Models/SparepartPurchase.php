<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class SparepartPurchase extends Model
{
     use HasFactory;
    use SoftDeletes;

    protected $table = 'sparepart_purchase';


    protected $fillable = [
        'vendor_id',
        'challan_no',
        'tracking_number',
        'courier_name',
        'challan_date',
        'received_date',
        'document_recipient',
        'document_challan',
        'created_by',
        'updated_by',
        'deleted_by'
    ];

    protected $casts = [
        'document_recipient' => 'array',
        'document_challan' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(SparepartPurchaseItem::class, 'purchase_id');
    }

  public function vendor()
{
    return $this->belongsTo(Vendor::class, 'vendor_id');
}


}
