import { EmptyState, NativeSelect, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/client-layout';
import { dateTime, num } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type ClientProps } from '@/types/adpilot';
import { router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Link2, RefreshCw, Search } from 'lucide-react';
import { useState } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

interface Period {
    clicks: number;
    impressions: number;
    position: number | null;
}
interface Row {
    key: string;
    clicks: number;
    impressions: number;
    position: number | null;
    prev_clicks: number | null;
    prev_position: number | null;
}

function Delta({ now, prev, inverse = false, suffix = '%' }: { now: number | null; prev: number | null; inverse?: boolean; suffix?: string }) {
    if (now === null || prev === null || prev === 0) return null;
    const diff = suffix === '%' ? Math.round(((now - prev) / prev) * 100) : Math.round((now - prev) * 10) / 10;
    if (diff === 0) return <span className="text-muted-foreground text-xs">bez zmian</span>;
    const good = inverse ? diff < 0 : diff > 0;
    const Icon = diff > 0 ? ArrowUpRight : ArrowDownRight;
    return (
        <span
            className={cn(
                'inline-flex items-center text-xs font-medium',
                good ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400',
            )}
        >
            <Icon className="size-3.5" />
            {diff > 0 ? '+' : ''}
            {diff.toLocaleString('pl-PL')}
            {suffix}
        </span>
    );
}

function Kpi({ label, value, delta }: { label: string; value: string; delta: React.ReactNode }) {
    return (
        <div className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
            <div className="mt-1 flex items-center gap-1 text-xs">
                {delta} <span className="text-muted-foreground">vs poprzednie 28 dni</span>
            </div>
        </div>
    );
}

