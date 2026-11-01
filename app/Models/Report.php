<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    protected $fillable = ['client_id', 'type', 'period_start', 'period_end', 'data', 'ai_summary', 'note', 'ai_model', 'sent_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'period_start' => 'date', 'period_end' => 'date', 'sent_at' => 'datetime'];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}
