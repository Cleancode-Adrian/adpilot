<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Faktura wystawiona z panelu w systemie fakturowym (Fakturownia). */
class Invoice extends Model
{
    protected $fillable = ['client_id', 'agency_id', 'provider', 'external_id', 'number', 'kind', 'buyer_name', 'total', 'currency',
        'issue_date', 'payment_to', 'view_url', 'status', 'items', 'sent_at', 'paid_at', 'created_by'];

    protected $casts = ['total' => 'float', 'items' => 'array', 'issue_date' => 'date', 'payment_to' => 'date', 'sent_at' => 'datetime', 'paid_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CareTask::class);
    }
}
