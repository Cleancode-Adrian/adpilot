<?php

namespace App\Services\Work;

use App\Models\CareTask;

/**
 * Stoper przy zadaniu: start zapisuje moment włączenia, stop dolicza czas do minut zadania.
 * Naraz działa jeden stoper – włączenie nowego zatrzymuje poprzedni (jedna osoba = jedno zadanie naraz).
 */
class TaskTimer
{
    public function running(): ?CareTask
    {
        return CareTask::whereNotNull('timer_started_at')->with('client:id,name')->first();
    }

    public function start(CareTask $task): void
    {
        CareTask::whereNotNull('timer_started_at')->where('id', '!=', $task->id)->get()->each(fn (CareTask $t) => $this->stop($t));
        if (! $task->timer_started_at) {
            $task->update(['timer_started_at' => now()]);
        }
    }

    /** Zatrzymuje stoper i dolicza czas (zaokrąglony w górę do pełnej minuty). Zwraca doliczone minuty. */
    public function stop(CareTask $task): int
    {
        if (! $task->timer_started_at) {
            return 0;
        }
        $minutes = max(1, (int) ceil($task->timer_started_at->diffInSeconds(now(), true) / 60));
        $task->update(['minutes' => (int) $task->minutes + $minutes, 'timer_started_at' => null]);

        return $minutes;
    }
}
