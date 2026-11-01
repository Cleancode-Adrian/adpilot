import { DailyTable, LeadsChart, RevenueChart, SpendChart } from '@/components/adpilot/charts';
import { EmptyState, KpiTile, NativeSelect, PlatformDot, Section } from '@/components/adpilot/ui';
import ClientLayout from '@/layouts/client-layout';
import { money, num, pct, platformName, roas } from '@/lib/format';
import { type Channel, type ClientProps, type DailyRow, type Kpis, type Stat } from '@/types/adpilot';
import { Link, router } from '@inertiajs/react';
import { ThumbsDown, ThumbsUp } from 'lucide-react';
import { useMemo, useState } from 'react';

interface SearchTermRow {
    id: number;
    term: string;
    clicks: number;
    cost: number;
    conversions: number;
    conversion_value: number;
    campaign: { id: number; name: string } | null;
}

type Props = {
    client: ClientProps;
    days: number;
    kpis: Kpis;
    daily: DailyRow[];
    campaigns: Stat[];
    ads: Stat[];
    channels: Channel[];
    searchTerms: SearchTermRow[];
};

/** Ocena reklamy względem jej kampanii – tekst + ikona, nie sam kolor. */
function AdVerdict({ ad, campaign, leads = false }: { ad: Stat; campaign?: Stat; leads?: boolean }) {
    if (!campaign || ad.cost < 30) return <span className="text-muted-foreground text-xs">za mało danych</span>;
    if (ad.conversions < 0.5 && ad.cost >= Math.max(100, (campaign.cpa ?? 0) * 1.5))
        return (
            <span className="inline-flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-400">
                <ThumbsDown className="size-3.5" /> {leads ? 'brak leadów' : 'brak sprzedaży'}
            </span>
        );
    const ratio = leads ? (ad.cpa && campaign.cpa ? campaign.cpa / ad.cpa : null) : ad.roas && campaign.roas ? ad.roas / campaign.roas : null;
    if (ratio !== null) {
        if (ratio >= 1.3)
            return (
                <span className="inline-flex items-center gap-1 text-xs font-medium text-green-700 dark:text-green-400">
                    <ThumbsUp className="size-3.5" /> lepsza od kampanii
                </span>
            );
        if (ratio <= 0.6)
            return (
                <span className="inline-flex items-center gap-1 text-xs font-medium text-red-700 dark:text-red-400">
                    <ThumbsDown className="size-3.5" /> słabsza od kampanii
                </span>
            );
    }
    return <span className="text-muted-foreground text-xs">w normie</span>;
}

