<?php

namespace App\Http\Controllers;

use App\Models\CareTask;
use App\Models\CareTemplate;
use App\Models\Client;
use App\Services\Care\CarePlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Opieka nad stroną: plan zadań cyklicznych + zadania na dany miesiąc. */
class CareController extends Controller
{
    public function __construct(private CarePlanner $planner) {}

    /** Wszyscy klienci – co jest do zrobienia w tym miesiącu (przypominajka). */
    public function overview(Request $request)
    {
        $month = $this->month($request);
        $period = CarePlanner::period($month);
        if ($month->gte(CarbonImmutable::now()->startOfMonth())) {
            $this->planner->ensureAll($month);
        }

        $clients = Client::active()
            ->where(fn ($q) => $q->whereHas('careTasks', fn ($t) => $t->where('period', $period))
                ->orWhereHas('careTasks', fn ($t) => $t->due()))
            ->orderBy('name')->get(['id', 'name', 'website_url']);

        $rows = $clients->map(function (Client $c) use ($period) {
            $tasks = $c->careTasks()
                ->where(fn ($q) => $q->where('period', $period)->orWhere(fn ($o) => $o->due()->where('period', '<', $period)))
                ->orderByRaw("case status when 'todo' then 0 else 1 end")->orderBy('due_date')->orderBy('id')
                ->get(['id', 'client_id', 'period', 'title', 'description', 'due_date', 'status', 'done_at', 'note']);

            return [
                'client' => $c,
                'tasks' => $tasks,
                'summary' => $this->planner->summary($c, $period),
            ];
        })->sortBy([
            fn ($a, $b) => $b['summary']['overdue'] <=> $a['summary']['overdue'],
            fn ($a, $b) => ($b['summary']['total'] - $b['summary']['done']) <=> ($a['summary']['total'] - $a['summary']['done']),
        ])->values();

        return Inertia::render('care', [
            'period' => $period,
            'rows' => $rows,
            'clientsWithoutPlan' => Client::active()->where('is_demo', false)->whereDoesntHave('careTemplates')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function index(Request $request, Client $client)
    {
        $month = $this->month($request);
        $period = CarePlanner::period($month);
        if ($month->gte(CarbonImmutable::now()->startOfMonth())) {
            $this->planner->ensureMonth($client, $month);
        }

        $columns = ['id', 'care_template_id', 'period', 'title', 'description', 'due_date', 'status', 'done_at', 'done_by', 'note'];

        return Inertia::render('clients/care', [
            'client' => app(ClientController::class)->clientProps($client),
            'period' => $period,
            'tasks' => $client->careTasks()->where('period', $period)->with('doneBy:id,name')
                ->orderBy('due_date')->orderBy('id')->get($columns),
            'overdue' => $client->careTasks()->due()->where('period', '<', $period)->orderBy('due_date')->get($columns),
            'templates' => $client->careTemplates()->get(),
            'history' => $client->careTasks()
                ->selectRaw("period, count(*) as total, sum(case when status in ('done','skipped') then 1 else 0 end) as done")
                ->where('period', '<=', CarePlanner::period())
                ->groupBy('period')->orderByDesc('period')->limit(12)->get(),
            'frequencies' => collect(CareTemplate::FREQUENCIES)->map(fn ($f) => $f['label']),
            'presets' => collect(CarePlanner::PRESETS)->map(fn ($p) => ['label' => $p['label'], 'count' => count($p['items'])]),
        ]);
    }

    // --- Zadania w miesiącu

    public function storeTask(Request $request, Client $client)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'due_date' => 'required|date',
        ]);
        $client->careTasks()->create($data + ['period' => substr($data['due_date'], 0, 7)]);

        return back()->with('success', 'Dodano zadanie.');
    }

