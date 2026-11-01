<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ad extends Model
{
    protected $fillable = ['client_id', 'campaign_id', 'platform', 'external_id', 'name', 'status'];

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
    public function metrics(): HasMany { return $this->hasMany(AdMetric::class); }
}
