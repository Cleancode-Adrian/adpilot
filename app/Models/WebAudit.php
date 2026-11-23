<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Audyt dowolnej strony (np. potencjalnego klienta): SEO, pomiar, szybkość, bezpieczeństwo. */
class WebAudit extends Model
{
    protected $fillable = ['client_id', 'audit_batch_id', 'url', 'name', 'email', 'status', 'score', 'areas', 'options', 'report', 'error', 'token', 'created_by', 'finished_at', 'followup_at', 'followup_off'];

    protected $hidden = ['token'];

    protected $casts = ['areas' => 'array', 'options' => 'array', 'report' => 'array', 'finished_at' => 'datetime', 'followup_at' => 'datetime', 'followup_off' => 'boolean'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Audyt zbiorczy, do którego należy ten audyt (null = pojedynczy audyt). */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(AuditBatch::class, 'audit_batch_id');
    }

    public function batchItem(): HasOne
    {
        return $this->hasOne(AuditBatchItem::class);
    }

    public function host(): string
    {
        return preg_replace('/^www\./', '', (string) parse_url($this->url, PHP_URL_HOST));
    }
}
