import { Button } from '@/components/ui/button';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type CareStatus, type CareTask } from '@/types/adpilot';
import { router } from '@inertiajs/react';
import { AlarmClock, CalendarClock, ChevronDown, CircleSlash, MessageSquare, RotateCcw, Timer, Trash2 } from 'lucide-react';
import { useState } from 'react';

/** Data lokalna RRRR-MM-DD (nie UTC – inaczej po północy byłby poprzedni dzień). */
export const localDate = (d = new Date()) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const today = () => localDate();
const addDays = (iso: string, n: number) => {
    const [y, m, d] = iso.split('-').map(Number);
    return localDate(new Date(y, m - 1, d + n));
};

/** Zmiana terminu: kalendarz + szybkie przesunięcia. */
function DueDateEditor({ task, onChange, onClose }: { task: CareTask; onChange: (due: string) => void; onClose: () => void }) {
    const base = task.due_date < today() ? today() : task.due_date.slice(0, 10);
    const quick: [string, string][] = [
        ['Dziś', today()],
        ['Jutro', addDays(today(), 1)],
        ['+3 dni', addDays(base, 3)],
        ['+tydzień', addDays(base, 7)],
        [
            '+miesiąc',
            (() => {
                const [y, m, d] = base.split('-').map(Number);
                const last = new Date(y, m + 1, 0).getDate();
                return localDate(new Date(y, m, Math.min(d, last)));
            })(),
        ],
    ];
    return (
        <div className="bg-muted/40 mt-2 flex flex-wrap items-center gap-2 rounded-md border px-2 py-1.5">
            <span className="text-muted-foreground text-xs">Termin:</span>
            <input
                type="date"
                defaultValue={task.due_date.slice(0, 10)}
                onChange={(e) => e.target.value && onChange(e.target.value)}
                className="border-input bg-background h-8 rounded-md border px-2 text-sm"
                aria-label="Nowy termin"
            />
            {quick.map(([label, value]) => (
                <Button key={label} type="button" variant="outline" size="sm" className="h-7 px-2 text-xs" onClick={() => onChange(value)}>
                    {label}
                </Button>
            ))}
            <Button type="button" variant="ghost" size="sm" className="ml-auto h-7 px-2 text-xs" onClick={onClose}>
                Zamknij
            </Button>
        </div>
    );
}

/** Termin: zawsze ikona + tekst (nie sam kolor). */
function DueBadge({ task }: { task: CareTask }) {
    if (task.status !== 'todo') return null;
    const t = today();
    if (task.due_date < t) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full border border-[#d03b3b]/40 bg-[#d03b3b]/10 px-2 py-0.5 text-xs font-medium text-[#b42f2f] dark:text-[#f08080]">
                <AlarmClock className="size-3" /> po terminie ({date(task.due_date)})
            </span>
        );
    }
    if (task.due_date === t) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full border border-[#fab219]/50 bg-[#fab219]/15 px-2 py-0.5 text-xs font-medium text-[#7a5600] dark:text-[#fab219]">
                <AlarmClock className="size-3" /> na dziś
            </span>
        );
    }
    return <span className="text-muted-foreground text-xs">do {date(task.due_date)}</span>;
}

/** 90 → „1 h 30 min” */
export const formatMinutes = (m: number | null | undefined) => {
    if (!m) return '';
    const h = Math.floor(m / 60);
    const r = m % 60;
    return h ? `${h} h${r ? ` ${r} min` : ''}` : `${r} min`;
};

/** „1:30”, „1,5 h”, „90”, „45m” → minuty */
const parseMinutes = (v: string): number | null => {
    const s = v.trim().toLowerCase().replace(',', '.');
    if (!s) return null;
    const hm = s.match(/^(\d+):(\d{1,2})$/);
    if (hm) return Number(hm[1]) * 60 + Number(hm[2]);
    const h = s.match(/^(\d+(?:\.\d+)?)\s*h$/);
    if (h) return Math.round(Number(h[1]) * 60);
    const m = s.match(/^(\d+)\s*(m|min)?$/);
    return m ? Number(m[1]) : null;
};

