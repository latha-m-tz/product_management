<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;


class Sparepart extends Model
{
        use HasFactory;
    use SoftDeletes;

    protected $table = 'spareparts';

    protected $fillable = [
        'name',
        'code',
        'sparepart_type',
          'sparepart_usages',
          'required_per_vci',
          'created_by',
          'updated_by',
          'deleted_by'

    ];
}
