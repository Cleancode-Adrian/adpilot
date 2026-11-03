<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audyt strony: SEO i techniczny (crawler po stronie klienta). */
class SiteAudit extends Model
{
    protected $fillable = ['client_id', 'url', 'status', 'score', 'pages_crawled', 'summary', 'issues', 'pages', 'error', 'started_at', 'finished_at'];

    protected $casts = ['summary' => 'array', 'issues' => 'array', 'pages' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