/** Czas pracy: pole + szybkie przyciski (dodają do wpisanego). */
function TimeInput({ task, onSave }: { task: CareTask; onSave: (minutes: number | null) => void }) {
    const [value, setValue] = useState(task.minutes ? String(task.minutes) : '');
    const save = (v: string) => {
        const m = parseMinutes(v);
        if (m !== (task.minutes ?? null)) onSave(m);
    };
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <Timer className="text-muted-foreground size-4" />
            <span className="text-muted-foreground text-xs">Czas pracy:</span>
            <input
                value={value}
                onChange={(e) => setValue(e.target.value)}
                onBlur={(e) => save(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                placeholder="min, np. 45 lub 1:30"
                className="border-input bg-background h-7 w-36 rounded-md border px-2 text-sm"
                aria-label="Czas pracy w minutach"
            />
            {[15, 30, 60].map((n) => (
                <Button
                    key={n}
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-7 px-2 text-xs"
                    onClick={() => {
                        const next = (parseMinutes(value) ?? 0) + n;
                        setValue(String(next));
                        onSave(next);
                    }}
                >
                    +{n < 60 ? `${n} min` : '1 h'}
                </Button>
            ))}
            {task.minutes ? <span className="text-muted-foreground text-xs">= {formatMinutes(task.minutes)}</span> : null}
        </div>
    );
}

export function CareTaskRow({ task, showPeriod = false }: { task: CareTask; showPeriod?: boolean }) {
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState(task.note ?? '');
    const [editDate, setEditDate] = useState(false);
    const done = task.status === 'done';
    const skipped = task.status === 'skipped';

    const update = (data: { status?: CareStatus; note?: string | null; due_date?: string; minutes?: number | null }) =>
        router.patch(`/care/tasks/${task.id}`, data, { preserveScroll: true, preserveState: true });

    return (
        <li className={cn('rounded-lg border px-3 py-2', (done || skipped) && 'bg-muted/40')}>
            <div className="flex items-start gap-3">
                <input
                    type="checkbox"
                    className="mt-1 size-4 shrink-0 cursor-pointer accent-current"
                    checked={done}
                    disabled={skipped}
                    onChange={(e) => {
                        update({ status: e.target.checked ? 'done' : 'todo' });
                        if (e.target.checked) setOpen(true);
                    }}
                    aria-label={done ? 'Oznacz jako niewykonane' : 'Oznacz jako wykonane'}
                />
                <div className="min-w-0 flex-1">
                    <button type="button" onClick={() => setOpen(!open)} className="flex w-full items-start gap-1 text-left">
                        <span className={cn('flex-1 text-sm font-medium', (done || skipped) && 'text-muted-foreground line-through')}>
                            {task.title}
                        </span>
                        {(task.description || task.note || done) && (
                            <ChevronDown className={cn('mt-0.5 size-4 shrink-0 opacity-50 transition', !open && '-rotate-90')} />
                        )}
                    </button>
                    <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        {task.status === 'todo' ? (
                            <button type="button" onClick={() => setEditDate(!editDate)} title="Zmień termin" className="hover:underline">
                                <DueBadge task={task} />
                            </button>
                        ) : (
                            <DueBadge task={task} />
                        )}
                        {showPeriod && <span className="text-muted-foreground">z miesiąca {task.period}</span>}
                        {done && task.done_at && (
                            <span className="text-muted-foreground">
                                wykonano {date(task.done_at)}
                                {task.done_by ? ` · ${task.done_by.name}` : ''}
                            </span>
                        )}
                        {skipped && <span className="text-muted-foreground">pominięte w tym miesiącu</span>}
                        {task.note && !open && (
                            <span className="text-muted-foreground inline-flex items-center gap-1">
                                <MessageSquare className="size-3" /> notatka
                            </span>
                        )}
                        {!!task.minutes && (
                            <span className="text-muted-foreground inline-flex items-center gap-1">
                                <Timer className="size-3" /> {formatMinutes(task.minutes)}
                            </span>
                        )}
                        {!task.care_template_id && <span className="text-muted-foreground">jednorazowe</span>}
                    </div>

                    {editDate && task.status === 'todo' && (
                        <DueDateEditor
                            task={task}
                            onClose={() => setEditDate(false)}
                            onChange={(due) => {
                                update({ due_date: due });
                                setEditDate(false);
                            }}
                        />
                    )}

                    {open && (
                        <div className="mt-2 space-y-2">
                            {task.description && <p className="text-muted-foreground text-sm whitespace-pre-line">{task.description}</p>}
                            <textarea
                                value={note}
                                onChange={(e) => setNote(e.target.value)}
                                onBlur={() => note !== (task.note ?? '') && update({ note: note || null })}
                                rows={2}
                                placeholder="Notatka z wykonania (np. zaktualizowano 12 wtyczek, wersja WP 6.9, problem z…) – zapisuje się automatycznie"
                                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                            />
                            {task.status !== 'skipped' && <TimeInput task={task} onSave={(minutes) => update({ minutes })} />}
                        </div>
                    )}
                </div>
                <div className="flex shrink-0 gap-1">
                    {skipped ? (
                        <Button variant="ghost" size="sm" title="Przywróć" onClick={() => update({ status: 'todo' })}>
                            <RotateCcw className="size-4" />
                        </Button>
                    ) : (
                        !done && (
                            <>
                                <Button variant="ghost" size="sm" title="Zmień termin" onClick={() => setEditDate(!editDate)}>
                                    <CalendarClock className="size-4" />
                                </Button>
                                <Button variant="ghost" size="sm" title="Pomiń w tym miesiącu" onClick={() => update({ status: 'skipped' })}>
                                    <CircleSlash className="size-4" />
                                </Button>
                            </>
                        )
                    )}
                    {!task.care_template_id && (
                        <Button
                            variant="ghost"
                            size="sm"
                            title="Usuń"
                            onClick={() =>
                                confirm(`Usunąć zadanie „${task.title}”?`) && router.delete(`/care/tasks/${task.id}`, { preserveScroll: true })
                            }
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    )}
                </div>
            </div>
        </li>
    );
}

/** Pasek postępu: wykonane / wszystkie. */
export function CareProgress({ done, total }: { done: number; total: number }) {
    const p = total ? Math.round((done / total) * 100) : 0;
    return (
        <div className="flex items-center gap-2">
            <div
                className="bg-muted h-2 w-28 overflow-hidden rounded-full"
                role="progressbar"
                aria-valuenow={p}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div className="h-full rounded-full bg-[#1baf7a] dark:bg-[#199e70]" style={{ width: `${p}%` }} />
            </div>
            <span className="text-muted-foreground text-xs tabular-nums">
                {done}/{total}
            </span>
        </div>
    );
}

/** Przełącznik miesiąca RRRR-MM. */
export function MonthSwitcher({ period, onChange }: { period: string; onChange: (p: string) => void }) {
    const [y, m] = period.split('-').map(Number);
    const shift = (d: number) => {
        const dt = new Date(y, m - 1 + d, 1);
        onChange(`${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}`);
    };
    const label = new Date(y, m - 1, 1).toLocaleDateString('pl-PL', { month: 'long', year: 'numeric' });
    const current = localDate().slice(0, 7);
    return (
        <div className="flex items-center gap-1">
            <Button variant="outline" size="sm" onClick={() => shift(-1)} aria-label="Poprzedni miesiąc">
                ‹
            </Button>
            <span className="min-w-36 text-center text-sm font-medium capitalize">{label}</span>
            <Button variant="outline" size="sm" onClick={() => shift(1)} aria-label="Następny miesiąc">
                ›
            </Button>
            {period !== current && (
                <Button variant="ghost" size="sm" onClick={() => onChange(current)}>
                    Bieżący
                </Button>
            )}
        </div>
    );
}