function RowsTable({ rows, label, link = false }: { rows: Row[]; label: string; link?: boolean }) {
    if (!rows.length) return <p className="text-muted-foreground text-sm">Brak danych.</p>;
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-muted-foreground text-left text-xs">
                    <tr>
                        <th className="py-1.5">{label}</th>
                        <th className="py-1.5 text-right">Kliknięcia</th>
                        <th className="py-1.5 text-right">Wyświetlenia</th>
                        <th className="py-1.5 text-right">Pozycja</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.key} className="border-t">
                            <td className="max-w-80 truncate py-1.5" title={r.key}>
                                {link ? (
                                    <a href={r.key} target="_blank" rel="noreferrer noopener" className="hover:underline">
                                        {r.key.replace(/^https?:\/\/[^/]+/, '') || '/'}
                                    </a>
                                ) : (
                                    r.key
                                )}
                            </td>
                            <td className="py-1.5 text-right whitespace-nowrap tabular-nums">
                                {num(r.clicks)} <Delta now={r.clicks} prev={r.prev_clicks} />
                            </td>
                            <td className="py-1.5 text-right tabular-nums">{num(r.impressions)}</td>
                            <td className="py-1.5 text-right whitespace-nowrap tabular-nums">
                                {r.position !== null ? num(r.position, 1) : '–'} <Delta now={r.position} prev={r.prev_position} inverse suffix="" />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function Seo({
    client,
    google_configured,
    connected,
    site,
    sites,
    last_synced_at,
    last_error,
    summary,
    daily,
    queries,
    pages,
}: {
    client: ClientProps;
    google_configured: boolean;
    connected: boolean;
    site: string | null;
    sites: { id: string; name: string }[];
    last_synced_at: string | null;
    last_error: string | null;
    summary: { until: string; now: Period; prev: Period } | null;
    daily: { date: string; clicks: number; impressions: number; position: number | null }[];
    queries: Row[];
    pages: Row[];
}) {
    const [pick, setPick] = useState(site ?? '');
    const base = `/clients/${client.id}`;
    const ctr = (p: Period) => (p.impressions ? (p.clicks / p.impressions) * 100 : null);

    return (
        <ClientLayout client={client} title="SEO">
            <div className="flex flex-col gap-4">
                {!google_configured ? (
                    <EmptyState title="Połączenie z Google nie jest skonfigurowane">
                        Uzupełnij GOOGLE_CLIENT_ID i GOOGLE_CLIENT_SECRET (Status systemu).
                    </EmptyState>
                ) : !connected || !site ? (
                    <Section
                        title="Google Search Console"
                        description="Kliknięcia i pozycje strony w Google, najlepsze frazy i podstrony – najlepszy dowód efektów SEO dla klienta."
                    >
                        <div className="grid gap-3 text-sm">
                            <p>
                                1. Połącz konto Google, które ma dostęp do Search Console tej strony (to samo połączenie obsługuje Google Ads i GA4).
                            </p>
                            <div>
                                <Button asChild>
                                    <a href={`/oauth/google/${client.id}?back=seo`}>
                                        <Link2 className="size-4" /> {connected ? 'Połącz ponownie z Google' : 'Połącz z Google'}
                                    </a>
                                </Button>
                            </div>
                            {connected && (
                                <>
                                    <p>2. Wybierz witrynę z Search Console:</p>
                                    {sites.length === 0 ? (
                                        <p className="text-muted-foreground">
                                            Brak witryn na tym koncie – dodaj stronę w Search Console albo połącz ponownie (Google musi zapytać o
                                            dostęp do Search Console).
                                        </p>
                                    ) : (
                                        <div className="flex flex-wrap gap-2">
                                            <NativeSelect value={pick} onChange={(e) => setPick(e.target.value)} className="min-w-72">
                                                <option value="">— wybierz —</option>
                                                {sites.map((s) => (
                                                    <option key={s.id} value={s.id}>
                                                        {s.name}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <Button
                                                disabled={!pick}
                                                onClick={() =>
                                                    router.put(`${base}/integrations/google`, { gsc_site: pick }, { preserveScroll: true })
                                                }
                                            >
                                                Zapisz i pobierz dane
                                            </Button>
                                        </div>
                                    )}
                                </>
                            )}
                            {last_error && <p className="text-xs text-[#b42f2f]">{last_error}</p>}
                        </div>
                    </Section>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="text-muted-foreground text-sm">
                                <Search className="mr-1 inline size-4" />
                                {site.replace(/^sc-domain:/, 'Domena: ')} · ostatnie 28 dni
                                {summary ? ` do ${new Date(summary.until).toLocaleDateString('pl-PL')}` : ''}
                                {last_synced_at && ` · pobrano ${dateTime(last_synced_at)}`}
                            </div>
                            <div className="flex gap-2">
                                <NativeSelect
                                    value={site}
                                    onChange={(e) =>
                                        router.put(`${base}/integrations/google`, { gsc_site: e.target.value }, { preserveScroll: true })
                                    }
                                    aria-label="Witryna"
                                >
                                    {sites.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-9"
                                    onClick={() => router.post(`${base}/seo/sync`, {}, { preserveScroll: true })}
                                >
                                    <RefreshCw className="size-4" /> Odśwież
                                </Button>
                            </div>
                        </div>
                        {last_error && <p className="text-xs text-[#b42f2f]">{last_error}</p>}

                        {!summary ? (
                            <EmptyState title="Dane się pobierają – odśwież stronę za minutę" />
                        ) : (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <Kpi
                                        label="Kliknięcia z Google"
                                        value={num(summary.now.clicks)}
                                        delta={<Delta now={summary.now.clicks} prev={summary.prev.clicks} />}
                                    />
                                    <Kpi
                                        label="Wyświetlenia"
                                        value={num(summary.now.impressions)}
                                        delta={<Delta now={summary.now.impressions} prev={summary.prev.impressions} />}
                                    />
                                    <Kpi
                                        label="CTR"
                                        value={ctr(summary.now) !== null ? `${num(ctr(summary.now), 1)}%` : '–'}
                                        delta={<Delta now={ctr(summary.now)} prev={ctr(summary.prev)} />}
                                    />
                                    <Kpi
                                        label="Średnia pozycja"
                                        value={summary.now.position !== null ? num(summary.now.position, 1) : '–'}
                                        delta={<Delta now={summary.now.position} prev={summary.prev.position} inverse suffix="" />}
                                    />
                                </div>

                                <Section title="Kliknięcia i wyświetlenia – 90 dni">
                                    <div className="h-64">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <LineChart data={daily} margin={{ top: 5, right: 8, bottom: 0, left: -10 }}>
                                                <CartesianGrid stroke="var(--chart-grid)" vertical={false} />
                                                <XAxis
                                                    dataKey="date"
                                                    tickFormatter={(d) =>
                                                        new Date(d).toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit' })
                                                    }
                                                    tick={{ fill: 'var(--chart-axis)', fontSize: 11 }}
                                                    tickLine={false}
                                                    minTickGap={24}
                                                />
                                                <YAxis
                                                    yAxisId="c"
                                                    tick={{ fill: 'var(--chart-axis)', fontSize: 11 }}
                                                    tickLine={false}
                                                    axisLine={false}
                                                />
                                                <YAxis
                                                    yAxisId="i"
                                                    orientation="right"
                                                    tick={{ fill: 'var(--chart-axis)', fontSize: 11 }}
                                                    tickLine={false}
                                                    axisLine={false}
                                                />
                                                <Tooltip
                                                    labelFormatter={(d) => new Date(String(d)).toLocaleDateString('pl-PL')}
                                                    formatter={(v, n) => [num(Number(v)), n === 'clicks' ? 'Kliknięcia' : 'Wyświetlenia']}
                                                />
                                                <Line yAxisId="c" type="monotone" dataKey="clicks" stroke="#2a78d6" strokeWidth={2} dot={false} />
                                                <Line
                                                    yAxisId="i"
                                                    type="monotone"
                                                    dataKey="impressions"
                                                    stroke="#8a8984"
                                                    strokeWidth={1.5}
                                                    strokeDasharray="4 3"
                                                    dot={false}
                                                />
                                            </LineChart>
                                        </ResponsiveContainer>
                                    </div>
                                    <div className="text-muted-foreground mt-1 flex gap-4 text-xs">
                                        <span>━ kliknięcia (oś lewa)</span>
                                        <span>┅ wyświetlenia (oś prawa)</span>
                                    </div>
                                </Section>

                                <div className="grid gap-4 xl:grid-cols-2">
                                    <Section title="Najlepsze frazy" description="Zmiana kliknięć i pozycji vs poprzednie 28 dni.">
                                        <RowsTable rows={queries} label="Fraza" />
                                    </Section>
                                    <Section title="Najlepsze podstrony">
                                        <RowsTable rows={pages} label="Podstrona" link />
                                    </Section>
                                </div>
                            </>
                        )}
                    </>
                )}
            </div>
        </ClientLayout>
    );
}
