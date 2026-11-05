<?php

namespace App\Http\Controllers;

use App\Models\CareTask;
use App\Models\Quote;
use App\Models\WebAudit;
use App\Services\Sales\Followups;
use App\Services\Work\TaskTimer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Stoper przy zadaniach, skrzynka zgłoszeń i przypomnienia o kontakcie. */
class WorkController extends Controller
{
    public function timerStart(CareTask $task, TaskTimer $timer)
    {
        abort_unless($task->status === 'todo', 422, 'Stoper działa tylko przy zadaniach do zrobienia.');
        $timer->start($task);

        return back();
    }

    public function timerStop(CareTask $task, TaskTimer $timer)
    {
        $minutes = $timer->stop($task);

        return back()->with('success', "Doliczono {$minutes} min do zadania „{$task->title}”.");
    }

    /** Zgłoszenia od klientów i agencji (formularz z linku). */
    public function requests(Request $request)
    {
        $status = $request->input('status') === 'done' ? 'done' : 'todo';

        return Inertia::render('requests', [
            'status' => $status,
            'tasks' => CareTask::where('source', 'request')->whereHas('client', fn ($q) => $q->active())
                ->when($status === 'done', fn ($q) => $q->whereIn('status', ['done', 'skipped']), fn ($q) => $q->where('status', 'todo'))
                ->with(['client:id,name,agency_id', 'client.agency:id,name', 'doneBy:id,name'])
                ->orderBy($status === 'done' ? 'done_at' : 'due_date', $status === 'done' ? 'desc' : 'asc')->limit(200)
                ->get(CareTask::LIST_COLUMNS),
            'counts' => [
                'todo' => CareTask::where('source', 'request')->where('status', 'todo')->whereHas('client', fn ($q) => $q->active())->count(),
            ],
        ]);
    }

    public function followup(Request $request, string $type, int $id, Followups $followups)
    {
        $data = $request->validate(['action' => ['required', Rule::in(['contacted', 'off'])]]);
        $item = $type === 'quote' ? Quote::findOrFail($id) : ($type === 'audit' ? WebAudit::findOrFail($id) : abort(404));
        $followups->handle($item, $data['action']);

        return back()->with('success', $data['action'] === 'off' ? 'Przypomnienie wyłączone.' : 'Zapisano kontakt – przypomnę za '.Followups::NEXT_AFTER_DAYS.' dni.');
    }
}
