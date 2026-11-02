<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dziennik: kto i kiedy odczytał / zmienił dostęp. */
class CredentialLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['client_credential_id', 'client_id', 'user_id', 'action', 'label', 'ip'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
