<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recommendation extends Model
{
    protected $fillable = [
        'client_id', 'platform', 'rule_key', 'fingerprint', 'severity', 'kind', 'entity_type', 'entity_id',
        'entity_name', 'title', 'description', 'action', 'impact', 'data', 'status', 'detected_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'detected_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }

    public function scopeOpen($query) { return $query->where('status', 'open'); }

    public function scopeBySeverity($query)
    {
        return $query->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('detected_at');
    }
}
