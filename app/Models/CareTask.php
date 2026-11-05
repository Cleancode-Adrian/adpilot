<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Zadanie opieki w konkretnym miesiącu (z planu albo jednorazowe). */
class CareTask extends Model
{
    protected $fillable = ['client_id', 'care_template_id', 'period', 'title', 'description', 'due_date', 'status', 'done_at', 'done_by', 'note', 'minutes', 'amount', 'invoiced_at',
        'timer_started_at', 'source', 'requester'];

    protected $casts = ['done_at' => 'datetime', 'invoiced_at' => 'datetime', 'amount' => 'float', 'timer_started_at' => 'datetime', 'requester' => 'array'];

    /** Kolumny zadań na listach (w tym stoper i dane zgłaszającego). */
    public const LIST_COLUMNS = ['id', 'client_id', 'care_template_id', 'source', 'period', 'title', 'description', 'requester', 'due_date', 'status',
        'done_at', 'done_by', 'note', 'minutes', 'timer_started_at', 'amount', 'invoiced_at'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CareTemplate::class, 'care_template_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function scopeTodo(Builder $q): Builder
    {
        return $q->where('status', 'todo');
    }

    /** Do zrobienia z terminem do podanej daty (domyślnie: dziś) – zaległe i na dziś. */
    public function scopeDue(Builder $q, ?string $until = null): Builder
    {
        return $q->todo()->where('due_date', '<=', $until ?? now()->toDateString());
    }
}
