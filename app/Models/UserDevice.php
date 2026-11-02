<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Urządzenie (przeglądarka), z którego użytkownik już się logował – logowanie z nowego = e-mail z ostrzeżeniem. */
class UserDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'user_agent', 'ip', 'last_used_at'];

    protected $hidden = ['token_hash'];

    protected $casts = ['last_used_at' => 'datetime'];
}
