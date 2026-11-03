<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Monitoring dostępności i certyfikatu SSL strony klienta. */
class SiteMonitor extends Model
{
    protected $fillable = ['client_id', 'url', 'enabled', 'status', 'last_checked_at', 'last_status_code', 'last_response_ms', 'last_error',
        'down_since', 'ssl_expires_at', 'ssl_issuer', 'ssl_error', 'ssl_checked_at', 'ssl_alerted_at'];

    protected $casts = [
        'enabled' => 'boolean',
        'last_checked_at' => 'datetime',
        'down_since' => 'datetime',
        'ssl_expires_at' => 'datetime',
        'ssl_checked_at' => 'datetime',
        'ssl_alerted_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sslDaysLeft(): ?int
    {
        return $this->ssl_expires_at ? (int) floor(now()->diffInDays($this->ssl_expires_at, false)) : null;
    }
}
