<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchTerm extends Model
{
    protected $fillable = ['client_id', 'campaign_id', 'term', 'period_start', 'period_end', 'impressions', 'clicks', 'cost', 'conversions', 'conversion_value'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'cost' => 'float', 'conversions' => 'float'];
    }

    public function campaign(): BelongsTo { return $this->belongsTo(Campaign::class); }
}
