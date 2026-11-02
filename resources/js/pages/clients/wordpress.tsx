import { Section } from '@/components/adpilot/ui';
import { CopyButton, postJson } from '@/components/adpilot/vault';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/client-layout';
import { date, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type ClientProps } from '@/types/adpilot';
import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    Download,
    FileWarning,
    Info,
    KeyRound,
    Puzzle,
    RefreshCw,
    ShieldCheck,
    Unplug,
    Users,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface Plugin {
    file: string;
    name: string;
    version: string;
    active: boolean;
    update: string | null;
}
interface Theme {
    slug: string;
    name: string;
    version: string;
    active: boolean;
    update: string | null;
}
interface Admin {
    login: string;
    email: string;
    name: string;
    registered: string;
}
interface Report {
    site_url: string;
    site_name: string;
    wp_version: string;
    wp_update: string | null;
    php_version: string;
    db_version: string;
    server: string;
    https: boolean;
    indexing_blocked: boolean;
    wp_debug: boolean;
    wp_debug_display: boolean;
    file_edit_allowed: boolean;
    users_count: number;
    plugins: Plugin[];
    themes: Theme[];
    admins: Admin[];
    failed_logins_24h: number;
    connector: string;
}
interface Scan {
    at: string;
    baseline: boolean;
    files_checked: number;
    core_checksums_unavailable?: boolean;
    core_modified_count: number;
    core_unknown_count: number;
    uploads_php_count: number;
    added_count: number;
    changed_count: number;
    removed_count: number;
}
interface Site {
    id: number;
    status: 'pending' | 'connected' | 'error';
    last_seen_at: string | null;
    last_report_at: string | null;
    last_error: string | null;
    report: Report | null;
    scan: Scan | null;
    php: { status: 'ok' | 'soon' | 'eol' | 'unknown'; eol: string | null };
    plugin_updates: Plugin[];
    theme_updates: Theme[];
}
interface WpEvent {
    id: number;
    type: string;
    severity: 'critical' | 'warning' | 'info';
    title: string;
    details: Record<string, string | string[] | number | null> | null;
    occurred_at: string;
    acknowledged_at: string | null;
}

/** Status zawsze ikona + tekst. */
function Status({ kind, children }: { kind: 'ok' | 'warn' | 'bad' | 'info'; children: React.ReactNode }) {
    const map = {
        ok: { icon: CheckCircle2, cls: 'text-[#0a7d0a] dark:text-[#4ccc4c]' },
        warn: { icon: AlertTriangle, cls: 'text-[#a4471f] dark:text-[#ec835a]' },
        bad: { icon: XCircle, cls: 'text-[#b42f2f] dark:text-[#f08080]' },
        info: { icon: Info, cls: 'text-muted-foreground' },
    }[kind];
    return (
        <span className={cn('inline-flex items-center gap-1 text-xs font-medium', map.cls)}>
            <map.icon className="size-3.5 shrink-0" /> {children}
        </span>
    );
}

function Tile({ label, value, children }: { label: string; value: React.ReactNode; children?: React.ReactNode }) {
    return (
        <div className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{value}</div>
            <div className="mt-1">{children}</div>
        </div>
    );
}

const SEVERITY = {
    critical: { label: 'Krytyczne', icon: AlertOctagon, cls: 'text-[#b42f2f] bg-[#d03b3b]/10 border-[#d03b3b]/40 dark:text-[#f08080]' },
    warning: { label: 'Uwaga', icon: AlertTriangle, cls: 'text-[#a4471f] bg-[#ec835a]/12 border-[#ec835a]/50 dark:text-[#ec835a]' },
    info: { label: 'Info', icon: Info, cls: 'text-muted-foreground bg-muted border-border' },
};

const DETAIL_LABELS: Record<string, string> = {
    user: 'Użytkownik',
    ip: 'IP',
    target: 'Konto',
    target_email: 'E-mail konta',
    how: 'Jak',
    file: 'Plik',
    where: 'Gdzie',
    items: 'Elementy',
    plugin: 'Wtyczka',
    theme: 'Motyw',
    count: 'Liczba',
    agent: 'Przeglądarka',
    last_login_tried: 'Ostatni login próby',
};