function StatTable({ rows, currency, kind, campaigns, leads = false }: { rows: Stat[]; currency: string; kind: 'campaign' | 'ad'; campaigns?: Map<number, Stat>; leads?: boolean }) {
    const [sort, setSort] = useState<keyof Stat>('cost');
    const sorted = useMemo(() => [...rows].sort((a, b) => (Number(b[sort]) || 0) - (Number(a[sort]) || 0)), [rows, sort]);
    const th = (key: keyof Stat, label: string) => (
        <th className="px-3 py-2 text-right font-medium">
            <button onClick={() => setSort(key)} className={sort === key ? 'text-foreground underline' : 'hover:text-foreground'}>
                {label}
            </button>
        </th>
    );
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-muted-foreground border-b text-left text-xs">
                        <th className="px-3 py-2 font-medium">{kind === 'campaign' ? 'Kampania' : 'Reklama'}</th>
                        {th('cost', 'Koszt')}
                        {th('clicks', 'Kliknięcia')}
                        {th('ctr', 'CTR')}
                        {th('cpc', 'CPC')}
                        {th('conversions', leads ? 'Leady' : 'Konwersje')}
                        {th('cpa', leads ? 'Koszt leada' : 'CPA')}
                        {leads ? th('cvr', 'Konw. %') : th('conversion_value', 'Przychód')}
                        {!leads && th('roas', 'ROAS')}
                        {kind === 'ad' ? <th className="px-3 py-2 font-medium">Ocena</th> : th('budget_lost_is', 'Utrata IS (budżet)')}
                    </tr>
                </thead>
                <tbody>
                    {sorted.map((r) => (
                        <tr key={r.id} className="hover:bg-muted/40 border-b last:border-0">
                            <td className="max-w-xs px-3 py-2">
                                <div className="flex items-center gap-2">
                                    <PlatformDot platform={r.platform} />
                                    <span className="truncate font-medium" title={r.name}>
                                        {r.name}
                                    </span>
                                </div>
                                <div className="text-muted-foreground pl-4.5 text-xs">
                                    {kind === 'ad' ? r.campaign_name : `${platformName[r.platform]} · ${r.channel_type ?? ''}`}
                                    {r.frequency ? ` · częstotliwość ${num(r.frequency, 1)}` : ''}
                                </div>
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">{money(r.cost, currency)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{num(r.clicks)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{pct(r.ctr, 2)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{money(r.cpc, currency, 2)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{num(r.conversions, 1)}</td>
                            <td className="px-3 py-2 text-right tabular-nums">{money(r.cpa, currency, 2)}</td>
                            {leads ? (
                                <td className="px-3 py-2 text-right tabular-nums">{pct(r.cvr, 2)}</td>
                            ) : (
                                <>
                                    <td className="px-3 py-2 text-right tabular-nums">{money(r.conversion_value, currency)}</td>
                                    <td className="px-3 py-2 text-right font-medium tabular-nums">{roas(r.roas)}</td>
                                </>
                            )}
                            <td className="px-3 py-2 text-right tabular-nums">
                                {kind === 'ad' ? (
                                    <AdVerdict ad={r} campaign={campaigns?.get(Number(r.campaign_id))} leads={leads} />
                                ) : r.budget_lost_is !== null && r.budget_lost_is !== undefined ? (
                                    pct(r.budget_lost_is * 100)
                                ) : (
                                    '–'
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

export default function ClientShow({ client, days, kpis, daily, campaigns, ads, channels, searchTerms }: Props) {
    const [platform, setPlatform] = useState<'all' | 'google_ads' | 'meta_ads'>('all');
    const cur = client.currency;
    const a = kpis.ads;
    const an = kpis.analytics;
    const filt = <T extends { platform: string }>(rows: T[]) => (platform === 'all' ? rows : rows.filter((r) => r.platform === platform));
    const campaignMap = useMemo(() => new Map(campaigns.map((c) => [c.id, c])), [campaigns]);
    const noData = campaigns.length === 0 && !kpis.has_analytics;
    const isLeads = kpis.goal === 'leads';

    return (
        <ClientLayout client={client} title="Przegląd">
            {/* Filtry w jednym rzędzie nad wykresami */}
            <div className="flex flex-wrap items-center gap-2">
                <NativeSelect value={days} onChange={(e) => router.get(`/clients/${client.id}`, { days: e.target.value }, { preserveScroll: true })} aria-label="Okres">
                    {[7, 14, 30, 60, 90].map((d) => (
                        <option key={d} value={d}>
                            Ostatnie {d} dni
                        </option>
                    ))}
                </NativeSelect>
                <NativeSelect value={platform} onChange={(e) => setPlatform(e.target.value as typeof platform)} aria-label="Platforma">
                    <option value="all">Wszystkie platformy</option>
                    <option value="google_ads">Google Ads</option>
                    <option value="meta_ads">Meta Ads</option>
                </NativeSelect>
                <span className="text-muted-foreground text-xs">
                    {new Date(kpis.period.from).toLocaleDateString('pl-PL')} – {new Date(kpis.period.to).toLocaleDateString('pl-PL')}, porównanie z poprzednimi {days} dniami
                </span>
            </div>

            {noData ? (
                <EmptyState title="Brak danych">
                    Podłącz konta w zakładce{' '}
                    <Link className="underline" href={`/clients/${client.id}/integrations`}>
                        Integracje
                    </Link>{' '}
                    i uruchom synchronizację.
                </EmptyState>
            ) : (
                <>
                    {isLeads ? (
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <KpiTile label="Wydatki" value={money(a.cost.value, cur)} delta={a.cost.delta} inverse />
                            <KpiTile label="Leady z reklam" value={num(a.conversions.value, 0)} delta={a.conversions.delta} hint="Formularze, rejestracje, telefony zliczone przez Google Ads i Meta" />
                            <KpiTile label="Koszt leada (CPL)" value={money(a.cpa.value, cur, 2)} delta={a.cpa.delta} inverse />
                            <KpiTile label="Konwersja kliknięć" value={pct(a.clicks.value ? ((a.conversions.value ?? 0) / a.clicks.value) * 100 : 0, 2)} hint="Leady / kliknięcia" />
                            <KpiTile label="Wejścia na stronę" value={num(an.sessions.value)} delta={an.sessions.delta} hint="Sesje GA4 ze wszystkich źródeł" />
                            <KpiTile label="Formularze i rejestracje (GA4)" value={num(an.leads.value)} delta={an.leads.delta} hint={client.lead_event_names.join(', ')} />
                            <KpiTile label="Kliknięcia" value={num(a.clicks.value)} delta={a.clicks.delta} />
                            <KpiTile label="CPC" value={money(a.cpc.value, cur, 2)} delta={a.cpc.delta} inverse />
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <KpiTile label="Wydatki" value={money(a.cost.value, cur)} delta={a.cost.delta} inverse />
                            <KpiTile label="Przychód z reklam" value={money(a.conversion_value.value, cur)} delta={a.conversion_value.delta} />
                            <KpiTile label="ROAS" value={roas(a.roas.value)} delta={a.roas.delta} hint="Przychód z reklam / koszt" />
                            <KpiTile label="Koszt konwersji (CPA)" value={money(a.cpa.value, cur, 2)} delta={a.cpa.delta} inverse />
                            <KpiTile label={kpis.goal === 'mixed' ? 'Konwersje (zakupy + leady)' : 'Konwersje'} value={num(a.conversions.value, 1)} delta={a.conversions.delta} />
                            <KpiTile label="Kliknięcia" value={num(a.clicks.value)} delta={a.clicks.delta} />
                            <KpiTile label="CTR" value={pct(a.ctr.value, 2)} delta={a.ctr.delta} />
                            <KpiTile label="CPC" value={money(a.cpc.value, cur, 2)} delta={a.cpc.delta} inverse />
                        </div>
                    )}

                    <div className="grid gap-4 xl:grid-cols-2">
                        <Section title="Wydatki dzienne" description="Google Ads i Meta Ads">
                            <SpendChart data={daily} currency={cur} />
                        </Section>
                        {isLeads ? (
                            <Section title="Leady dziennie" description="Zliczone przez platformy reklamowe vs zdarzenia w GA4">
                                <LeadsChart data={daily} hasAnalytics={kpis.has_analytics} />
                            </Section>
                        ) : (
                            <Section title="Przychód dzienny" description="Przypisany reklamom vs cały sklep (GA4)">
                                <RevenueChart data={daily} currency={cur} hasAnalytics={kpis.has_analytics} />
                            </Section>
                        )}
                    </div>
                    <DailyTable data={daily} currency={cur} />

                    <Section title="Kampanie" description="Kliknij nagłówek kolumny, aby posortować">
                        {filt(campaigns).length ? <StatTable rows={filt(campaigns)} currency={cur} kind="campaign" leads={isLeads} /> : <EmptyState title="Brak kampanii w tym okresie" />}
                    </Section>

                    <Section title="Reklamy – co działa, a co nie" description={isLeads ? 'Ocena względem średniego kosztu leada w kampanii, w której działa reklama' : 'Ocena względem średniego ROAS kampanii, w której działa reklama'}>
                        {filt(ads).length ? <StatTable rows={filt(ads)} currency={cur} kind="ad" campaigns={campaignMap} leads={isLeads} /> : <EmptyState title="Brak danych o reklamach" />}
                    </Section>

                    <div className="grid gap-4 xl:grid-cols-2">
                        <Section title={isLeads ? 'Ruch na stronie i kontakty (GA4)' : 'Ruch w sklepie (GA4)'} description="Wszystkie źródła ruchu">
                            {kpis.has_analytics ? (
                                <>
                                    <div className="mb-4 grid grid-cols-2 gap-3">
                                        <KpiTile label="Wejścia (sesje)" value={num(an.sessions.value)} delta={an.sessions.delta} />
                                        <KpiTile label="Użytkownicy" value={num(an.users.value)} delta={an.users.delta} />
                                        {kpis.goal !== 'sales' && (
                                            <>
                                                <KpiTile label="Kontakty (GA4)" value={num(an.leads.value)} delta={an.leads.delta} />
                                                <KpiTile label="Konwersja strony" value={pct(an.lead_cvr.value, 2)} delta={an.lead_cvr.delta} hint="Kontakty / sesje" />
                                            </>
                                        )}
                                        {kpis.goal !== 'leads' && (
                                            <>
                                                <KpiTile label="Transakcje" value={num(an.transactions.value)} delta={an.transactions.delta} />
                                                <KpiTile label="Przychód sklepu" value={money(an.revenue.value, cur)} delta={an.revenue.delta} />
                                            </>
                                        )}
                                    </div>
                                    {kpis.goal !== 'sales' && kpis.events.length > 0 && (
                                        <div className="mb-4">
                                            <div className="mb-1 text-xs font-medium">Rodzaje kontaktów</div>
                                            <div className="flex flex-wrap gap-2">
                                                {kpis.events.map((e) => (
                                                    <span key={e.event_name} className="bg-muted rounded-md px-2 py-1 text-xs tabular-nums">
                                                        <span className="font-mono">{e.event_name}</span>: <strong>{num(e.count)}</strong>
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="text-muted-foreground border-b text-left text-xs">
                                                <th className="py-2 font-medium">Kanał</th>
                                                <th className="py-2 text-right font-medium">Sesje</th>
                                                <th className="py-2 text-right font-medium">Zaang.</th>
                                                {isLeads ? (
                                                    <>
                                                        <th className="py-2 text-right font-medium" title="Zdarzenia kluczowe GA4">Konwersje</th>
                                                        <th className="py-2 text-right font-medium">Konw. %</th>
                                                    </>
                                                ) : (
                                                    <>
                                                        <th className="py-2 text-right font-medium">Transakcje</th>
                                                        <th className="py-2 text-right font-medium">Przychód</th>
                                                        <th className="py-2 text-right font-medium">Konw.</th>
                                                    </>
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {channels.map((c) => (
                                                <tr key={c.channel} className="border-b last:border-0 tabular-nums">
                                                    <td className="py-1.5">{c.channel}</td>
                                                    <td className="py-1.5 text-right">{num(c.sessions)}</td>
                                                    <td className="py-1.5 text-right">{pct(c.engagement_rate)}</td>
                                                    {isLeads ? (
                                                        <>
                                                            <td className="py-1.5 text-right">{num(c.key_events)}</td>
                                                            <td className="py-1.5 text-right">{pct(c.key_cvr, 2)}</td>
                                                        </>
                                                    ) : (
                                                        <>
                                                            <td className="py-1.5 text-right">{num(c.transactions)}</td>
                                                            <td className="py-1.5 text-right">{money(c.revenue, cur)}</td>
                                                            <td className="py-1.5 text-right">{pct(c.cvr, 2)}</td>
                                                        </>
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </>
                            ) : (
                                <EmptyState title="GA4 nie jest podłączone">Wybierz usługę GA4 w zakładce Integracje.</EmptyState>
                            )}
                        </Section>

                        <Section title="Wyszukiwane hasła (Google Ads, 30 dni)" description="Najdroższe hasła – zielone przynoszą sprzedaż, bez konwersji to kandydaci na wykluczenia">
                            {searchTerms.length ? (
                                <div className="max-h-[28rem] overflow-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="text-muted-foreground border-b text-left text-xs">
                                                <th className="py-2 font-medium">Hasło</th>
                                                <th className="py-2 text-right font-medium">Kliknięcia</th>
                                                <th className="py-2 text-right font-medium">Koszt</th>
                                                <th className="py-2 text-right font-medium">Konw.</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {searchTerms.map((t) => (
                                                <tr key={t.id} className="border-b last:border-0 tabular-nums">
                                                    <td className="py-1.5">
                                                        <div className="font-mono text-xs">{t.term}</div>
                                                        <div className="text-muted-foreground text-xs">{t.campaign?.name}</div>
                                                    </td>
                                                    <td className="py-1.5 text-right">{num(t.clicks)}</td>
                                                    <td className="py-1.5 text-right">{money(t.cost, cur)}</td>
                                                    <td className="py-1.5 text-right">
                                                        {t.conversions > 0 ? (
                                                            <span className="text-green-700 dark:text-green-400">{num(t.conversions, 1)}</span>
                                                        ) : (
                                                            <span className="text-red-700 dark:text-red-400">0 ✕</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <EmptyState title="Brak danych o hasłach" />
                            )}
                        </Section>
                    </div>
                </>
            )}
        </ClientLayout>
    );
}
