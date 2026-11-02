<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Client extends Model
{
    protected $fillable = [
        'name', 'website_url', 'has_ads', 'has_care', 'has_general', 'admin_url', 'contact_name', 'contact_phone', 'industry', 'goal', 'lead_events', 'currency', 'target_roas', 'target_cpa',
        'monthly_budget', 'daily_budget', 'contact_email', 'auto_send_reports', 'notes', 'is_demo', 'archived_at',
    ];

    protected $hidden = ['report_token'];

    protected function casts(): array
    {
        return [
            'has_ads' => 'boolean',
            'has_care' => 'boolean',
            'has_general' => 'boolean',
            'target_roas' => 'float',
            'target_cpa' => 'float',
            'monthly_budget' => 'float',
            'daily_budget' => 'float',
            'auto_send_reports' => 'boolean',
            'is_demo' => 'boolean',
            'archived_at' => 'datetime',
            'lead_events' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Client $client) {
            $client->report_token ??= Str::random(48);
        });
    }

    /** Domyślne zdarzenia GA4 traktowane jako lead (rekomendowane nazwy Google + popularne własne). */
    public const DEFAULT_LEAD_EVENTS = ['generate_lead', 'form_submit', 'sign_up', 'contact', 'phone_click', 'click_to_call', 'submit_lead_form'];

    public const GOALS = ['sales' => 'Sprzedaż (sklep)', 'leads' => 'Pozyskiwanie kontaktów', 'mixed' => 'Sprzedaż i kontakty'];

    /**
     * Porządkuje adres strony: usuwa zdublowane/uszkodzone przedrostki („https//”, „https://https://”, „www” zostaje),
     * dodaje https:// i zwraca null, gdy to nie jest poprawny adres.
     */
    public static function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        $scheme = preg_match('#^http://(?!https?[:/])#i', $url) ? 'http' : 'https';
        // zdejmij dowolną liczbę (także uszkodzonych) przedrostków http(s)
        // tylko pełne „http”/„https” zakończone dwukropkiem lub ukośnikiem – nie ruszamy domen typu „httpbin.org”
        $rest = preg_replace('#^(?:\s*https?\s*(?::\s*/*|/+))+#i', '', $url);
        $rest = ltrim((string) $rest, '/');
        if ($rest === '') {
            return null;
        }
        $candidate = $scheme.'://'.$rest;
        $host = parse_url($candidate, PHP_URL_HOST);

        if (! filter_var($candidate, FILTER_VALIDATE_URL) || ! $host || ! str_contains($host, '.')) {
            return null;
        }

        return rtrim($candidate, '/');
    }

    public function leadEventNames(): array
    {
        return $this->lead_events ?: self::DEFAULT_LEAD_EVENTS;
    }

    public function tracksLeads(): bool
    {
        return in_array($this->goal, ['leads', 'mixed'], true);
    }

    public function tracksSales(): bool
    {
        return in_array($this->goal ?? 'sales', ['sales', 'mixed'], true);
    }

    /** Klienci aktywni (niezarchiwizowani). */
    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /** Klienci z usługą reklam (Google / Meta). */
    public function scopeRunsAds($query)
    {
        return $query->where('has_ads', true);
    }

    public function integrations(): HasMany { return $this->hasMany(Integration::class); }
    public function campaigns(): HasMany { return $this->hasMany(Campaign::class); }
    public function ads(): HasMany { return $this->hasMany(Ad::class); }
    public function searchTerms(): HasMany { return $this->hasMany(SearchTerm::class); }
    public function analytics(): HasMany { return $this->hasMany(AnalyticsDaily::class); }
    public function audits(): HasMany { return $this->hasMany(TrackingAudit::class); }
    public function recommendations(): HasMany { return $this->hasMany(Recommendation::class); }
    public function reports(): HasMany { return $this->hasMany(Report::class); }
    public function careTemplates(): HasMany { return $this->hasMany(CareTemplate::class)->orderBy('position')->orderBy('id'); }
    public function careTasks(): HasMany { return $this->hasMany(CareTask::class); }
    public function credentials(): HasMany { return $this->hasMany(ClientCredential::class); }

    public function latestAudit(): HasOne
    {
        return $this->hasOne(TrackingAudit::class)->where('status', 'done')->latestOfMany();
    }

    public function integration(string $provider): ?Integration
    {
        return $this->integrations->firstWhere('provider', $provider);
    }
}