function EventRow({ e, hideAcknowledged }: { e: WpEvent; hideAcknowledged: boolean }) {
    const [open, setOpen] = useState(false);
    const [acked, setAcked] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // od razu znika z listy; serwer zapisuje w tle, błąd pokazujemy w wierszu (bez okna z kodem)
    const acknowledge = async () => {
        setAcked(true);
        setError(null);
        try {
            const res = await postJson(`/wp-events/${e.id}/ack`);
            if (!res.ok) throw new Error(res.status === 419 ? 'sesja wygasła – odśwież stronę (F5)' : `błąd serwera ${res.status}`);
            router.reload({ only: ['alertCount', 'client', 'wp_alerts'] });
        } catch (err) {
            setAcked(false);
            setError(`Nie zapisano: ${err instanceof Error ? err.message : 'brak połączenia'}`);
        }
    };
    if (acked && hideAcknowledged) return null;
    const s = SEVERITY[e.severity];
    const d = e.details ?? {};
    const files = (['files', 'changed', 'added', 'removed'] as const).flatMap((k) =>
        Array.isArray(d[k]) ? (d[k] as string[]).map((f) => ({ k, f })) : [],
    );
    const scalars = Object.entries(d).filter(([k, v]) => DETAIL_LABELS[k] && v !== null && v !== '' && !Array.isArray(v));

    return (
        <li className={cn('rounded-lg border px-3 py-2', e.acknowledged_at && 'opacity-60')}>
            <div className="flex flex-wrap items-start gap-2">
                <span className={cn('inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium', s.cls)}>
                    <s.icon className="size-3" /> {s.label}
                </span>
                <button type="button" onClick={() => setOpen(!open)} className="min-w-0 flex-1 text-left">
                    <div className="text-sm font-medium">{e.title}</div>
                    <div className="text-muted-foreground text-xs">
                        {dateTime(e.occurred_at)}
                        {d.user ? ` · ${d.user}` : ''}
                        {d.ip ? ` · IP ${d.ip}` : ''}
                        {e.acknowledged_at && ` · sprawdzone ${dateTime(e.acknowledged_at)}`}
                    </div>
                </button>
                {(scalars.length > 0 || files.length > 0 || d.hint) && (
                    <ChevronDown className={cn('mt-1 size-4 shrink-0 opacity-50 transition', !open && '-rotate-90')} onClick={() => setOpen(!open)} />
                )}
                {!e.acknowledged_at && !acked && e.severity !== 'info' && (
                    <Button variant="outline" size="sm" className="h-7" onClick={acknowledge}>
                        <CheckCircle2 className="size-3.5" /> Sprawdzone
                    </Button>
                )}
            </div>
            {error && <p className="mt-1 text-xs font-medium text-[#b42f2f] dark:text-[#f08080]">{error}</p>}
            {open && (
                <div className="mt-2 space-y-2 pl-1 text-xs">
                    {d.hint && <p className="text-muted-foreground">{String(d.hint)}</p>}
                    {scalars.length > 0 && (
                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                            {scalars.map(([k, v]) => (
                                <div key={k} className="contents">
                                    <dt className="text-muted-foreground">{DETAIL_LABELS[k]}</dt>
                                    <dd className="break-all">{String(v)}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                    {files.length > 0 && (
                        <ul className="bg-muted max-h-64 overflow-auto rounded-md p-2 font-mono">
                            {files.map(({ k, f }) => (
                                <li key={k + f} className="break-all">
                                    <span className="text-muted-foreground">
                                        {{ files: '', changed: 'zmieniony: ', added: 'nowy: ', removed: 'usunięty: ' }[k]}
                                    </span>
                                    {f}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </li>
    );
}

function Setup({ client, siteExists, keyValue }: { client: ClientProps; siteExists: boolean; keyValue: string | null }) {
    const { admin } = usePage<SharedData>().props;
    const base = `/clients/${client.id}`;
    if (!admin) {
        return <Section title="WordPress nie jest połączony">Połączenie strony może skonfigurować administrator panelu.</Section>;
    }
    return (
        <Section
            title="Połącz stronę WordPress"
            description="Wtyczka CC Connector raportuje wersje, aktualizacje i administratorów oraz od razu zgłasza ważne zdarzenia."
        >
            <ol className="grid gap-4 text-sm">
                <li className="flex gap-3">
                    <span className="bg-foreground text-background flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                        1
                    </span>
                    <div className="grid gap-2">
                        <div>
                            Pobierz plik wtyczki – <strong>z wbudowanym kluczem tej strony</strong>.
                        </div>
                        <div>
                            <Button asChild>
                                <a href={`${base}/wordpress/download`}>
                                    <Download className="size-4" /> Pobierz cc-connector.php
                                </a>
                            </Button>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Plik zawiera tajny klucz – nie wysyłaj go mailem ani nie zostawiaj w publicznym miejscu.
                        </p>
                    </div>
                </li>
                <li className="flex gap-3">
                    <span className="bg-foreground text-background flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                        2
                    </span>
                    <div>
                        Wgraj go przez FTP do katalogu <code className="bg-muted rounded px-1">wp-content/mu-plugins/</code> (jeśli katalogu nie ma –
                        utwórz go).{' '}
                        <Link href={`${base}/credentials`} className="underline-offset-2 hover:underline">
                            Dane FTP →
                        </Link>
                        <p className="text-muted-foreground mt-1 text-xs">
                            Jako mu-plugin wtyczka działa od razu i nie da się jej wyłączyć z kokpitu (widać ją w Wtyczki → „Obowiązkowe”).
                        </p>
                    </div>
                </li>
                <li className="flex gap-3">
                    <span className="bg-foreground text-background flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                        3
                    </span>
                    <div>
                        Otwórz dowolną stronę witryny w przeglądarce – w ciągu minuty połączy się sama, a ta zakładka się odświeży.
                        {siteExists && (
                            <div className="text-muted-foreground mt-2 inline-flex items-center gap-2 text-xs">
                                <RefreshCw className="size-3.5 animate-spin" /> Czekam na pierwsze połączenie…
                            </div>
                        )}
                    </div>
                </li>
            </ol>

            <details className="mt-6 border-t pt-4 text-sm">
                <summary className="text-muted-foreground cursor-pointer">Bez FTP: zwykła wtyczka (ZIP) + klucz w ustawieniach</summary>
                <div className="mt-3 grid gap-3">
                    <p className="text-muted-foreground text-xs">
                        Kokpit → Wtyczki → Dodaj nową → Wyślij wtyczkę → wybierz ZIP → Aktywuj. Potem Ustawienia → CC Connector → wklej klucz →
                        „Zapisz i połącz”.
                    </p>
                    <div>
                        <Button variant="outline" size="sm" asChild>
                            <a href={`${base}/wordpress/download?type=zip`}>
                                <Download className="size-4" /> Pobierz cc-connector.zip
                            </a>
                        </Button>
                    </div>
                    {keyValue ? (
                        <div className="flex items-center gap-1">
                            <code className="bg-muted min-w-0 flex-1 truncate rounded px-2 py-1.5 text-xs">{keyValue.slice(0, 18)}••••••••••••</code>
                            <CopyButton value={keyValue} label="Kopiuj klucz" />
                        </div>
                    ) : (
                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.post(`${base}/wordpress/connect`, {}, { preserveScroll: true })}
                            >
                                <KeyRound className="size-4" /> Wygeneruj klucz
                            </Button>
                        </div>
                    )}
                </div>
            </details>
        </Section>
    );
}

export default function WordPress({
    client,
    site,
    connectionKey: keyValue,
    events,
    eventFilter,
    latestConnector,
    alertCount,
}: {
    client: ClientProps;
    site: Site | null;
    connectionKey: string | null;
    events: WpEvent[];
    eventFilter: string;
    latestConnector: string | null;
    alertCount: number;
}) {
    const { admin } = usePage<SharedData>().props;
    const base = `/clients/${client.id}`;
    const r = site?.report;

    // czekanie na pierwsze połączenie: odświeżaj co 10 s
    useEffect(() => {
        if (!site || r) return;
        const t = setInterval(() => router.reload({ only: ['site', 'events', 'client'] }), 10_000);
        return () => clearInterval(t);
    }, [site, r]);

    if (!site || !r) {
        return (
            <ClientLayout client={client} title="WordPress">
                <Setup client={client} siteExists={!!site} keyValue={keyValue} />
                {site?.last_error && <p className="text-sm text-[#b42f2f] dark:text-[#f08080]">Ostatni błąd: {site.last_error}</p>}
            </ClientLayout>
        );
    }

    const scan = site.scan;
    const scanIssues = scan
        ? scan.core_modified_count + scan.core_unknown_count + scan.uploads_php_count + (scan.baseline ? 0 : scan.added_count + scan.changed_count)
        : 0;
    const updates = site.plugin_updates.length + site.theme_updates.length + (r.wp_update ? 1 : 0);
    const outdated = !!latestConnector && !!r.connector && r.connector.localeCompare(latestConnector, undefined, { numeric: true }) < 0;
    const stale = site.last_seen_at && Date.now() - new Date(site.last_seen_at).getTime() > 36 * 3600_000;

    return (
        <ClientLayout client={client} title="WordPress">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="text-sm">
                    {stale ? (
                        <Status kind="bad">Brak kontaktu od {dateTime(site.last_seen_at)}</Status>
                    ) : (
                        <Status kind="ok">Połączono · ostatni kontakt {dateTime(site.last_seen_at)}</Status>
                    )}
                    {site.last_error && (
                        <div className="mt-1">
                            <Status kind="warn">{site.last_error}</Status>
                        </div>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" onClick={() => router.post(`${base}/wordpress/pull`, {}, { preserveScroll: true })}>
                        <RefreshCw className="size-4" /> Sprawdź teraz
                    </Button>
                    {admin && (
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <a href={`${base}/wordpress/download`} title="Plik mu-plugin z kluczem (np. po aktualizacji wtyczki)">
                                    <Download className="size-4" /> Plik wtyczki
                                </a>
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                title="Nowy klucz – stary plik przestanie działać"
                                onClick={() =>
                                    confirm(
                                        'Wygenerować nowy klucz? Obecna wtyczka na stronie przestanie się łączyć, dopóki nie wgrasz nowego pliku.',
                                    ) && router.post(`${base}/wordpress/regenerate`, {}, { preserveScroll: true })
                                }
                            >
                                <KeyRound className="size-4" /> Nowy klucz
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    confirm('Odłączyć stronę? Historia zdarzeń zostanie usunięta.') &&
                                    router.delete(`${base}/wordpress`, { preserveScroll: true })
                                }
                            >
                                <Unplug className="size-4" /> Odłącz
                            </Button>
                        </>
                    )}
                </div>
            </div>

            {outdated && (
                <div className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-2.5 text-sm">
                    <AlertTriangle className="size-4 shrink-0 text-amber-700 dark:text-amber-400" />
                    <span className="flex-1">
                        Na stronie działa CC Connector <strong>{r.connector}</strong>, dostępna jest wersja <strong>{latestConnector}</strong>.
                        Pobierz plik i podmień go w wp-content/mu-plugins/ (przez FTP).
                    </span>
                    {admin && (
                        <Button size="sm" variant="outline" asChild>
                            <a href={`${base}/wordpress/download`}>
                                <Download className="size-4" /> Pobierz nową wersję
                            </a>
                        </Button>
                    )}
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <Tile label="WordPress" value={r.wp_version}>
                    {r.wp_update ? <Status kind="warn">dostępna wersja {r.wp_update}</Status> : <Status kind="ok">aktualny</Status>}
                </Tile>
                <Tile label="PHP" value={r.php_version.split('-')[0]}>
                    {site.php.status === 'eol' ? (
                        <Status kind="bad">bez wsparcia bezpieczeństwa{site.php.eol ? ` od ${date(site.php.eol)}` : ''}</Status>
                    ) : site.php.status === 'soon' ? (
                        <Status kind="warn">wsparcie do {date(site.php.eol)}</Status>
                    ) : site.php.status === 'ok' ? (
                        <Status kind="ok">wspierana{site.php.eol ? ` do ${date(site.php.eol)}` : ''}</Status>
                    ) : (
                        <Status kind="info">nieznany status</Status>
                    )}
                </Tile>
                <Tile label="Wtyczki" value={r.plugins.length}>
                    {site.plugin_updates.length ? (
                        <Status kind="warn">{site.plugin_updates.length} do aktualizacji</Status>
                    ) : (
                        <Status kind="ok">wszystkie aktualne</Status>
                    )}
                    <div className="text-muted-foreground text-xs">aktywne: {r.plugins.filter((p) => p.active).length}</div>
                </Tile>
                <Tile label="Administratorzy" value={r.admins.length}>
                    <span className="text-muted-foreground text-xs">kont łącznie: {r.users_count}</span>
                </Tile>
                <Tile label="Skan plików" value={scan ? (scanIssues ? scanIssues : '✓') : '–'}>
                    {!scan ? (
                        <Status kind="info">jeszcze nie było</Status>
                    ) : scanIssues ? (
                        <Status kind="bad">podejrzane pliki – zobacz zdarzenia</Status>
                    ) : (
                        <Status kind="ok">{scan.baseline ? 'zapisano wzorzec' : 'bez zmian'}</Status>
                    )}
                    {scan && (
                        <div className="text-muted-foreground text-xs">
                            {dateTime(scan.at)} · {scan.files_checked} plików
                        </div>
                    )}
                </Tile>
            </div>

            <div className="grid gap-4 xl:grid-cols-[1fr_380px]">
                <div className="grid content-start gap-4">
                    <Section
                        title={`Zdarzenia${alertCount ? ` – do sprawdzenia: ${alertCount}` : ''}`}
                        description="Krytyczne przychodzą też od razu e-mailem."
                        actions={
                            <div className="flex flex-wrap gap-2">
                                <div className="flex rounded-md border p-0.5">
                                    {[
                                        ['alerts', 'Do sprawdzenia'],
                                        ['all', 'Wszystkie'],
                                    ].map(([k, label]) => (
                                        <Button
                                            key={k}
                                            variant={eventFilter === k ? 'secondary' : 'ghost'}
                                            size="sm"
                                            className="h-7"
                                            onClick={() =>
                                                router.get(`${base}/wordpress`, { events: k }, { preserveScroll: true, preserveState: true })
                                            }
                                        >
                                            {label}
                                        </Button>
                                    ))}
                                </div>
                                {alertCount > 0 && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-8"
                                        onClick={async () => {
                                            const res = await postJson(`${base}/wordpress/ack-all`);
                                            if (res.ok) router.reload();
                                            else
                                                alert(
                                                    res.status === 419
                                                        ? 'Sesja wygasła – odśwież stronę (F5).'
                                                        : `Nie zapisano (błąd serwera ${res.status}).`,
                                                );
                                        }}
                                    >
                                        <CheckCircle2 className="size-4" /> Wszystkie sprawdzone
                                    </Button>
                                )}
                            </div>
                        }
                    >
                        {events.length === 0 ? (
                            <p className="text-muted-foreground text-sm">{eventFilter === 'alerts' ? 'Nic do sprawdzenia. ✓' : 'Brak zdarzeń.'}</p>
                        ) : (
                            <ul className="grid gap-2">
                                {events.map((e) => (
                                    <EventRow key={e.id} e={e} hideAcknowledged={eventFilter === 'alerts'} />
                                ))}
                            </ul>
                        )}
                    </Section>

                    <Section title={`Do aktualizacji (${updates})`}>
                        {updates === 0 ? (
                            <p className="text-sm">
                                <Status kind="ok">WordPress, wtyczki i motywy są aktualne.</Status>
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <tbody>
                                    {r.wp_update && (
                                        <tr className="border-b">
                                            <td className="py-1.5 font-medium">WordPress</td>
                                            <td className="py-1.5 text-right tabular-nums">
                                                {r.wp_version} → <strong>{r.wp_update}</strong>
                                            </td>
                                        </tr>
                                    )}
                                    {site.plugin_updates.map((p) => (
                                        <tr key={p.file} className="border-b last:border-0">
                                            <td className="py-1.5">
                                                <Puzzle className="text-muted-foreground mr-1.5 inline size-3.5" />
                                                {p.name} {!p.active && <span className="text-muted-foreground text-xs">(nieaktywna)</span>}
                                            </td>
                                            <td className="py-1.5 text-right tabular-nums">
                                                {p.version} → <strong>{p.update}</strong>
                                            </td>
                                        </tr>
                                    ))}
                                    {site.theme_updates.map((t) => (
                                        <tr key={t.slug} className="border-b last:border-0">
                                            <td className="py-1.5">Motyw: {t.name}</td>
                                            <td className="py-1.5 text-right tabular-nums">
                                                {t.version} → <strong>{t.update}</strong>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </Section>

                    <Section title={`Wszystkie wtyczki (${r.plugins.length})`}>
                        <details>
                            <summary className="text-muted-foreground cursor-pointer text-sm">Pokaż listę</summary>
                            <table className="mt-2 w-full text-sm">
                                <tbody>
                                    {r.plugins.map((p) => (
                                        <tr key={p.file} className="border-b last:border-0">
                                            <td className="py-1.5">{p.name}</td>
                                            <td className="text-muted-foreground py-1.5 text-xs">{p.active ? 'aktywna' : 'nieaktywna'}</td>
                                            <td className="py-1.5 text-right tabular-nums">{p.version}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </details>
                    </Section>
                </div>

                <div className="grid content-start gap-4">
                    <Section title="Bezpieczeństwo">
                        <ul className="grid gap-2">
                            <li>{r.https ? <Status kind="ok">HTTPS włączone</Status> : <Status kind="bad">Strona bez HTTPS</Status>}</li>
                            <li>
                                {r.wp_debug_display ? (
                                    <Status kind="bad">Błędy PHP wyświetlane odwiedzającym (WP_DEBUG)</Status>
                                ) : r.wp_debug ? (
                                    <Status kind="warn">WP_DEBUG włączony (błędy do logu)</Status>
                                ) : (
                                    <Status kind="ok">Tryb debugowania wyłączony</Status>
                                )}
                            </li>
                            <li>
                                {r.file_edit_allowed ? (
                                    <Status kind="warn">Edycja plików z kokpitu włączona – zalecane DISALLOW_FILE_EDIT</Status>
                                ) : (
                                    <Status kind="ok">Edycja plików z kokpitu zablokowana</Status>
                                )}
                            </li>
                            <li>
                                {r.failed_logins_24h >= 50 ? (
                                    <Status kind="warn">{r.failed_logins_24h} nieudanych logowań w 24 h</Status>
                                ) : (
                                    <Status kind="info">Nieudane logowania (24 h): {r.failed_logins_24h}</Status>
                                )}
                            </li>
                            {r.indexing_blocked && (
                                <li>
                                    <Status kind="warn">Strona ukryta przed Google (Ustawienia → Czytanie)</Status>
                                </li>
                            )}
                            {scan?.core_checksums_unavailable && (
                                <li>
                                    <Status kind="info">Brak sum kontrolnych dla tej wersji WP – rdzeń nie został sprawdzony</Status>
                                </li>
                            )}
                        </ul>
                    </Section>

                    <Section title={`Administratorzy (${r.admins.length})`} actions={<Users className="text-muted-foreground size-4" />}>
                        <ul className="grid gap-2 text-sm">
                            {r.admins.map((a) => (
                                <li key={a.login} className="flex flex-col">
                                    <span className="font-medium">
                                        {a.login}{' '}
                                        {a.name && a.name !== a.login && <span className="text-muted-foreground font-normal">({a.name})</span>}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {a.email}
                                        {a.registered && ` · od ${date(a.registered)}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Section>

                    <Section title="Serwer">
                        <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs">
                            <dt className="text-muted-foreground">Baza danych</dt>
                            <dd>{r.db_version}</dd>
                            <dt className="text-muted-foreground">Serwer WWW</dt>
                            <dd className="break-all">{r.server || '–'}</dd>
                            <dt className="text-muted-foreground">CC Connector</dt>
                            <dd>{r.connector}</dd>
                            <dt className="text-muted-foreground">Ostatni raport</dt>
                            <dd>{dateTime(site.last_report_at)}</dd>
                        </dl>
                        <p className="text-muted-foreground mt-3 flex items-start gap-1.5 text-xs">
                            <ShieldCheck className="mt-0.5 size-3.5 shrink-0" /> Raport i skan plików codziennie o 6:40 + zdarzenia na bieżąco.
                        </p>
                        {scan && scan.baseline && (
                            <p className="text-muted-foreground mt-1 flex items-start gap-1.5 text-xs">
                                <FileWarning className="mt-0.5 size-3.5 shrink-0" /> Pierwszy skan zapisuje wzorzec plików – zmiany będą wykrywane od
                                kolejnego.
                            </p>
                        )}
                    </Section>
                </div>
            </div>
        </ClientLayout>
    );
}
