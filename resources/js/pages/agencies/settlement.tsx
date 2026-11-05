import { EmptyState, NetGross, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { date, money } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCheck, FileDown, Pencil } from 'lucide-react';

interface TaskRow {
    id: number;
    client_id: number;
    client: string | null;
    title: string;
    note: string | null;
    done_at: string | null;
    minutes: number;
    amount: number | null;
    value: number | null;
    source: 'amount' | 'time' | null;
    invoiced: boolean;
}
interface Data {
    month: string;
    month_label: string;
    rate: number | null;
    currency: string;
    tasks: TaskRow[];
    sites: { client_id: number; client: string; tasks: number; minutes: number; value: number }[];
    subscriptions: {
        id: number;
        client: string | null;
        type: string;
        name: string;
        period: string;
        next_date: string;
        amount: number | null;
        currency: string;
    }[];
    totals: { tasks: number; minutes: number; work: number; subscriptions: number; open: number; open_tasks: number; unpriced: number };
}

function Tile({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className="mt-1 text-2xl font-semibold tracking-tight tabular-nums">{value}</div>
        </div>
    );
}

const fmtTime = (m: number) => (m >= 60 ? `${Math.floor(m / 60)} h${m % 60 ? ` ${m % 60} min` : ''}` : `${m} min`);

