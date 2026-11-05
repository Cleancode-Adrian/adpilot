import { formatMinutes } from '@/components/adpilot/care-task-row';
import { EmptyState, NetGross, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CircleHelp } from 'lucide-react';

interface Row {
    id: number;
    name: string;
    agency: string | null;
    revenue: number;
    subscriptions: number;
    extra: number;
    hourly: number;
    minutes: number;
    tasks: number;
    effective_rate: number | null;
    flag: 'ok' | 'low' | 'no_time' | 'idle';
}
interface Data {
    months: number;
    from: string;
    target: number | null;
    rows: Row[];
    totals: { revenue: number; minutes: number; effective_rate: number | null };
}

export default function Profitability({ data }: { data: Data }) {
    const pln = (v: number) => money(v, 'PLN', 0);
    const maxRate = Math.max(1, ...data.rows.map((r) => r.effective_rate ?? 0));

    return (
        <AppLayout breadcrumbs={[{ title: 'Rentowność', href: '/profitability' }]}>
            <Head title="Rentowność" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Rentowność klientów</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">
                            Ile realnie zarabiasz na godzinie pracy u każdego klienta: przychód (abonamenty, prace dodatkowe, praca wg stawki agencji)
                            ÷ czas zapisany przy zadaniach. Najsłabsi na górze.
                        </p>
                    </div>
                    <div className="flex rounded-md border p-0.5">
                        {[1, 3, 6, 12].map((m) => (
                            <Button
                                key={m}
                                variant={data.months === m ? 'secondary' : 'ghost'}
                                size="sm"
                                className="h-8"
                                onClick={() => router.get('/profitability', { months: m }, { preserveScroll: true })}
                            >
                                {m === 1 ? 'Ten miesiąc' : `${m} mies.`}
                            </Button>
                        ))}
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="bg-card rounded-xl border p-4">
                        <div className="text-muted-foreground text-sm">Przychód z pracy</div>
                        <div className="mt-1 text-2xl font-semibold">
                            <NetGross value={data.totals.revenue} size="lg" />
                        </div>
                    </div>
                    <div className="bg-card rounded-xl border p-4">
                        <div className="text-muted-foreground text-sm">Zapisany czas pracy</div>
                        <div className="mt-1 text-2xl font-semibold">{formatMinutes(data.totals.minutes) || '0 min'}</div>
                    </div>
                    <div className="bg-card rounded-xl border p-4">
                        <div className="text-muted-foreground text-sm">Średnia stawka za godzinę</div>
                        <div className="mt-1 text-2xl font-semibold">{data.totals.effective_rate ? `${pln(data.totals.effective_rate)}/h` : '–'}</div>
                        <div className="text-muted-foreground mt-1 text-xs">
                            {data.target ? (
                                `cel: ${pln(data.target)}/h`
                            ) : (
                                <Link href="/admin/settings" className="underline">
                                    ustaw docelową stawkę
                                </Link>
                            )}
                        </div>
                    </div>
                </div>

                <Section title="Klienci">
                    {data.rows.length === 0 ? (
                        <EmptyState title="Brak danych – dodaj abonamenty w Rozliczeniach i zapisuj czas przy zadaniach (stoper ▶)" />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="py-1.5">Klient</th>
                                        <th className="py-1.5 text-right">Przychód</th>
                                        <th className="py-1.5 text-right">Czas</th>
                                        <th className="py-1.5 text-right">Zadania</th>
                                        <th className="w-1/3 py-1.5 pl-4">Stawka za godzinę</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.rows.map((r) => (
                                        <tr key={r.id} className="border-t align-top">
                                            <td className="py-2">
                                                <Link href={`/clients/${r.id}`} className="font-medium hover:underline">
                                                    {r.name}
                                                </Link>
                                                {r.agency && <div className="text-muted-foreground text-xs">agencja {r.agency}</div>}
                                            </td>
                                            <td
                                                className="py-2 text-right whitespace-nowrap"
                                                title={`abonamenty ${pln(r.subscriptions)} · prace ${pln(r.extra)} · wg stawki ${pln(r.hourly)}`}
                                            >
                                                <NetGross value={r.revenue} />
                                            </td>
                                            <td className="py-2 text-right whitespace-nowrap">{r.minutes ? formatMinutes(r.minutes) : '–'}</td>
                                            <td className="py-2 text-right">{r.tasks}</td>
                                            <td className="py-2 pl-4">
                                                {r.effective_rate !== null ? (
                                                    <div className="flex items-center gap-2">
                                                        <div className="bg-muted h-2 flex-1 overflow-hidden rounded-full">
                                                            <div
                                                                className={cn(
                                                                    'h-full rounded-full',
                                                                    r.flag === 'low' ? 'bg-[#b42f2f]' : 'bg-[#2a78d6]',
                                                                )}
                                                                style={{ width: `${Math.max(3, (r.effective_rate / maxRate) * 100)}%` }}
                                                            />
                                                        </div>
                                                        <span
                                                            className={cn(
                                                                'w-24 text-right font-semibold whitespace-nowrap tabular-nums',
                                                                r.flag === 'low' && 'text-[#b42f2f] dark:text-[#f08080]',
                                                            )}
                                                        >
                                                            {r.flag === 'low' && <AlertTriangle className="mr-1 inline size-3.5" />}
                                                            {pln(r.effective_rate)}/h
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                                        <CircleHelp className="size-3.5" />
                                                        {r.flag === 'no_time'
                                                            ? 'brak zapisanego czasu – włączaj stoper przy zadaniach'
                                                            : 'brak przychodu'}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
