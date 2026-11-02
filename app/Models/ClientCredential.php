<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dostęp do usługi klienta (panel WordPress, hosting, FTP, baza, poczta…).
 * Hasło i notatki są szyfrowane kluczem APP_KEY – bez niego nie da się ich odczytać (także z kopii bazy).
 */
class ClientCredential extends Model
{
    public const CATEGORIES = [
        'cms' => 'Logowanie do strony (panel)',
        'hosting' => 'Hosting / panel serwera',
        'ftp' => 'FTP / SFTP / SSH',
        'database' => 'Baza danych',
        'domain' => 'Domena / DNS',
        'email' => 'Poczta',
        'google' => 'Google (Analytics, Search Console…)',
        'social' => 'Social media / Meta',
        'other' => 'Pozostałe',
    ];

    protected $fillable = ['client_id', 'category', 'label', 'url', 'username', 'secret', 'notes', 'secret_changed_at'];

    protected $hidden = ['secret', 'notes'];

    protected $casts = ['secret' => 'encrypted', 'notes' => 'encrypted', 'secret_changed_at' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function log(string $action, ?User $user = null, ?string $ip = null): void
    {
        CredentialLog::create([
            'client_credential_id' => $action === 'delete' ? null : $this->id,
            'client_id' => $this->client_id,
            'user_id' => $user?->id,
            'action' => $action,
            'label' => $this->label,
            'ip' => $ip,
        ]);
    }
}
