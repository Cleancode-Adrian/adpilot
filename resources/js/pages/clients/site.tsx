import { EmptyState, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ClientLayout from '@/layouts/client-layout';
import { date, dateTime, num } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type ClientProps } from '@/types/adpilot';
import { router } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    Download,
    ExternalLink,
    Info,
    Lock,
    LockOpen,
    Play,
    RefreshCw,
    ScanSearch,
    Timer,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';

type Severity = 'critical' | 'high' | 'medium' | 'low';
interface Issue {
    code: string;
    severity: Severity;
    title: string;
    description: string;
    action: string;
    count: number;
    urls: string[];
    details: Record<string, unknown> | null;
    scope: 'site' | 'pages';
}
interface Audit {
    id: number;
    status: 'pending' | 'running' | 'done' | 'failed';
    score: number | null;
    pages_crawled: number;
    created_at: string;
    finished_at: string | null;
    error: string | null;
    issues: Issue[] | null;
    summary: {
        pages: number;
        html_pages: number;
        avg_ms: number | null;
        https: boolean;
        sitemap_urls: number;
        counts: Partial<Record<Severity, number>>;
        limit_reached: boolean;
    } | null;
    pages:
        | { url: string; status: number; ms: number | null; title?: string; h1?: string[]; noindex?: boolean; words?: number; redirects?: number }[]
        | null;
}
interface Monitor {
    enabled: boolean;
    url: string;
    status: 'up' | 'down' | 'unknown';
    last_checked_at: string | null;
    last_status_code: number | null;
    last_response_ms: number | null;
    last_error: string | null;
    down_since: string | null;
    ssl_expires_at: string | null;
    ssl_issuer: string | null;
    ssl_error: string | null;
    ssl_days: number | null;
    uptime_7: number | null;
    uptime_30: number | null;
    avg_ms_7: number | null;
}

const SEV: Record<Severity, { label: string; icon: typeof Info; cls: string }> = {
    critical: { label: 'Krytyczne', icon: AlertOctagon, cls: 'text-[#b42f2f] bg-[#d03b3b]/10 border-[#d03b3b]/40 dark:text-[#f08080]' },
    high: { label: 'Wysokie', icon: AlertTriangle, cls: 'text-[#a4471f] bg-[#ec835a]/12 border-[#ec835a]/50 dark:text-[#ec835a]' },
    medium: { label: 'Średnie', icon: Info, cls: 'text-[#7a5600] bg-[#fab219]/15 border-[#fab219]/50 dark:text-[#fab219]' },
    low: { label: 'Niskie', icon: Info, cls: 'text-muted-foreground bg-muted border-border' },
};

/** Ocena 0–100: liczba + słowo (nie sam kolor). */
function Score({ value }: { value: number }) {
    const [label, cls] =
        value >= 90
            ? ['bardzo dobrze', 'text-[#0a7d0a] dark:text-[#4ccc4c]']
            : value >= 70
              ? ['dobrze', 'text-[#0a7d0a] dark:text-[#4ccc4c]']
              : value >= 50
                ? ['do poprawy', 'text-[#a4471f] dark:text-[#ec835a]']
                : ['słabo', 'text-[#b42f2f] dark:text-[#f08080]'];
    return (
        <div>
            <div className={cn('text-4xl font-bold tabular-nums', cls)}>
                {value}
                <span className="text-muted-foreground text-lg font-normal">/100</span>
            </div>
            <div className={cn('text-sm font-medium', cls)}>{label}</div>
        </div>
    );
}

