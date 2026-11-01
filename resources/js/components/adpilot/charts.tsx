import { money } from '@/lib/format';
import { type DailyRow } from '@/types/adpilot';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type TipEntry = { dataKey?: string | number; value?: number | string; color?: string };
type TipProps = { active?: boolean; payload?: TipEntry[]; label?: string | number; currency: string; names: Record<string, string> };

const axisProps = {
    tick: { fill: 'var(--chart-axis)', fontSize: 11 },
    tickLine: false,
    axisLine: { stroke: 'var(--chart-grid)' },
};

const shortDate = (d: string) => new Date(d).toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit' });

function Legend({ items }: { items: { label: string; color: string; dashed?: boolean }[] }) {
    return (
        <div className="text-muted-foreground mb-2 flex flex-wrap gap-4 text-xs">
            {items.map((i) => (
                <span key={i.label} className="inline-flex items-center gap-1.5">
                    {i.dashed ? (
                        <span className="inline-block w-4 border-t-2 border-dashed" style={{ borderColor: i.color }} />
                    ) : (
                        <span className="inline-block size-2.5 rounded-[3px]" style={{ background: i.color }} />
                    )}
                    {i.label}
                </span>
            ))}
        </div>
    );
}

function ChartTooltip({ active, payload, label, currency, names }: TipProps) {
    if (!active || !payload?.length) return null;
    const total = payload.length > 1 && payload.every((p) => String(p.dataKey).endsWith('_cost')) ? payload.reduce((s, p) => s + (Number(p.value) || 0), 0) : null;
    return (
        <div className="bg-popover text-popover-foreground rounded-md border px-3 py-2 text-xs shadow-md">
            <div className="mb-1 font-medium">{new Date(String(label)).toLocaleDateString('pl-PL', { weekday: 'short', day: 'numeric', month: 'long' })}</div>
            {payload.map((p) => (
                <div key={String(p.dataKey)} className="flex items-center justify-between gap-4">
                    <span className="inline-flex items-center gap-1.5">
                        <span className="inline-block size-2 rounded-[2px]" style={{ background: p.color }} />
                        {names[String(p.dataKey)]}
                    </span>
                    <span className="tabular-nums">{money(Number(p.value), currency)}</span>
                </div>
            ))}
            {total !== null && (
                <div className="mt-1 flex justify-between gap-4 border-t pt-1 font-medium">
                    <span>Razem</span>
                    <span className="tabular-nums">{money(total, currency)}</span>
                </div>
            )}
        </div>
    );
}

