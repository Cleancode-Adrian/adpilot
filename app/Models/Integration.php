<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Integration extends Model
{
    protected $fillable = [
        'client_id', 'provider', 'status', 'access_token', 'refresh_token',
        'token_expires_at', 'settings', 'available_accounts', 'last_synced_at', 'last_error', 'alerted_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'alerted_at' => 'datetime',
            'settings' => 'array',
            'available_accounts' => 'array',
        ];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
