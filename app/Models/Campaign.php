<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = ['client_id', 'platform', 'external_id', 'name', 'status', 'channel_type', 'daily_budget', 'issues', 'destination'];

    /** Reklamy, po których kliknięciu nikt nie przechodzi na stronę (formularz Meta, Messenger, WhatsApp, telefon…). */
    public const OFF_SITE_DESTINATIONS = ['ON_AD', 'MESSENGER', 'WHATSAPP', 'INSTAGRAM_DIRECT', 'ON_POST', 'ON_VIDEO', 'ON_PAGE', 'ON_EVENT', 'PHONE_CALL', 'FACEBOOK', 'INSTAGRAM_PROFILE', 'MESSAGING_MESSENGER_WHATSAPP', 'MESSAGING_INSTAGRAM_DIRECT_MESSENGER', 'MESSAGING_INSTAGRAM_DIRECT_WHATSAPP', 'MESSAGING_INSTAGRAM_DIRECT_MESSENGER_WHATSAPP'];

    protected function casts(): array
    {
        return ['daily_budget' => 'float', 'issues' => 'array'];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function metrics(): HasMany { return $this->hasMany(CampaignMetric::class); }
    public function ads(): HasMany { return $this->hasMany(Ad::class); }
}
