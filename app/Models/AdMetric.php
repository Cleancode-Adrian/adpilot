<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdMetric extends Model
{
    public $timestamps = false;

    protected $fillable = ['ad_id', 'date', 'impressions', 'clicks', 'link_clicks', 'landing_page_views', 'cost', 'conversions', 'conversion_value', 'frequency'];
}
