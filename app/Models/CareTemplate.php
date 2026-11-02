<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stałe zadanie z planu opieki – powtarza się co miesiąc / kwartał / pół roku / rok. */
class CareTemplate extends Model
{
    public const FREQUENCIES = [
        'monthly' => ['label' => 'co miesiąc', 'months' => 1],
        'quarterly' => ['label' => 'co kwartał', 'months' => 3],
        'semiannual' => ['label' => 'co pół roku', 'months' => 6],
        'yearly' => ['label' => 'raz w roku', 'months' => 12],
    ];

    protected $fillable = ['client_id', 'title', 'description', 'frequency', 'start_month', 'due_day', 'active', 'position'];

    protected $casts = ['active' => 'boolean', 'start_month' => 'integer', 'due_day' => 'integer', 'position' => 'integer'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CareTask::class);
    }

    /** Czy zadanie wypada w danym miesiącu (1–12). */
    public function isDueIn(int $month): bool
    {
        $every = self::FREQUENCIES[$this->frequency]['months'] ?? 1;

        return (($month - $this->start_month) % $every + $every) % $every === 0;
    }
}
