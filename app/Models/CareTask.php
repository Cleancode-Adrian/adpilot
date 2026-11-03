<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Zadanie opieki w konkretnym miesiącu (z planu albo jednorazowe). */
class CareTask extends Model
{
    protected $fillable = ['client_id', 'care_template_id', 'period', 'title', 'description', 'due_date', 'status', 'done_at', 'done_by', 'note', 'minutes'];

    protected $casts = ['done_at' => 'datetime'];

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
