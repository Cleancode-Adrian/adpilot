<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/** Dziennik bezpieczeństwa: logowania, nieudane próby, 2FA, zmiany haseł, odblokowania sejfu. */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [
        'login' => 'Logowanie',
        'login_new_device' => 'Logowanie z nowego urządzenia',
        'login_failed' => 'Nieudane logowanie',
        'login_locked' => 'Blokada po wielu nieudanych próbach',
        'two_factor_failed' => 'Błędny kod weryfikacji dwuetapowej',
        'two_factor_enabled' => 'Włączono weryfikację dwuetapową',
        'two_factor_disabled' => 'Wyłączono weryfikację dwuetapową',
        'recovery_code_used' => 'Użyto kodu zapasowego',
        'recovery_codes_regenerated' => 'Wygenerowano nowe kody zapasowe',
        'password_changed' => 'Zmiana hasła',
        'password_reset' => 'Reset hasła',
        'email_changed' => 'Zmiana adresu e-mail',
        'sessions_revoked' => 'Wylogowano inne urządzenia',
        'vault_unlocked' => 'Odblokowano sejf haseł',
        'vault_unlock_failed' => 'Nieudane odblokowanie sejfu',
        'logout' => 'Wylogowanie',
        'device_removed' => 'Usunięto zaufane urządzenie',
    ];

    protected $fillable = ['user_id', 'type', 'email', 'ip', 'user_agent', 'details'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public static function record(string $type, ?User $user = null, ?Request $request = null, array $details = [], ?string $email = null): self
    {
        $request ??= request();

        return self::create([
            'user_id' => $user?->id,
            'type' => $type,
            'email' => $email,
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 300) : null,
            'details' => $details ?: null,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
