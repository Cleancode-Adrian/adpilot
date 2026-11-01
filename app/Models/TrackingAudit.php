<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingAudit extends Model
{
    protected $fillable = ['client_id', 'url', 'status', 'score', 'results', 'error'];

    protected function casts(): array
    {
        return ['results' => 'array'];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
}