/** Wydatki dzienne – słupki skumulowane Google / Meta (jedna oś, jedna jednostka). */
export function SpendChart({ data, currency }: { data: DailyRow[]; currency: string }) {
    const names = { google_cost: 'Google Ads', meta_cost: 'Meta Ads' };
    const hasMeta = data.some((d) => d.meta_cost > 0);
    const hasGoogle = data.some((d) => d.google_cost > 0);
    return (
        <div>
            <Legend
                items={[
                    ...(hasGoogle ? [{ label: 'Google Ads', color: 'var(--series-google)' }] : []),
                    ...(hasMeta ? [{ label: 'Meta Ads', color: 'var(--series-meta)' }] : []),
                ]}
            />
            <div className="h-56">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barCategoryGap={2}>
                        <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
                        <XAxis dataKey="date" tickFormatter={shortDate} {...axisProps} minTickGap={24} />
                        <YAxis {...axisProps} axisLine={false} width={56} tickFormatter={(v) => money(v, currency)} />
                        <Tooltip cursor={{ fill: 'var(--muted)', opacity: 0.5 }} content={<ChartTooltip currency={currency} names={names} />} />
                        {hasGoogle && <Bar dataKey="google_cost" stackId="s" fill="var(--series-google)" stroke="var(--card)" strokeWidth={1} radius={hasMeta ? 0 : [4, 4, 0, 0]} />}
                        {hasMeta && <Bar dataKey="meta_cost" stackId="s" fill="var(--series-meta)" stroke="var(--card)" strokeWidth={1} radius={[4, 4, 0, 0]} />}
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

/** Przychód dzienny: z platform reklamowych vs GA4 (cały sklep). */
export function RevenueChart({ data, currency, hasAnalytics }: { data: DailyRow[]; currency: string; hasAnalytics: boolean }) {
    const names = { ads_value: 'Przychód przypisany reklamom', revenue: 'Przychód sklepu (GA4)' };
    return (
        <div>
            <Legend
                items={[
                    { label: names.ads_value, color: 'var(--series-revenue)' },
                    ...(hasAnalytics ? [{ label: names.revenue, color: 'var(--chart-axis)', dashed: true }] : []),
                ]}
            />
            <div className="h-56">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                        <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
                        <XAxis dataKey="date" tickFormatter={shortDate} {...axisProps} minTickGap={24} />
                        <YAxis {...axisProps} axisLine={false} width={64} tickFormatter={(v) => money(v, currency)} />
                        <Tooltip cursor={{ stroke: 'var(--chart-axis)', strokeWidth: 1 }} content={<ChartTooltip currency={currency} names={names} />} />
                        {hasAnalytics && <Line dataKey="revenue" stroke="var(--chart-axis)" strokeWidth={2} strokeDasharray="4 4" dot={false} activeDot={{ r: 4 }} />}
                        <Line dataKey="ads_value" stroke="var(--series-revenue)" strokeWidth={2} dot={false} activeDot={{ r: 4, stroke: 'var(--card)', strokeWidth: 2 }} />
                    </LineChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}

/** Widok tabelaryczny danych z wykresów (dostępność + kontrast). */
export function DailyTable({ data, currency }: { data: DailyRow[]; currency: string }) {
    const [open, setOpen] = useState(false);
    return (
        <div>
            <button onClick={() => setOpen(!open)} className="text-muted-foreground text-xs underline-offset-2 hover:underline">
                {open ? 'Ukryj tabelę danych' : 'Pokaż dane w tabeli'}
            </button>
            {open && (
                <div className="mt-2 max-h-72 overflow-auto rounded border">
                    <table className="w-full text-xs">
                        <thead className="bg-muted sticky top-0">
                            <tr className="text-left">
                                <th className="px-2 py-1">Data</th>
                                <th className="px-2 py-1 text-right">Google Ads</th>
                                <th className="px-2 py-1 text-right">Meta Ads</th>
                                <th className="px-2 py-1 text-right">Przychód (reklamy)</th>
                                <th className="px-2 py-1 text-right">Sesje</th>
                                <th className="px-2 py-1 text-right">Przychód (GA4)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((d) => (
                                <tr key={d.date} className="border-t tabular-nums">
                                    <td className="px-2 py-1">{d.date}</td>
                                    <td className="px-2 py-1 text-right">{money(d.google_cost, currency)}</td>
                                    <td className="px-2 py-1 text-right">{money(d.meta_cost, currency)}</td>
                                    <td className="px-2 py-1 text-right">{money(d.ads_value, currency)}</td>
                                    <td className="px-2 py-1 text-right">{d.sessions.toLocaleString('pl-PL')}</td>
                                    <td className="px-2 py-1 text-right">{money(d.revenue, currency)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

/** Leady dziennie: konwersje z platform reklamowych vs zdarzenia leadowe w GA4 (liczby, jedna oś). */
export function LeadsChart({ data, hasAnalytics }: { data: DailyRow[]; hasAnalytics: boolean }) {
    const names: Record<string, string> = { ads_conversions: 'Leady z reklam (platformy)', leads: 'Formularze/rejestracje/telefony (GA4)' };
    return (
        <div>
            <Legend
                items={[
                    { label: names.ads_conversions, color: 'var(--series-revenue)' },
                    ...(hasAnalytics ? [{ label: names.leads, color: 'var(--chart-axis)', dashed: true }] : []),
                ]}
            />
            <div className="h-56">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={data} margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
                        <CartesianGrid vertical={false} stroke="var(--chart-grid)" />
                        <XAxis dataKey="date" tickFormatter={shortDate} {...axisProps} minTickGap={24} />
                        <YAxis {...axisProps} axisLine={false} width={40} allowDecimals={false} />
                        <Tooltip
                            cursor={{ stroke: 'var(--chart-axis)', strokeWidth: 1 }}
                            content={({ active, payload, label }) =>
                                active && payload?.length ? (
                                    <div className="bg-popover text-popover-foreground rounded-md border px-3 py-2 text-xs shadow-md">
                                        <div className="mb-1 font-medium">{new Date(String(label)).toLocaleDateString('pl-PL', { weekday: 'short', day: 'numeric', month: 'long' })}</div>
                                        {payload.map((p) => (
                                            <div key={String(p.dataKey)} className="flex justify-between gap-4">
                                                <span>{names[String(p.dataKey)]}</span>
                                                <span className="tabular-nums">{Number(p.value).toLocaleString('pl-PL')}</span>
                                            </div>
                                        ))}
                                    </div>
                                ) : null
                            }
                        />
                        {hasAnalytics && <Line dataKey="leads" stroke="var(--chart-axis)" strokeWidth={2} strokeDasharray="4 4" dot={false} activeDot={{ r: 4 }} />}
                        <Line dataKey="ads_conversions" stroke="var(--series-revenue)" strokeWidth={2} dot={false} activeDot={{ r: 4, stroke: 'var(--card)', strokeWidth: 2 }} />
                    </LineChart>
                </ResponsiveContainer>
            </div>
        </div>
    );
}
