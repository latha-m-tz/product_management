<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;
    protected $table = 'Register';

    protected $fillable = [
        'name',   // make sure 'name' is here
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
}
