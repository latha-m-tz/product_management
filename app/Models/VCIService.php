<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VCIService extends Model
{
        use SoftDeletes;

protected $table = 'service_vci';

protected $fillable = [
    'vendor_id',
    'challan_no',
    'challan_date',
    'tracking_no',
    'receipt_files',  
    'cerated_at',
    'updated_at',
    'deleted_at',    
    'created_by',
    'updated_by',
    'deleted_by',
];

    protected $casts = [
        'receipt_files' => 'array',
    ];

    protected $appends = ['receipt_files_urls'];

    public function getReceiptFilesUrlsAttribute()
    {
        $urls = [];
        foreach ($this->receipt_files ?? [] as $file) {
            $urls[] = asset('storage/' . $file);
        }
        return $urls;
    }

public function serviceVCI()
{
    return $this->belongsTo(VCIService::class, 'service_vci_id', 'id');
}
public function items()
{
    return $this->hasMany(VCIServiceItems::class, 'service_vci_id', 'id');
}
public function vendor()
{
    return $this->belongsTo(Vendor::class, 'vendor_id', 'id');
}
public function sparepart()
{
    return $this->belongsTo(Sparepart::class, 'sparepart_id');
}

public function product()
{
    return $this->belongsTo(Product::class, 'product_id');
}

}