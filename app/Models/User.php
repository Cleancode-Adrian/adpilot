<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Security\Totp;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'daily_digest',
        'last_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_step',
    ];

    protected $appends = ['two_factor_enabled'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'daily_digest' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function getTwoFactorEnabledAttribute(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    /** Kod z aplikacji (TOTP) – każdy kod działa tylko raz. */
    public function verifyTwoFactorCode(string $code): bool
    {
        if (! $this->two_factor_enabled) {
            return false;
        }
        $step = Totp::verify($this->two_factor_secret, $code, $this->two_factor_last_step);
        if ($step === null) {
            return false;
        }
        $this->forceFill(['two_factor_last_step' => $step])->saveQuietly();

        return true;
    }

    /** Kod zapasowy – jednorazowy, po użyciu znika. */
    public function useRecoveryCode(string $code): bool
    {
        $code = strtoupper(preg_replace('/[^a-z0-9]/i', '', $code));
        $codes = (array) $this->two_factor_recovery_codes;
        foreach ($codes as $i => $hash) {
            if (password_verify($code, $hash)) {
                unset($codes[$i]);
                $this->forceFill(['two_factor_recovery_codes' => array_values($codes)])->saveQuietly();

                return true;
            }
        }

        return false;
    }

    /** Nowe kody zapasowe: zwraca jawne (pokazujemy raz), w bazie zostają skróty. */
    public function regenerateRecoveryCodes(): array
    {
        $plain = collect(range(1, 10))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $this->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $plain)])->save();

        return $plain;
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(SecurityEvent::class);
    }
}
