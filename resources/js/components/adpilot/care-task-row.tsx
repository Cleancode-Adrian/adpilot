import { Button } from '@/components/ui/button';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type CareStatus, type CareTask } from '@/types/adpilot';
import { router } from '@inertiajs/react';
import { AlarmClock, ChevronDown, CircleSlash, MessageSquare, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';

/** Data lokalna RRRR-MM-DD (nie UTC – inaczej po północy byłby poprzedni dzień). */
export const localDate = (d = new Date()) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const today = () => localDate();

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

export function CareTaskRow({ task, showPeriod = false }: { task: CareTask; showPeriod?: boolean }) {
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState(task.note ?? '');
    const done = task.status === 'done';
    const skipped = task.status === 'skipped';

    const update = (data: { status?: CareStatus; note?: string | null }) =>
        router.patch(`/care/tasks/${task.id}`, data, { preserveScroll: true, preserveState: true });

    return (
        <li className={cn('rounded-lg border px-3 py-2', (done || skipped) && 'bg-muted/40')}>
            <div className="flex items-start gap-3">
                <input
                    type="checkbox"
                    className="mt-1 size-4 shrink-0 cursor-pointer accent-current"
                    checked={done}
                    disabled={skipped}
                    onChange={(e) => update({ status: e.target.checked ? 'done' : 'todo' })}
                    aria-label={done ? 'Oznacz jako niewykonane' : 'Oznacz jako wykonane'}
                />
                <div className="min-w-0 flex-1">
                    <button type="button" onClick={() => setOpen(!open)} className="flex w-full items-start gap-1 text-left">
                        <span className={cn('flex-1 text-sm font-medium', (done || skipped) && 'text-muted-foreground line-through')}>
                            {task.title}
                        </span>
                        {(task.description || task.note) && (
                            <ChevronDown className={cn('mt-0.5 size-4 shrink-0 opacity-50 transition', !open && '-rotate-90')} />
                        )}
                    </button>
                    <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                        <DueBadge task={task} />
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
                        {!task.care_template_id && <span className="text-muted-foreground">jednorazowe</span>}
                    </div>

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
                            <Button variant="ghost" size="sm" title="Pomiń w tym miesiącu" onClick={() => update({ status: 'skipped' })}>
                                <CircleSlash className="size-4" />
                            </Button>
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
