<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDaily extends Model
{
    protected $table = 'analytics_daily';

    public $timestamps = false;

    protected $fillable = ['client_id', 'date', 'channel', 'sessions', 'users', 'new_users', 'engaged_sessions', 'transactions', 'revenue', 'key_events'];
}
