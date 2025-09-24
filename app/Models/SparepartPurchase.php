<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SparepartPurchase extends Model
{
     use HasFactory;

    protected $table = 'sparepart_purchase';

    protected $fillable = [
        'vendor_id',
        'challan_no',
        'challan_date',
           'created_by',
           'updated_by',
           'deleted_by'
      
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
