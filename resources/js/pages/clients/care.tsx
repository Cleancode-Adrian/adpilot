import { CareProgress, CareTaskRow, localDate, MonthSwitcher } from '@/components/adpilot/care-task-row';
import { EmptyState, NativeSelect, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ClientLayout from '@/layouts/client-layout';
import { type CareTask, type CareTemplate, type ClientProps } from '@/types/adpilot';
import { router, useForm } from '@inertiajs/react';
import { Check, ClipboardCheck, PackagePlus, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';

type Frequencies = Record<CareTemplate['frequency'], string>;
const MONTHS = ['sty', 'lut', 'mar', 'kwi', 'maj', 'cze', 'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];

function monthsOf(t: Pick<CareTemplate, 'frequency' | 'start_month'>) {
    const every = { monthly: 1, quarterly: 3, semiannual: 6, yearly: 12 }[t.frequency];
    if (every === 1) return '';
    return MONTHS.filter((_, i) => (((i + 1 - t.start_month) % every) + every) % every === 0).join(', ');
}

function TemplateForm({
    initial,
    frequencies,
    onSubmit,
    onCancel,
    submitLabel,
}: {
    initial: Omit<CareTemplate, 'id'>;
    frequencies: Frequencies;
    onSubmit: (data: Omit<CareTemplate, 'id'>, reset: () => void) => void;
    onCancel?: () => void;
    submitLabel: string;
}) {
    const [d, setD] = useState(initial);
    const set = <K extends keyof typeof d>(k: K, v: (typeof d)[K]) => setD((p) => ({ ...p, [k]: v }));
    return (
        <form
            className="grid gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                onSubmit(d, () => setD(initial));
            }}
        >
            <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto_auto]">
                <div className="grid gap-1.5">
                    <Label>Zadanie</Label>
                    <Input required value={d.title} onChange={(e) => set('title', e.target.value)} placeholder="np. Aktualizacja wtyczek" />
                </div>
                <div className="grid gap-1.5">
                    <Label>Jak często</Label>
                    <NativeSelect value={d.frequency} onChange={(e) => set('frequency', e.target.value as CareTemplate['frequency'])}>
                        {Object.entries(frequencies).map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                {d.frequency !== 'monthly' && (
                    <div className="grid gap-1.5">
                        <Label>Od miesiąca</Label>
                        <NativeSelect value={d.start_month} onChange={(e) => set('start_month', Number(e.target.value))}>
                            {MONTHS.map((m, i) => (
                                <option key={m} value={i + 1}>
                                    {m}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                )}
                <div className="grid gap-1.5">
                    <Label>Termin (dzień)</Label>
                    <Input
                        type="number"
                        min={1}
                        max={31}
                        className="w-24"
                        value={d.due_day}
                        onChange={(e) => set('due_day', Number(e.target.value))}
                    />
                </div>
            </div>
            <div className="grid gap-1.5">
                <Label>Opis / checklista (opcjonalnie)</Label>
                <textarea
                    rows={2}
                    value={d.description ?? ''}
                    onChange={(e) => set('description', e.target.value)}
                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                    placeholder="Co dokładnie sprawdzić, gdzie, na co uważać…"
                />
            </div>
            {d.frequency !== 'monthly' && <p className="text-muted-foreground text-xs">Wypada w miesiącach: {monthsOf(d)}</p>}
            <div className="flex gap-2">
                <Button type="submit" size="sm">
                    <Check className="size-4" /> {submitLabel}
                </Button>
                {onCancel && (
                    <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
                        <X className="size-4" /> Anuluj
                    </Button>
                )}
            </div>
        </form>
    );
}

function TemplateRow({ t, frequencies }: { t: CareTemplate; frequencies: Frequencies }) {
    const [editing, setEditing] = useState(false);
    if (editing) {
        return (
            <li className="rounded-lg border p-3">
                <TemplateForm
                    initial={t}
                    frequencies={frequencies}
                    submitLabel="Zapisz"
                    onCancel={() => setEditing(false)}
                    onSubmit={(data) => router.patch(`/care/templates/${t.id}`, data, { preserveScroll: true, onSuccess: () => setEditing(false) })}
                />
            </li>
        );
    }
    return (
        <li className="flex items-start gap-3 rounded-lg border px-3 py-2">
            <input
                type="checkbox"
                className="mt-1 size-4"
                checked={t.active}
                title={t.active ? 'Aktywne – kliknij, by wstrzymać' : 'Wstrzymane – kliknij, by włączyć'}
                onChange={(e) => router.patch(`/care/templates/${t.id}`, { active: e.target.checked }, { preserveScroll: true })}
            />
            <div className="min-w-0 flex-1">
                <div className={`text-sm font-medium ${t.active ? '' : 'text-muted-foreground line-through'}`}>{t.title}</div>
                <div className="text-muted-foreground text-xs">
                    {frequencies[t.frequency]}
                    {t.frequency !== 'monthly' && ` (${monthsOf(t)})`} · do {t.due_day}. dnia miesiąca{!t.active && ' · wstrzymane'}
                </div>
            </div>
            <Button variant="ghost" size="sm" title="Edytuj" onClick={() => setEditing(true)}>
                <Pencil className="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="sm"
                title="Usuń z planu"
                onClick={() =>
                    confirm(`Usunąć „${t.title}” z planu? Historia wykonanych prac zostanie.`) &&
                    router.delete(`/care/templates/${t.id}`, { preserveScroll: true })
                }
            >
                <Trash2 className="size-4" />
            </Button>
        </li>
    );
}

export default function Care({
    client,
    period,
    tasks,
    overdue,
    templates,
    history,
    frequencies,
    presets,
}: {
    client: ClientProps;
    period: string;
    tasks: CareTask[];
    overdue: CareTask[];
    templates: CareTemplate[];
    history: { period: string; total: number; done: number }[];
    frequencies: Frequencies;
    presets: Record<string, { label: string; count: number }>;
}) {
    const [adding, setAdding] = useState(false);
    const lastDay = (() => {
        const [y, m] = period.split('-').map(Number);
        const t = localDate();
        return t.startsWith(period) ? t : localDate(new Date(y, m, 0));
    })();
    const taskForm = useForm({ title: '', description: '', due_date: lastDay });
    const done = tasks.filter((t) => t.status !== 'todo').length;
    const go = (p: string) => router.get(`/clients/${client.id}/care`, { month: p }, { preserveScroll: true });

    const presetButtons = (
        <div className="flex flex-wrap gap-2">
            {Object.entries(presets).map(([key, p]) => (
                <Button
                    key={key}
                    variant="outline"
                    size="sm"
                    onClick={() => router.post(`/clients/${client.id}/care/preset`, { preset: key }, { preserveScroll: true })}
                >
                    <PackagePlus className="size-4" /> {p.label} ({p.count})
                </Button>
            ))}
        </div>
    );

    return (
        <ClientLayout client={client} title="Zadania i opieka">
            <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
                <div className="grid content-start gap-4">
                    {overdue.length > 0 && (
                        <Section
                            title={`Zaległe z poprzednich miesięcy (${overdue.length})`}
                            description="Zrób, pomiń albo przenieś do notatki – nie znikną same."
                        >
                            <ul className="grid gap-2">
                                {overdue.map((t) => (
                                    <CareTaskRow key={t.id} task={t} showPeriod />
                                ))}
                            </ul>
                        </Section>
                    )}

                    <Section
                        title="Zadania w miesiącu"
                        description="Odhacz po wykonaniu. Rozwiń zadanie, żeby zobaczyć checklistę i dopisać notatkę z wykonania."
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <MonthSwitcher period={period} onChange={go} />
                                {done > 0 && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => router.post(`/clients/${client.id}/work-reports`, { month: period })}
                                    >
                                        <ClipboardCheck className="size-4" /> Raport z prac
                                    </Button>
                                )}
                            </div>
                        }
                    >
                        {tasks.length > 0 && (
                            <div className="mb-3">
                                <CareProgress done={done} total={tasks.length} />
                            </div>
                        )}
                        {tasks.length === 0 ? (
                            <EmptyState title="Brak zadań w tym miesiącu">
                                {templates.length === 0
                                    ? 'Dodaj plan opieki (po prawej) – zadania będą pojawiać się co miesiąc same.'
                                    : 'W tym miesiącu nic nie wypada z planu. Możesz dodać zadanie jednorazowe.'}
                            </EmptyState>
                        ) : (
                            <ul className="grid gap-2">
                                {tasks.map((t) => (
                                    <CareTaskRow key={t.id} task={t} />
                                ))}
                            </ul>
                        )}

                        <div className="mt-4 border-t pt-4">
                            {!adding ? (
                                <Button variant="outline" size="sm" onClick={() => setAdding(true)}>
                                    <Plus className="size-4" /> Zadanie jednorazowe
                                </Button>
                            ) : (
                                <form
                                    className="grid gap-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        taskForm.post(`/clients/${client.id}/care/tasks`, {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                taskForm.reset();
                                                setAdding(false);
                                            },
                                        });
                                    }}
                                >
                                    <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
                                        <div className="grid gap-1.5">
                                            <Label>Zadanie</Label>
                                            <Input
                                                required
                                                value={taskForm.data.title}
                                                onChange={(e) => taskForm.setData('title', e.target.value)}
                                                placeholder="np. Wymiana banera na stronie głównej"
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label>Termin</Label>
                                            <Input
                                                type="date"
                                                required
                                                value={taskForm.data.due_date}
                                                onChange={(e) => taskForm.setData('due_date', e.target.value)}
                                            />
                                        </div>
                                    </div>
                                    <textarea
                                        rows={2}
                                        value={taskForm.data.description}
                                        onChange={(e) => taskForm.setData('description', e.target.value)}
                                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                        placeholder="Szczegóły (opcjonalnie)"
                                    />
                                    <div className="flex gap-2">
                                        <Button type="submit" size="sm" disabled={taskForm.processing}>
                                            <Check className="size-4" /> Dodaj
                                        </Button>
                                        <Button type="button" variant="ghost" size="sm" onClick={() => setAdding(false)}>
                                            Anuluj
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </div>
                    </Section>
                </div>

                <div className="grid content-start gap-4">
                    <Section title="Plan opieki" description="Zadania stałe – co miesiąc same pojawiają się na liście i w przypomnieniach.">
                        {templates.length === 0 ? (
                            <div className="grid gap-3">
                                <p className="text-muted-foreground text-sm">Zacznij od gotowego pakietu – potem możesz go dowolnie edytować:</p>
                                {presetButtons}
                            </div>
                        ) : (
                            <ul className="grid gap-2">
                                {templates.map((t) => (
                                    <TemplateRow key={t.id} t={t} frequencies={frequencies} />
                                ))}
                            </ul>
                        )}
                        <details className="mt-4 border-t pt-4">
                            <summary className="cursor-pointer text-sm font-medium">+ Dodaj zadanie stałe</summary>
                            <div className="mt-3">
                                <TemplateForm
                                    initial={{
                                        title: '',
                                        description: '',
                                        frequency: 'monthly',
                                        start_month: new Date().getMonth() + 1,
                                        due_day: 10,
                                        active: true,
                                    }}
                                    frequencies={frequencies}
                                    submitLabel="Dodaj do planu"
                                    onSubmit={(data, reset) =>
                                        router.post(`/clients/${client.id}/care/templates`, data, { preserveScroll: true, onSuccess: reset })
                                    }
                                />
                            </div>
                        </details>
                        {templates.length > 0 && (
                            <details className="mt-3">
                                <summary className="text-muted-foreground cursor-pointer text-sm">Dodaj gotowy pakiet</summary>
                                <div className="mt-2">{presetButtons}</div>
                            </details>
                        )}
                    </Section>

                    {history.length > 0 && (
                        <Section title="Historia">
                            <ul className="grid gap-2">
                                {history.map((h) => (
                                    <li key={h.period}>
                                        <button
                                            type="button"
                                            onClick={() => go(h.period)}
                                            className="flex w-full items-center justify-between gap-2 text-sm hover:underline"
                                        >
                                            <span className="capitalize">
                                                {new Date(`${h.period}-01T12:00:00`).toLocaleDateString('pl-PL', { month: 'long', year: 'numeric' })}
                                            </span>
                                            <CareProgress done={Number(h.done)} total={Number(h.total)} />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </Section>
                    )}
                </div>
            </div>
        </ClientLayout>
    );
}
