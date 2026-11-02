<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Strona WordPress klienta połączona przez wtyczkę CC Connector. */
class WpSite extends Model
{
    protected $fillable = ['client_id', 'site_key', 'secret', 'status', 'rest_url', 'report', 'scan', 'last_seen_at', 'last_report_at', 'last_pull_at', 'last_error', 'offline_alerted_at'];

    protected $hidden = ['secret'];

    protected $casts = [
        'secret' => 'encrypted',
        'report' => 'array',
        'scan' => 'array',
        'last_seen_at' => 'datetime',
        'last_report_at' => 'datetime',
        'last_pull_at' => 'datetime',
        'offline_alerted_at' => 'datetime',
    ];

    public static function generateFor(Client $client): self
    {
        return self::create([
            'client_id' => $client->id,
            'site_key' => Str::lower(Str::random(32)),
            'secret' => Str::random(64),
        ]);
    }

    /** Klucz do wklejenia we wtyczce: "cc1." + base64url(JSON z adresem panelu, id strony i sekretem). */
    public function connectionKey(): string
    {
        $json = json_encode(['u' => rtrim(config('app.url'), '/'), 's' => $this->site_key, 'k' => $this->secret], JSON_UNESCAPED_SLASHES);

        return 'cc1.'.rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WpEvent::class);
    }
}
