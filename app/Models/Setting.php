<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ustawienia agencji edytowane w panelu (nadpisują wartości z .env).
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public const DEFAULTS = [
        'agency_name' => null,          // fallback: AGENCY_NAME z .env
        'agency_email' => null,
        'agency_phone' => null,
        'agency_website' => null,
        'agency_logo' => null,          // ścieżka w storage/app/public
        'brand_color' => '#2a78d6',
        'report_footer' => null,
    ];

    public static function all_(): array
    {
        return Cache::rememberForever('adpilot.settings', function () {
            try {
                $db = static::query()->where('key', 'not like', 'heartbeat.%')->pluck('value', 'key')->all();
            } catch (Throwable) {
                $db = []; // przed migracją
            }

            return array_merge(self::DEFAULTS, array_filter($db, fn ($v) => $v !== null && $v !== ''));
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_()[$key] ?? $default;
    }

    public static function put(array $values): void
    {
        foreach ($values as $k => $v) {
            static::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Cache::forget('adpilot.settings');
    }

    /**
     * Marka widoczna dla danego klienta: przy stronach agencji white label – marka agencji,
     * w pozostałych przypadkach – Twoja (z ustawień).
     */
    public static function brandFor(?Client $client): array
    {
        $agency = $client?->agency;

        return $agency && $agency->white_label ? $agency->brand() : static::brand() + ['white_label' => false];
    }

    /** Dane marki używane w panelu, raportach i mailach. */
    public static function brand(): array
    {
        $s = static::all_();
        $logo = $s['agency_logo'] ? Storage::disk('public')->url($s['agency_logo']) : null;

        return [
            'name' => $s['agency_name'] ?: config('adpilot.agency.name'),
            'email' => $s['agency_email'] ?: config('adpilot.agency.email'),
            'phone' => $s['agency_phone'],
            'website' => $s['agency_website'],
            'logo_url' => $logo,
            'logo_path' => $s['agency_logo'] ? Storage::disk('public')->path($s['agency_logo']) : null,
            'color' => $s['brand_color'] ?: '#2a78d6',
            'report_footer' => $s['report_footer'],
        ];
    }
}
