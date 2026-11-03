<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignMetric extends Model
{
    public $timestamps = false;

    protected $fillable = ['campaign_id', 'date', 'impressions', 'clicks', 'link_clicks', 'landing_page_views', 'cost', 'conversions', 'conversion_value', 'reach', 'frequency', 'budget_lost_is'];
}
