import { SEV, type Sev } from '@/components/adpilot/audit-report';
import { CopyLink } from '@/components/adpilot/copy-link';
import { Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import { dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { scoreTone } from '@/pages/audits/index';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Download, ExternalLink, Pause, Play, RefreshCw, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { BATCH_STATUS, duration, type Progress } from './index';

interface Batch {
    id: number;
    name: string;
    status: 'draft' | 'running' | 'paused' | 'done';
    source: string | null;
    options: { ai?: boolean } | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
}

interface Item {
    id: number;
    label: string;
    url: string;
    pages: string[];
    note: string | null;
    enabled: boolean;
    state: 'skipped' | 'waiting' | 'pending' | 'running' | 'done' | 'failed';
    audit_id: number | null;
    score: number | null;
    areas: Record<string, number | null>;
    counts: Record<Sev, number> | null;
    verify: number;
    company: string | null;
    blocked: string | null;
    error: string | null;
    top: string[];
}

interface Common {
    key: string;
    title: string;
    sev: Sev;
    section: string;
    count: number;
    sites: string[];
}

interface Stats {
    sites: number;
    avg_score: number | null;
    counts: Record<Sev, number>;
    areas: Record<string, number | null>;
    verify: number;
}

const STATE: Record<Item['state'], string> = {
    skipped: 'pominięta',
    waiting: 'czeka',
    pending: 'w kolejce',
    running: 'trwa',
    done: 'gotowa',
    failed: 'nieudana',
};

const path = (u: string) => {
    try {
        return decodeURI(new URL(u).pathname + new URL(u).search);
    } catch {
        return u;
    }
};

function Tile({ label, value, cls, hint }: { label: string; value: string | number; cls?: string; hint?: string }) {
    return (
        <div className="bg-card rounded-xl border p-4" title={hint}>
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className={cn('mt-1 text-2xl font-semibold tracking-tight tabular-nums', cls)}>{value}</div>
        </div>
    );
}

export default function AuditBatchShow({
    batch,
    progress,
    items,
    stats,
    common,
    areas,
    has_ai,
    public_url,
    csv_url,
}: {
    batch: Batch;
    progress: Progress;
    items: Item[];
    stats: Stats;
    common: Common[];
    areas: Record<string, string>;
    has_ai: boolean;
    public_url: string;
    csv_url: string;
}) {
    const draft = batch.status === 'draft';
    const active = batch.status === 'running' || progress.running > 0;
    const [ai, setAi] = useState(has_ai && (batch.options?.ai ?? true));
    const [sort, setSort] = useState<'position' | 'score'>('position');
    const [busy, setBusy] = useState(false);
    const [allCommon, setAllCommon] = useState(false);

    useEffect(() => {
        if (!active) return;
        const t = setInterval(() => router.reload({ only: ['batch', 'progress', 'items', 'stats', 'common'] }), 6000);
        return () => clearInterval(t);
    }, [active]);

    const post = (url: string, data: Record<string, boolean> = {}) =>
        router.post(url, data, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });
    const patch = (url: string, data: Record<string, boolean>) => router.patch(url, data, { preserveScroll: true, preserveState: true });

    const enabled = items.filter((i) => i.enabled).length;
    const subpages = items.filter((i) => i.enabled).reduce((n, i) => n + i.pages.length, 0);
    const finished = progress.done + progress.failed;
    const percent = progress.total ? Math.round((finished / progress.total) * 100) : 0;
    const current = items.find((i) => i.state === 'running') ?? items.find((i) => i.state === 'pending');
    const todo = items.filter((i) => i.enabled && i.state === 'waiting').length;

    const rows = useMemo(() => {
        if (sort === 'position') return items;
        return [...items].sort((a, b) => (a.score ?? 999) - (b.score ?? 999));
    }, [items, sort]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Audyt zbiorczy', href: '/audit-batches' },
                { title: batch.name, href: `/audit-batches/${batch.id}` },
            ]}
        >
            <Head title={`Audyt zbiorczy – ${batch.name}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                            {batch.name}
                            <span className={cn('rounded-full px-2 py-0.5 text-xs font-medium', BATCH_STATUS[batch.status].cls)}>
                                {BATCH_STATUS[batch.status].label}
                            </span>
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Utworzono {dateTime(batch.created_at)}
                            {batch.finished_at && ` · zakończono ${dateTime(batch.finished_at)}`}
                            {batch.source && (
                                <>
                                    {' · '}
                                    <a href={batch.source} target="_blank" rel="noreferrer noopener" className="underline">
                                        lista źródłowa
                                    </a>
                                </>
                            )}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {!draft && stats.sites > 0 && (
                            <>
                                <Button variant="outline" asChild>
                                    <a href={csv_url}>
                                        <Download className="size-4" /> Arkusz uwag (Excel)
                                    </a>
                                </Button>
                                <Button variant="outline" asChild>
                                    <a href={public_url} target="_blank" rel="noreferrer">
                                        <ExternalLink className="size-4" /> Raport zbiorczy
                                    </a>
                                </Button>
                                <CopyLink url={public_url} label="Kopiuj link do raportu" />
                            </>
                        )}
                        <Button
                            variant="ghost"
                            title="Usuń audyt zbiorczy"
                            onClick={() =>
                                confirm(`Usunąć „${batch.name}” razem z audytami wszystkich stron z tej listy?`) &&
                                router.delete(`/audit-batches/${batch.id}`)
                            }
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                </div>

                {/* ---------- podgląd listy przed uruchomieniem ---------- */}
                {draft && (
                    <Section
                        title="Sprawdź listę i uruchom"
                        description="Tak odczytaliśmy listę. Odznacz strony, których nie chcesz teraz sprawdzać – nic nie ruszy, dopóki nie klikniesz „Uruchom”."
                    >
                        <div className="grid gap-3">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Tile label="Strony do sprawdzenia" value={`${enabled} z ${items.length}`} />
                                <Tile
                                    label="Wskazane podstrony"
                                    value={subpages}
                                    hint="Oprócz nich każda strona ma sprawdzaną stronę główną i kontakt"
                                />
                                <Tile
                                    label="Szacowany czas pracy w tle"
                                    value={enabled ? duration(enabled * progress.seconds_per_site) : '–'}
                                    hint={`${duration(progress.seconds_per_site)} na stronę, strony po kolei`}
                                />
                            </div>
                            <label className={cn('flex items-start gap-2 text-sm', !has_ai && 'opacity-60')}>
                                <Checkbox checked={ai} disabled={!has_ai} onCheckedChange={(v) => setAi(v === true)} className="mt-0.5" />
                                <span>
                                    <span className="font-medium">Przegląd treści przez AI</span> – literówki, sprzeczności, błędy w ofercie,
                                    propozycje rozbudowy.{' '}
                                    <span className="text-muted-foreground">
                                        {has_ai
                                            ? 'Dokładniejszy raport, ale dłuższy czas i koszt zapytań AI za każdą stronę. Bez niego zostają wszystkie sprawdzenia automatyczne.'
                                            : 'Niedostępny – brak klucza ANTHROPIC_API_KEY na serwerze.'}
                                    </span>
                                </span>
                            </label>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button disabled={busy || enabled === 0} onClick={() => post(`/audit-batches/${batch.id}/start`, { ai })}>
                                    <Play className="size-4" /> Uruchom audyt ({enabled})
                                </Button>
                                <Button variant="outline" onClick={() => patch(`/audit-batches/${batch.id}`, { enabled: true })}>
                                    Zaznacz wszystkie
                                </Button>
                                <Button variant="outline" onClick={() => patch(`/audit-batches/${batch.id}`, { enabled: false })}>
                                    Odznacz wszystkie
                                </Button>
                                <span className="text-muted-foreground text-xs">
                                    Strony są sprawdzane po kolei w tle – możesz zamknąć kartę i wrócić później.
                                </span>
                            </div>
                        </div>
                    </Section>
                )}

                {/* ---------- postęp ---------- */}
                {!draft && (
                    <Section
                        title={batch.status === 'done' ? 'Audyt zakończony' : 'Postęp'}
                        actions={
                            <div className="flex flex-wrap gap-2">
                                {batch.status === 'running' && (
                                    <Button variant="outline" size="sm" disabled={busy} onClick={() => post(`/audit-batches/${batch.id}/pause`)}>
                                        <Pause className="size-4" /> Wstrzymaj
                                    </Button>
                                )}
                                {(batch.status === 'paused' || (batch.status === 'done' && todo > 0)) && (
                                    <Button size="sm" disabled={busy} onClick={() => post(`/audit-batches/${batch.id}/start`)}>
                                        <Play className="size-4" /> Wznów{todo > 0 ? ` (${todo})` : ''}
                                    </Button>
                                )}
                                {progress.failed > 0 && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={busy}
                                        onClick={() => post(`/audit-batches/${batch.id}/retry-failed`)}
                                    >
                                        <RotateCcw className="size-4" /> Ponów nieudane ({progress.failed})
                                    </Button>
                                )}
                            </div>
                        }
                    >
                        <div
                            className="bg-muted h-2.5 overflow-hidden rounded-full"
                            role="progressbar"
                            aria-valuenow={percent}
                            aria-valuemin={0}
                            aria-valuemax={100}
                        >
                            <div className="h-full rounded-full bg-[#2e8b57] transition-all" style={{ width: `${percent}%` }} />
                        </div>
                        <p className="mt-2 text-sm">
                            <span className="font-medium tabular-nums">
                                {finished} z {progress.total}
                            </span>{' '}
                            stron sprawdzonych
                            {progress.failed > 0 && <span className="text-[#b42f2f] dark:text-[#f08080]"> · {progress.failed} nieudane</span>}
                            {progress.skipped > 0 && <span className="text-muted-foreground"> · {progress.skipped} pominięte</span>}
                            {batch.status === 'running' && finished < progress.total && (
                                <span className="text-muted-foreground"> · do końca {duration(progress.eta_seconds)}</span>
                            )}
                            {batch.status === 'running' && current && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · teraz: <RefreshCw className="inline size-3 animate-spin" /> {current.label}
                                </span>
                            )}
                        </p>
                        {batch.status === 'paused' && (
                            <p className="text-muted-foreground mt-1 text-xs">Wstrzymano – kolejne strony nie ruszą, dopóki nie klikniesz „Wznów”.</p>
                        )}
                    </Section>
                )}

                {/* ---------- wyniki łącznie ---------- */}
                {!draft && stats.sites > 0 && (
                    <>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <Tile label="Średnia ocena" value={`${stats.avg_score ?? '–'}/100`} cls={scoreTone(stats.avg_score).cls} />
                            <Tile
                                label="Pilne"
                                value={stats.counts.pilne}
                                cls={SEV.pilne.text}
                                hint="Traci klientów, wprowadza w błąd albo niesie ryzyko prawne"
                            />
                            <Tile label="Średnie" value={stats.counts.srednie} cls={SEV.srednie.text} />
                            <Tile label="Drobne" value={stats.counts.drobne} cls={SEV.drobne.text} />
                            <Tile
                                label="Do potwierdzenia ręcznie"
                                value={stats.verify}
                                hint="Punkty, które warto sprawdzić w przeglądarce przed przekazaniem dalej"
                            />
                        </div>
                        <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            Średnio w dziedzinach:
                            {Object.entries(areas).map(([k, label]) => (
                                <span key={k}>
                                    {label}{' '}
                                    <span className={cn('font-medium tabular-nums', scoreTone(stats.areas[k] ?? null).cls)}>
                                        {stats.areas[k] ?? '–'}
                                    </span>
                                </span>
                            ))}
                        </div>
                    </>
                )}

                {!draft && common.length > 0 && (
                    <Section
                        title="Najczęstsze problemy"
                        description="Te same rzeczy na wielu stronach – zwykle da się je naprawić jednym sposobem na wszystkich. Kliknij, aby zobaczyć strony."
                    >
                        <ul className="grid gap-1">
                            {(allCommon ? common : common.slice(0, 12)).map((c) => (
                                <li key={c.key}>
                                    <details className="group rounded-md border">
                                        <summary className="flex cursor-pointer list-none items-center gap-2 px-2 py-1.5 text-sm">
                                            <span
                                                className={cn(
                                                    'w-16 shrink-0 rounded px-1 py-0.5 text-center text-[10px] font-bold text-white uppercase',
                                                    SEV[c.sev].bg,
                                                )}
                                            >
                                                {SEV[c.sev].label}
                                            </span>
                                            <span className="min-w-0 flex-1">{c.title}</span>
                                            <span className="shrink-0 font-medium tabular-nums">
                                                {c.count} z {stats.sites}
                                            </span>
                                            <ChevronRight className="text-muted-foreground size-4 shrink-0 transition-transform group-open:rotate-90" />
                                        </summary>
                                        <div className="text-muted-foreground border-t px-2 py-1.5 text-xs">{c.sites.join(' · ')}</div>
                                    </details>
                                </li>
                            ))}
                        </ul>
                        {common.length > 12 && (
                            <Button variant="ghost" size="sm" className="mt-2" onClick={() => setAllCommon(!allCommon)}>
                                {allCommon ? 'Pokaż najważniejsze' : `Pokaż wszystkie (${common.length})`}
                            </Button>
                        )}
                    </Section>
                )}

                {/* ---------- strony ---------- */}
                <Section
                    title={`Strony (${items.length})`}
                    actions={
                        !draft && stats.sites > 1 ? (
                            <div className="flex gap-1 text-xs">
                                <Button variant={sort === 'position' ? 'secondary' : 'ghost'} size="sm" onClick={() => setSort('position')}>
                                    Kolejność z listy
                                </Button>
                                <Button variant={sort === 'score' ? 'secondary' : 'ghost'} size="sm" onClick={() => setSort('score')}>
                                    Od najsłabszych
                                </Button>
                            </div>
                        ) : undefined
                    }
                >
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left text-xs">
                                    <th className="w-8 py-2" />
                                    <th className="py-2 font-medium">Strona</th>
                                    {draft ? (
                                        <th className="py-2 font-medium">Wskazane podstrony</th>
                                    ) : (
                                        <>
                                            <th className="py-2 font-medium">Ocena</th>
                                            {Object.values(areas).map((a) => (
                                                <th key={a} className="hidden py-2 font-medium lg:table-cell">
                                                    {a}
                                                </th>
                                            ))}
                                            <th className="py-2 font-medium">Uwagi</th>
                                            <th className="py-2" />
                                        </>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((i) => {
                                    const canToggle = i.audit_id === null;
                                    return (
                                        <tr key={i.id} className={cn('border-b align-top last:border-0', !i.enabled && 'opacity-60')}>
                                            <td className="py-2">
                                                {canToggle && (
                                                    <Checkbox
                                                        checked={i.enabled}
                                                        aria-label={`Sprawdź ${i.label}`}
                                                        onCheckedChange={(v) =>
                                                            patch(`/audit-batches/${batch.id}/items/${i.id}`, { enabled: v === true })
                                                        }
                                                    />
                                                )}
                                            </td>
                                            <td className="py-2">
                                                <details className="group">
                                                    <summary className="cursor-pointer list-none">
                                                        <span className="font-medium">{i.label}</span>
                                                        {i.company && <span className="text-muted-foreground"> · {i.company}</span>}
                                                        {!draft && (i.pages.length > 0 || i.top.length > 0) && (
                                                            <ChevronRight className="text-muted-foreground ml-1 inline size-3.5 transition-transform group-open:rotate-90" />
                                                        )}
                                                        {i.note && <div className="text-xs text-[#a86a00] dark:text-[#fab219]">{i.note}</div>}
                                                        {i.error && <div className="text-xs text-[#b42f2f] dark:text-[#f08080]">{i.error}</div>}
                                                    </summary>
                                                    {!draft && (
                                                        <div className="mt-1 grid gap-1 text-xs">
                                                            {i.top.length > 0 && (
                                                                <ul className="grid gap-0.5">
                                                                    {i.top.map((t) => (
                                                                        <li key={t} className={SEV.pilne.text}>
                                                                            ● {t}
                                                                        </li>
                                                                    ))}
                                                                </ul>
                                                            )}
                                                            {i.pages.map((p) => (
                                                                <a
                                                                    key={p}
                                                                    href={p}
                                                                    target="_blank"
                                                                    rel="noreferrer noopener"
                                                                    className="text-muted-foreground break-all hover:underline"
                                                                >
                                                                    {path(p)}
                                                                </a>
                                                            ))}
                                                        </div>
                                                    )}
                                                </details>
                                            </td>
                                            {draft ? (
                                                <td className="py-2 text-xs">
                                                    {i.pages.length === 0 ? (
                                                        <span className="text-muted-foreground">
                                                            tylko strona główna i podstrony dobrane automatycznie
                                                        </span>
                                                    ) : (
                                                        <ul className="grid gap-0.5">
                                                            {i.pages.map((p) => (
                                                                <li key={p}>
                                                                    <a
                                                                        href={p}
                                                                        target="_blank"
                                                                        rel="noreferrer noopener"
                                                                        className="break-all hover:underline"
                                                                    >
                                                                        {path(p)}
                                                                    </a>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </td>
                                            ) : (
                                                <>
                                                    <td className="py-2 whitespace-nowrap">
                                                        {i.state === 'done' ? (
                                                            <span className={cn('font-semibold tabular-nums', scoreTone(i.score).cls)}>
                                                                {i.score}/100
                                                            </span>
                                                        ) : i.state === 'running' || i.state === 'pending' ? (
                                                            <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                                                <RefreshCw className={cn('size-3', i.state === 'running' && 'animate-spin')} />{' '}
                                                                {STATE[i.state]}
                                                            </span>
                                                        ) : (
                                                            <span
                                                                className={cn(
                                                                    'text-xs',
                                                                    i.state === 'failed'
                                                                        ? 'text-[#b42f2f] dark:text-[#f08080]'
                                                                        : 'text-muted-foreground',
                                                                )}
                                                            >
                                                                {STATE[i.state]}
                                                            </span>
                                                        )}
                                                    </td>
                                                    {Object.keys(areas).map((k) => (
                                                        <td
                                                            key={k}
                                                            className={cn(
                                                                'hidden py-2 tabular-nums lg:table-cell',
                                                                scoreTone(i.areas[k] ?? null).cls,
                                                            )}
                                                        >
                                                            {i.state === 'done' ? (i.areas[k] ?? '–') : ''}
                                                        </td>
                                                    ))}
                                                    <td className="py-2 text-xs whitespace-nowrap tabular-nums">
                                                        {i.counts && (
                                                            <>
                                                                <span className={cn('font-semibold', SEV.pilne.text)}>{i.counts.pilne}</span> /{' '}
                                                                <span className={SEV.srednie.text}>{i.counts.srednie}</span> /{' '}
                                                                <span className={SEV.drobne.text}>{i.counts.drobne}</span>
                                                            </>
                                                        )}
                                                    </td>
                                                    <td className="py-2 text-right">
                                                        {i.audit_id && (i.state === 'done' || i.state === 'failed') && (
                                                            <Link href={`/audits/${i.audit_id}`} className="text-xs underline">
                                                                Raport
                                                            </Link>
                                                        )}
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    {!draft && (
                        <p className="text-muted-foreground mt-2 text-xs">
                            Uwagi: pilne / średnie / drobne. „Raport” otwiera pełny audyt danej strony (z linkiem i PDF).
                        </p>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
