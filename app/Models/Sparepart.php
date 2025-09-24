<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class Sparepart extends Model
{
        use HasFactory;

    protected $table = 'spareparts';

    protected $fillable = [
        'name',
        'sparepart_type',
      
    ];
}