    public function updateTask(Request $request, CareTask $task)
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['todo', 'done', 'skipped'])],
            'note' => 'sometimes|nullable|string|max:5000',
            'title' => 'sometimes|string|max:255',
            'due_date' => 'sometimes|date',
        ]);
        if (isset($data['status'])) {
            $data += $data['status'] === 'todo'
                ? ['done_at' => null, 'done_by' => null]
                : ['done_at' => now(), 'done_by' => $request->user()->id];
        }
        $task->update($data);

        return back();
    }

    public function destroyTask(Request $request, CareTask $task)
    {
        // Zadanie z planu wróciłoby przy następnym planowaniu – oznaczamy je jako pominięte
        if ($task->care_template_id) {
            $task->update(['status' => 'skipped', 'done_at' => now(), 'done_by' => $request->user()->id]);

            return back()->with('success', 'Zadanie z planu pominięte w tym miesiącu.');
        }
        $task->delete();

        return back()->with('success', 'Usunięto zadanie.');
    }

    // --- Plan opieki (zadania cykliczne)

    public function storeTemplate(Request $request, Client $client)
    {
        $data = $this->templateData($request);
        $client->careTemplates()->create($data + ['position' => (int) $client->careTemplates()->max('position') + 1]);
        $this->planner->ensureMonth($client);

        return back()->with('success', 'Dodano zadanie do planu opieki.');
    }

    public function updateTemplate(Request $request, CareTemplate $template)
    {
        $template->update($this->templateData($request, partial: true));

        // Zmiany przenoś na jeszcze niewykonane zadania z bieżącego i przyszłych miesięcy
        $template->tasks()->todo()->where('period', '>=', CarePlanner::period())->get()->each(function (CareTask $t) use ($template) {
            [$y, $m] = explode('-', $t->period);
            $first = CarbonImmutable::create((int) $y, (int) $m, 1);
            $t->update([
                'title' => $template->title,
                'description' => $template->description,
                'due_date' => $first->day(min($template->due_day, $first->daysInMonth))->toDateString(),
            ]);
        });
        // Wyłączone / zmienione cyklicznie: usuń niewykonane zadania, które już nie wypadają
        $template->tasks()->todo()->where('period', '>=', CarePlanner::period())->get()
            ->filter(fn (CareTask $t) => ! $template->active || ! $template->isDueIn((int) substr($t->period, 5, 2)))
            ->each->delete();
        $this->planner->ensureMonth($template->client);

        return back()->with('success', 'Zapisano plan.');
    }

    public function destroyTemplate(CareTemplate $template)
    {
        // Historia wykonanych zadań zostaje; niewykonane z bieżącego/przyszłych miesięcy znikają
        $template->tasks()->todo()->where('period', '>=', CarePlanner::period())->delete();
        $template->delete();

        return back()->with('success', 'Usunięto zadanie z planu (historia wykonanych prac została).');
    }

    public function applyPreset(Request $request, Client $client)
    {
        $key = $request->validate(['preset' => ['required', Rule::in(array_keys(CarePlanner::PRESETS))]])['preset'];
        $added = $this->planner->applyPreset($client, $key);

        return back()->with('success', $added
            ? "Dodano pakiet „".CarePlanner::PRESETS[$key]['label']."” ($added zadań). Zadania na ten miesiąc są już na liście."
            : 'Wszystkie zadania z tego pakietu są już w planie.');
    }

    private function templateData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|' : '';

        return $request->validate([
            'title' => $req.'required|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'frequency' => [$partial ? 'sometimes' : 'required', Rule::in(array_keys(CareTemplate::FREQUENCIES))],
            'start_month' => 'sometimes|integer|min:1|max:12',
            'due_day' => $req.'required|integer|min:1|max:31',
            'active' => 'sometimes|boolean',
        ]) + ($partial ? [] : ['start_month' => (int) $request->input('start_month', now()->month)]);
    }

    private function month(Request $request): CarbonImmutable
    {
        $m = (string) $request->input('month');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)
            ? CarbonImmutable::createFromFormat('Y-m-d', "$m-01")->startOfDay()
            : CarbonImmutable::now()->startOfMonth();
    }
}
