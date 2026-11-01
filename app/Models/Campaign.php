<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = ['client_id', 'platform', 'external_id', 'name', 'status', 'channel_type', 'daily_budget'];

    protected function casts(): array
    {
        return ['daily_budget' => 'float'];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function metrics(): HasMany { return $this->hasMany(CampaignMetric::class); }
    public function ads(): HasMany { return $this->hasMany(Ad::class); }
}
