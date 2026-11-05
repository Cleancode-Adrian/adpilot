<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audyt dowolnej strony (np. potencjalnego klienta): SEO, pomiar, szybkość, bezpieczeństwo. */
class WebAudit extends Model
{
    protected $fillable = ['client_id', 'url', 'name', 'email', 'status', 'score', 'areas', 'error', 'token', 'created_by', 'finished_at', 'followup_at', 'followup_off'];

    protected $hidden = ['token'];

    protected $casts = ['areas' => 'array', 'finished_at' => 'datetime', 'followup_at' => 'datetime', 'followup_off' => 'boolean'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function host(): string
    {
        return preg_replace('/^www\./', '', (string) parse_url($this->url, PHP_URL_HOST));
    }
}
