<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Zdarzenie ze strony WordPress (nowy administrator, zmiana plików, instalacja wtyczki…). */
class WpEvent extends Model
{
    protected $fillable = ['client_id', 'wp_site_id', 'type', 'severity', 'title', 'details', 'occurred_at', 'acknowledged_at', 'acknowledged_by'];

    protected $casts = ['details' => 'array', 'occurred_at' => 'datetime', 'acknowledged_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(WpSite::class, 'wp_site_id');
    }

    /** Alerty do reakcji: krytyczne i ostrzeżenia, jeszcze nieoznaczone jako sprawdzone. */
    public function scopeAlerts(Builder $q): Builder
    {
        return $q->whereIn('severity', ['critical', 'warning'])->whereNull('acknowledged_at');
    }
}
