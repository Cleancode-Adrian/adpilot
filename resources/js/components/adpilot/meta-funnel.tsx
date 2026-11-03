import { money, num } from '@/lib/format';
import { cn } from '@/lib/utils';
import { AlertTriangle, CheckCircle2, ChevronRight, Info } from 'lucide-react';

export interface FunnelStep {
    key: string;
    label: string;
    value: number;
    rate: number | null;
    norm: number | null;
    hint: string | null;
}
export interface Funnel {
    cost: number;
    steps: FunnelStep[];
    ga_sources: { source_medium: string; sessions: number }[];
    has_ga: boolean;
}
export interface Placement {
    platform: string;
    position: string;
    name: string;
    impressions: number;
    link_clicks: number;
    landing_page_views: number;
    cost: number;
    conversions: number;
    link_ctr: number;
    lpv_rate: number | null;
    cost_per_lpv: number | null;
    suspicious: boolean;
}

/**
 * Lejek Meta: każdy krok z liczbą i odsetkiem przejścia z poprzedniego.
 * Odsetek poniżej normy = ikona ostrzeżenia + tekst (nie sam kolor).
 */
export function MetaFunnel({ funnel, currency }: { funnel: Funnel; currency: string }) {
    return (
        <div className="grid gap-3">
            <ol className="grid gap-2 sm:grid-cols-2 lg:grid-cols-[repeat(5,minmax(0,1fr))]">
                {funnel.steps.map((s, i) => {
                    const low = s.norm !== null && s.rate !== null && s.rate < s.norm;
                    return (
                        <li key={s.key} className="relative flex items-stretch gap-2">
                            {i > 0 && (
                                <ChevronRight className="text-muted-foreground absolute top-1/2 -left-3 hidden size-4 -translate-y-1/2 lg:block" />
                            )}
                            <div className={cn('bg-card flex-1 rounded-xl border p-3', low && 'border-[#ec835a]/60')} title={s.hint ?? undefined}>
                                <div className="text-muted-foreground text-xs">{s.label}</div>
                                <div className="mt-0.5 text-xl font-semibold tabular-nums">{num(s.value, s.key === 'results' ? 1 : 0)}</div>
                                {s.rate !== null && (
                                    <div
                                        className={cn(
                                            'mt-1 inline-flex items-center gap-1 text-xs font-medium tabular-nums',
                                            low ? 'text-[#a4471f] dark:text-[#ec835a]' : 'text-muted-foreground',
                                        )}
                                    >
                                        {low ? (
                                            <AlertTriangle className="size-3.5" />
                                        ) : s.norm !== null ? (
                                            <CheckCircle2 className="size-3.5" />
                                        ) : null}
                                        {num(s.rate, 1)}% z poprzedniego
                                        {low && s.norm !== null && <span className="font-normal">(norma {s.norm}%+)</span>}
                                    </div>
                                )}
                            </div>
                        </li>
                    );
                })}
            </ol>
            {funnel.steps.some((s) => s.norm !== null && s.rate !== null && s.rate < s.norm) && (
                <ul className="grid gap-1 text-xs">
                    {funnel.steps
                        .filter((s) => s.norm !== null && s.rate !== null && s.rate < s.norm && s.hint)
                        .map((s) => (
                            <li key={s.key} className="text-muted-foreground flex items-start gap-1.5">
                                <Info className="mt-0.5 size-3.5 shrink-0" />
                                <span>
                                    <strong className="text-foreground">{s.label}:</strong> {s.hint}
                                </span>
                            </li>
                        ))}
                </ul>
            )}
            <p className="text-muted-foreground text-xs">
                Tylko kampanie prowadzące na stronę (bez formularzy Meta i Messengera) · wydatki {money(funnel.cost, currency)}
                {funnel.has_ga
                    ? funnel.ga_sources.length > 0 && (
                          <> · źródła w GA4: {funnel.ga_sources.map((s) => `${s.source_medium} (${s.sessions})`).join(', ')}</>
                      )
                    : ' · podłącz GA4, aby zobaczyć, ile wejść GA4 faktycznie rejestruje'}
            </p>
        </div>
    );
}

/** Umiejscowienia: gdzie są kliknięcia i czy kończą się wejściem na stronę. */
export function PlacementTable({ rows, currency }: { rows: Placement[]; currency: string }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="text-muted-foreground border-b text-left text-xs">
                        <th className="py-2 font-medium">Umiejscowienie</th>
                        <th className="py-2 text-right font-medium">Wyświetlenia</th>
                        <th className="py-2 text-right font-medium">Kliknięcia w link</th>
                        <th className="py-2 text-right font-medium">CTR linku</th>
                        <th className="py-2 text-right font-medium">Wejścia na stronę</th>
                        <th className="py-2 text-right font-medium">% kliknięć → wejść</th>
                        <th className="py-2 text-right font-medium">Koszt</th>
                        <th className="py-2 text-right font-medium">Koszt wejścia</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((p) => (
                        <tr key={p.platform + p.position} className={cn('border-b last:border-0', p.suspicious && 'bg-[#d03b3b]/5')}>
                            <td className="py-1.5">
                                <div className="flex items-center gap-1.5">
                                    {p.suspicious && <AlertTriangle className="size-3.5 shrink-0 text-[#b42f2f] dark:text-[#f08080]" />}
                                    <span className={cn(p.suspicious && 'font-medium')}>{p.name}</span>
                                </div>
                                {p.suspicious && (
                                    <div className="text-xs text-[#b42f2f] dark:text-[#f08080]">
                                        kliknięcia bez wejść na stronę – rozważ wykluczenie
                                    </div>
                                )}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">{num(p.impressions)}</td>
                            <td className="py-1.5 text-right tabular-nums">{num(p.link_clicks)}</td>
                            <td className="py-1.5 text-right tabular-nums">{num(p.link_ctr, 2)}%</td>
                            <td className="py-1.5 text-right tabular-nums">{num(p.landing_page_views)}</td>
                            <td
                                className={cn(
                                    'py-1.5 text-right tabular-nums',
                                    p.lpv_rate !== null && p.lpv_rate < 50 && 'font-medium text-[#a4471f] dark:text-[#ec835a]',
                                )}
                            >
                                {p.lpv_rate === null ? '–' : `${p.lpv_rate}%`}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">{money(p.cost, currency, 2)}</td>
                            <td className="py-1.5 text-right tabular-nums">{p.cost_per_lpv === null ? '–' : money(p.cost_per_lpv, currency, 2)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