export default function AgencySettlement({
    agency,
    data,
}: {
    agency: { id: number; name: string; hourly_rate: number | null; currency: string };
    data: Data;
}) {
    const cur = data.currency;
    const m2 = (v: number | null) => money(v, cur, 2);
    const go = (month: string) => router.get(`/agencies/${agency.id}/settlement`, { month }, { preserveScroll: true });

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Agencje', href: '/agencies' },
                { title: agency.name, href: `/agencies/${agency.id}` },
                { title: 'Rozliczenie', href: '#' },
            ]}
        >
            <Head title={`Rozliczenie – ${agency.name}`} />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Rozliczenie – {agency.name}, {data.month_label}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Prace wykonane w miesiącu na stronach agencji + abonamenty i odnowienia z terminem w tym miesiącu. Kwoty netto.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Input type="month" value={data.month} onChange={(e) => e.target.value && go(e.target.value)} className="h-9 w-40" />
                        <Button variant="outline" size="sm" asChild>
                            <a href={`/agencies/${agency.id}/settlement/pdf?month=${data.month}`}>
                                <FileDown className="size-4" /> PDF dla agencji
                            </a>
                        </Button>
                        <Button
                            size="sm"
                            disabled={data.totals.open_tasks === 0}
                            onClick={() =>
                                confirm(
                                    `Oznaczyć ${data.totals.open_tasks} prac z ${data.month_label} jako zafakturowane (${m2(data.totals.open)})?`,
                                ) && router.post(`/agencies/${agency.id}/settlement/invoiced`, { month: data.month }, { preserveScroll: true })
                            }
                        >
                            <CheckCheck className="size-4" /> Zafakturowane
                        </Button>
                    </div>
                </div>

                {!data.rate && (
                    <div className="rounded-lg border border-[#a4471f]/40 bg-[#a4471f]/5 px-3 py-2 text-sm">
                        Agencja nie ma stawki godzinowej – prace bez wpisanej kwoty nie mają wartości.{' '}
                        <Link href={`/agencies/${agency.id}/edit`} className="inline-flex items-center gap-1 underline">
                            <Pencil className="size-3" /> Ustaw stawkę
                        </Link>
                    </div>
                )}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Tile label="Wykonane prace" value={String(data.totals.tasks)} />
                    <Tile label="Czas pracy" value={fmtTime(data.totals.minutes)} />
                    <Tile label="Wartość prac" value={<NetGross value={data.totals.work} currency={cur} size="lg" />} />
                    <Tile label="Do zafakturowania (prace)" value={<NetGross value={data.totals.open} currency={cur} size="lg" />} />
                </div>
                {data.totals.unpriced > 0 && (
                    <p className="text-muted-foreground text-sm">
                        {data.totals.unpriced} prac bez kwoty i bez czasu – uzupełnij czas lub kwotę w zadaniach, jeśli mają być rozliczone.
                    </p>
                )}

                {data.tasks.length === 0 && data.subscriptions.length === 0 ? (
                    <EmptyState title={`Brak prac i abonamentów w miesiącu ${data.month_label}`} />
                ) : (
                    <>
                        {data.sites.length > 1 && (
                            <Section title="Wg stron">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground text-left text-xs">
                                        <tr>
                                            <th className="py-1">Strona</th>
                                            <th className="py-1 text-right">Prace</th>
                                            <th className="py-1 text-right">Czas</th>
                                            <th className="py-1 text-right">Wartość</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {data.sites.map((s) => (
                                            <tr key={s.client_id} className="border-t">
                                                <td className="py-1.5">
                                                    <Link href={`/clients/${s.client_id}/care`} className="hover:underline">
                                                        {s.client}
                                                    </Link>
                                                </td>
                                                <td className="py-1.5 text-right">{s.tasks}</td>
                                                <td className="py-1.5 text-right">{fmtTime(s.minutes)}</td>
                                                <td className="py-1.5 text-right">{m2(s.value)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Section>
                        )}

                        <Section title="Wykonane prace" description={data.rate ? `Stawka: ${m2(data.rate)}/h` : undefined}>
                            {data.tasks.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Brak wykonanych prac w tym miesiącu.</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="text-muted-foreground text-left text-xs">
                                            <tr>
                                                <th className="py-1">Data</th>
                                                <th className="py-1">Strona</th>
                                                <th className="py-1">Zadanie</th>
                                                <th className="py-1 text-right">Czas</th>
                                                <th className="py-1 text-right">Wartość</th>
                                                <th className="py-1 text-right">Faktura</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {data.tasks.map((t) => (
                                                <tr key={t.id} className="border-t align-top">
                                                    <td className="py-1.5 whitespace-nowrap">{date(t.done_at)}</td>
                                                    <td className="py-1.5">{t.client}</td>
                                                    <td className="py-1.5">
                                                        {t.title}
                                                        {t.note && <div className="text-muted-foreground line-clamp-2 text-xs">{t.note}</div>}
                                                    </td>
                                                    <td className="py-1.5 text-right whitespace-nowrap">{t.minutes ? fmtTime(t.minutes) : '–'}</td>
                                                    <td className="py-1.5 text-right whitespace-nowrap">
                                                        {t.value === null ? '–' : m2(t.value)}
                                                        {t.source === 'time' && <div className="text-muted-foreground text-[11px]">wg stawki</div>}
                                                    </td>
                                                    <td className="py-1.5 text-right text-xs">
                                                        {t.invoiced ? '✓' : t.value !== null ? 'do faktury' : ''}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Section>

                        {data.subscriptions.length > 0 && (
                            <Section
                                title="Abonamenty i odnowienia w tym miesiącu"
                                description="Po wystawieniu faktury przesuń termin w Rozliczeniach."
                            >
                                <table className="w-full text-sm">
                                    <tbody>
                                        {data.subscriptions.map((b) => (
                                            <tr key={b.id} className="border-t first:border-t-0">
                                                <td className="py-1.5 whitespace-nowrap">{date(b.next_date)}</td>
                                                <td className="py-1.5">{b.client}</td>
                                                <td className="py-1.5">
                                                    {b.type}: {b.name} <span className="text-muted-foreground text-xs">({b.period})</span>
                                                </td>
                                                <td className="py-1.5 text-right whitespace-nowrap">{money(b.amount, b.currency, 2)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Section>
                        )}

                        <p className="text-right text-base font-semibold">
                            Razem do rozliczenia: <NetGross value={data.totals.work + data.totals.subscriptions} currency={cur} />
                        </p>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
