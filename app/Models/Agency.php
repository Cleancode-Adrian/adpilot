<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/** Agencja – zleceniodawca, dla którego obsługujesz strony jego klientów. */
class Agency extends Model
{
    protected $fillable = [
        'name', 'website_url', 'tax_id', 'contact_name', 'contact_email', 'contact_phone', 'white_label',
        'logo', 'brand_color', 'report_footer', 'hourly_rate', 'currency', 'terms', 'notes', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'white_label' => 'boolean',
            'hourly_rate' => 'float',
            'archived_at' => 'datetime',
        ];
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }

    /**
     * Marka agencji w formacie Setting::brand() – do raportów i e-maili white label.
     * Celowo bez danych Twojej firmy: klient końcowy ma widzieć tylko agencję.
     */
    public function brand(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->contact_email,
            'phone' => $this->contact_phone,
            'website' => $this->website_url ? preg_replace('#^https?://#', '', $this->website_url) : null,
            'logo_url' => $this->logoUrl(),
            'logo_path' => $this->logo ? Storage::disk('public')->path($this->logo) : null,
            'color' => $this->brand_color ?: '#2a78d6',
            'report_footer' => $this->report_footer,
            'white_label' => true,
        ];
    }
}