function IssueRow({ issue }: { issue: Issue }) {
    const [open, setOpen] = useState(false);
    const s = SEV[issue.severity];
    const details = issue.details as Record<string, unknown> | null;
    return (
        <li className="rounded-lg border">
            <button type="button" onClick={() => setOpen(!open)} className="flex w-full items-start gap-2 px-3 py-2 text-left">
                <span className={cn('inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium', s.cls)}>
                    <s.icon className="size-3" /> {s.label}
                </span>
                <span className="flex-1 text-sm font-medium">
                    {issue.title}
                    {issue.scope === 'pages' && (
                        <span className="text-muted-foreground font-normal">
                            {' '}
                            · {issue.count} {issue.count === 1 ? 'adres' : 'adresów'}
                        </span>
                    )}
                </span>
                <ChevronDown className={cn('mt-0.5 size-4 shrink-0 opacity-50 transition', !open && '-rotate-90')} />
            </button>
            {open && (
                <div className="space-y-2 border-t px-3 py-2 text-sm">
                    <p className="text-muted-foreground">{issue.description}</p>
                    <p>
                        <strong>Jak naprawić:</strong> {issue.action}
                    </p>
                    {issue.urls.length > 0 && (
                        <ul className="bg-muted max-h-72 space-y-0.5 overflow-auto rounded-md p-2 font-mono text-xs">
                            {issue.urls.map((u) => {
                                const d = details?.[u];
                                return (
                                    <li key={u} className="break-all">
                                        <a href={u} target="_blank" rel="noreferrer noopener" className="hover:underline">
                                            {u}
                                        </a>
                                        {Array.isArray(d) && <span className="text-muted-foreground"> ← link na: {(d as string[]).join(', ')}</span>}
                                        {typeof d === 'string' && <span className="text-muted-foreground"> ({d} obrazów bez alt)</span>}
                                        {!!d && typeof d === 'object' && !Array.isArray(d) && 'status' in (d as object) && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                ({String((d as { status: unknown }).status)}) ← na: {((d as { on: string[] }).on ?? []).join(', ')}
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                            {issue.count > issue.urls.length && (
                                <li className="text-muted-foreground">… i {issue.count - issue.urls.length} więcej</li>
                            )}
                        </ul>
                    )}
                </div>
            )}
        </li>
    );
}

function MonitorSection({
    client,
    monitor,
    incidents,
}: {
    client: ClientProps;
    monitor: Monitor | null;
    incidents: { id: number; started_at: string; ended_at: string | null; reason: string }[];
}) {
    const [url, setUrl] = useState(monitor?.url ?? client.website_url);
    const base = `/clients/${client.id}`;
    const status = !monitor || !monitor.enabled ? 'off' : monitor.status;

    return (
        <Section
            title="Dostępność i certyfikat SSL"
            description="Sprawdzanie co 5 minut – jeśli strona przestanie działać, od razu dostaniesz e-mail."
            actions={
                <Button variant="outline" size="sm" onClick={() => router.post(`${base}/site/check`, {}, { preserveScroll: true })}>
                    <RefreshCw className="size-4" /> Sprawdź teraz
                </Button>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <div className="text-muted-foreground text-xs">Status</div>
                    <div className="mt-1 flex items-center gap-1.5 font-semibold">
                        {status === 'up' && (
                            <>
                                <CheckCircle2 className="size-5 text-[#0a7d0a] dark:text-[#4ccc4c]" /> Działa
                            </>
                        )}
                        {status === 'down' && (
                            <>
                                <XCircle className="size-5 text-[#b42f2f] dark:text-[#f08080]" /> Nie działa
                            </>
                        )}
                        {status === 'unknown' && (
                            <>
                                <Timer className="text-muted-foreground size-5" /> Czeka na pierwszy pomiar
                            </>
                        )}
                        {status === 'off' && <span className="text-muted-foreground">Wyłączony</span>}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {monitor?.down_since && status === 'down' && <>od {dateTime(monitor.down_since)} · </>}
                        {monitor?.last_error ?? (monitor?.last_checked_at ? `sprawdzono ${dateTime(monitor.last_checked_at)}` : '')}
                    </div>
                </div>
                <div>
                    <div className="text-muted-foreground text-xs">Dostępność</div>
                    <div className="mt-1 font-semibold tabular-nums">{monitor?.uptime_30 != null ? `${num(monitor.uptime_30, 2)}%` : '–'}</div>
                    <div className="text-muted-foreground text-xs">
                        30 dni · 7 dni: {monitor?.uptime_7 != null ? `${num(monitor.uptime_7, 2)}%` : '–'}
                    </div>
                </div>
                <div>
                    <div className="text-muted-foreground text-xs">Czas odpowiedzi</div>
                    <div className="mt-1 font-semibold tabular-nums">
                        {monitor?.last_response_ms != null ? `${num(monitor.last_response_ms)} ms` : '–'}
                    </div>
                    <div className="text-muted-foreground text-xs">średnio 7 dni: {monitor?.avg_ms_7 ? `${num(monitor.avg_ms_7)} ms` : '–'}</div>
                </div>
                <div>
                    <div className="text-muted-foreground text-xs">Certyfikat SSL</div>
                    <div className="mt-1 flex items-center gap-1.5 font-semibold">
                        {monitor?.ssl_days == null ? (
                            <span className="text-muted-foreground">{monitor?.ssl_error ?? '–'}</span>
                        ) : monitor.ssl_days < 0 ? (
                            <>
                                <LockOpen className="size-4 text-[#b42f2f]" /> Wygasł
                            </>
                        ) : (
                            <>
                                {monitor.ssl_days <= 14 ? (
                                    <AlertTriangle className="size-4 text-[#a4471f]" />
                                ) : (
                                    <Lock className="size-4 text-[#0a7d0a] dark:text-[#4ccc4c]" />
                                )}
                                {monitor.ssl_days} dni
                            </>
                        )}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {monitor?.ssl_expires_at && <>do {date(monitor.ssl_expires_at)}</>}
                        {monitor?.ssl_issuer && <> · {monitor.ssl_issuer}</>}
                    </div>
                </div>
            </div>

            {incidents.length > 0 && (
                <div className="mt-4 border-t pt-3">
                    <div className="mb-2 text-sm font-medium">Ostatnie awarie</div>
                    <ul className="grid gap-1 text-sm">
                        {incidents.map((i) => (
                            <li key={i.id} className="flex flex-wrap gap-x-3">
                                <span className="tabular-nums">{dateTime(i.started_at)}</span>
                                <span className="text-muted-foreground">
                                    {i.ended_at
                                        ? `trwała ${Math.max(1, Math.round((new Date(i.ended_at).getTime() - new Date(i.started_at).getTime()) / 60000))} min`
                                        : 'trwa'}
                                </span>
                                <span className="text-muted-foreground">{i.reason}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <form
                className="mt-4 flex flex-wrap items-center gap-2 border-t pt-3"
                onSubmit={(e) => {
                    e.preventDefault();
                    router.post(`${base}/site/monitor`, { url, enabled: true }, { preserveScroll: true });
                }}
            >
                <span className="text-muted-foreground text-xs">Sprawdzany adres:</span>
                <Input value={url} onChange={(e) => setUrl(e.target.value)} className="h-8 max-w-sm text-sm" />
                <Button type="submit" size="sm" variant="outline" className="h-8">
                    Zapisz
                </Button>
                {monitor?.enabled && (
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-8"
                        onClick={() => router.post(`${base}/site/monitor`, { url, enabled: false }, { preserveScroll: true })}
                    >
                        Wyłącz monitoring
                    </Button>
                )}
            </form>
        </Section>
    );
}

export default function Site({
    client,
    monitor,
    incidents,
    audit,
    comparison,
    history,
    public_url,
    pdf_url,
}: {
    client: ClientProps;
    monitor: Monitor | null;
    incidents: { id: number; started_at: string; ended_at: string | null; reason: string }[];
    audit: Audit | null;
    comparison: { previous_score: number | null; previous_date: string; fixed: string[]; new: string[] } | null;
    history: { id: number; status: string; score: number | null; pages_crawled: number; created_at: string }[];
    public_url: string | null;
    pdf_url: string | null;
}) {
    const base = `/clients/${client.id}`;
    const running = audit && (audit.status === 'pending' || audit.status === 'running');
    const [showPages, setShowPages] = useState(false);

    // audyt w toku – odśwież co 5 s
    useEffect(() => {
        if (!running) return;
        const t = setInterval(() => router.reload({ only: ['audit', 'history', 'comparison', 'public_url', 'pdf_url', 'client'] }), 5000);
        return () => clearInterval(t);
    }, [running]);

    return (
        <ClientLayout client={client} title="Strona">
            <MonitorSection client={client} monitor={monitor} incidents={incidents} />

            <Section
                title="Audyt strony – SEO i technika"
                description="Przechodzi po stronie jak robot Google (do 60 podstron): błędy, linki, tytuły, nagłówki, obrazy, indeksowanie, szybkość, HTTPS."
                actions={
                    <div className="flex flex-wrap gap-2">
                        {public_url && (
                            <>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={public_url} target="_blank" rel="noreferrer">
                                        <ExternalLink className="size-4" /> Raport dla klienta
                                    </a>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={pdf_url!}>
                                        <Download className="size-4" /> PDF
                                    </a>
                                </Button>
                            </>
                        )}
                        <Button size="sm" disabled={!!running} onClick={() => router.post(`${base}/site/audit`, {}, { preserveScroll: true })}>
                            {running ? <RefreshCw className="size-4 animate-spin" /> : <Play className="size-4" />}{' '}
                            {running ? 'Audyt trwa…' : 'Uruchom audyt'}
                        </Button>
                    </div>
                }
            >
                {!audit ? (
                    <EmptyState title="Jeszcze nie było audytu">
                        <ScanSearch className="mx-auto mb-2 size-6" />
                        Kliknij „Uruchom audyt” – wynik w 1–3 minuty. Strony z opieką są audytowane automatycznie 1. dnia miesiąca.
                    </EmptyState>
                ) : running ? (
                    <div className="text-muted-foreground flex items-center gap-2 py-6 text-sm">
                        <RefreshCw className="size-4 animate-spin" /> Sprawdzam stronę{' '}
                        {audit.status === 'running' ? `(start ${dateTime(audit.created_at)})` : '– w kolejce'}…
                    </div>
                ) : audit.status === 'failed' ? (
                    <p className="text-sm text-[#b42f2f] dark:text-[#f08080]">Audyt nie powiódł się: {audit.error}</p>
                ) : (
                    <div className="grid gap-5">
                        <div className="flex flex-wrap items-center gap-8">
                            {audit.score !== null && <Score value={audit.score} />}
                            <div className="grid grid-cols-2 gap-x-8 gap-y-1 text-sm sm:grid-cols-4">
                                {(['critical', 'high', 'medium', 'low'] as Severity[]).map((s) => (
                                    <div key={s} className="flex items-center gap-1.5">
                                        {(() => {
                                            const I = SEV[s].icon;
                                            return <I className="size-4 opacity-70" />;
                                        })()}
                                        {SEV[s].label}: <strong className="tabular-nums">{audit.summary?.counts?.[s] ?? 0}</strong>
                                    </div>
                                ))}
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {audit.pages_crawled} podstron{audit.summary?.limit_reached && ' (limit)'} · średnio{' '}
                                {audit.summary?.avg_ms ? `${num(audit.summary.avg_ms)} ms` : '–'} · {dateTime(audit.finished_at)}
                            </div>
                        </div>

                        {comparison && (
                            <div className="bg-muted/40 rounded-lg border px-3 py-2 text-sm">
                                Poprzedni audyt ({date(comparison.previous_date)}): <strong>{comparison.previous_score}/100</strong>
                                {comparison.fixed.length > 0 && (
                                    <span className="text-[#0a7d0a] dark:text-[#4ccc4c]"> · naprawione: {comparison.fixed.join(', ')}</span>
                                )}
                                {comparison.new.length > 0 && (
                                    <span className="text-[#a4471f] dark:text-[#ec835a]"> · nowe: {comparison.new.join(', ')}</span>
                                )}
                            </div>
                        )}

                        {audit.issues && audit.issues.length > 0 ? (
                            <ul className="grid gap-2">
                                {audit.issues.map((i) => (
                                    <IssueRow key={i.code} issue={i} />
                                ))}
                            </ul>
                        ) : (
                            <p className="text-sm">
                                <CheckCircle2 className="mr-1 inline size-4 text-[#0a7d0a]" /> Nie znaleziono problemów.
                            </p>
                        )}

                        {audit.pages && (
                            <div>
                                <button
                                    type="button"
                                    onClick={() => setShowPages(!showPages)}
                                    className="text-muted-foreground text-sm hover:underline"
                                >
                                    {showPages ? 'Ukryj' : 'Pokaż'} sprawdzone podstrony ({audit.pages.length})
                                </button>
                                {showPages && (
                                    <div className="mt-2 overflow-x-auto">
                                        <table className="w-full text-xs">
                                            <thead>
                                                <tr className="text-muted-foreground border-b text-left">
                                                    <th className="py-1.5 font-medium">Adres</th>
                                                    <th className="py-1.5 font-medium">Kod</th>
                                                    <th className="py-1.5 font-medium">Tytuł</th>
                                                    <th className="py-1.5 text-right font-medium">Czas</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {audit.pages.map((p) => (
                                                    <tr key={p.url} className="border-b last:border-0">
                                                        <td className="max-w-80 truncate py-1 font-mono">
                                                            {p.url.replace(/^https?:\/\/[^/]+/, '') || '/'}
                                                        </td>
                                                        <td
                                                            className={cn(
                                                                'py-1 tabular-nums',
                                                                (p.status >= 400 || p.status === 0) && 'font-semibold text-[#b42f2f]',
                                                            )}
                                                        >
                                                            {p.status || 'błąd'}
                                                            {p.redirects ? ' ↪' : ''}
                                                            {p.noindex ? ' · noindex' : ''}
                                                        </td>
                                                        <td className="max-w-96 truncate py-1">{p.title ?? ''}</td>
                                                        <td className="py-1 text-right tabular-nums">{p.ms ? `${num(p.ms)} ms` : '–'}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {history.length > 1 && (
                    <div className="mt-5 border-t pt-3 text-xs">
                        <span className="text-muted-foreground">Historia: </span>
                        {history.map((h, i) => (
                            <span key={h.id}>
                                {i > 0 && ' · '}
                                {date(h.created_at)} – {h.status === 'done' ? `${h.score}/100` : h.status === 'failed' ? 'błąd' : 'w toku'}
                            </span>
                        ))}
                    </div>
                )}
            </Section>
        </ClientLayout>
    );
}
