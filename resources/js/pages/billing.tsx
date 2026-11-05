import { type BillingItem, BillingRow, type ExtraWork, ExtraWorkList } from '@/components/adpilot/billing';
import { EmptyState, NetGross, Section } from '@/components/adpilot/ui';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/format';
import { Head } from '@inertiajs/react';

export default function Billing({
    toInvoice,
    extraWork,
    extraThisMonth,
    extraOpen,
    renewals,
    all,
    mrr,
    mrrSubscriptions,
    types,
    periods,
}: {
    toInvoice: BillingItem[];
    extraWork: ExtraWork[];
    extraThisMonth: number;
    extraOpen: number;
    renewals: BillingItem[];
    all: BillingItem[];
    mrr: number;
    mrrSubscriptions: number;
    types: Record<string, string>;
    periods: Record<string, string>;
}) {
    const invoiceSum = toInvoice.reduce((s, i) => s + (i.amount ?? 0), 0);
    const tile = (label: string, value: React.ReactNode, hint: string) => (
        <div className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
            <div className="text-muted-foreground mt-1 text-xs">{hint}</div>
        </div>
    );
    const pln = (v: number) => money(v, 'PLN', 2);

    return (
        <AppLayout breadcrumbs={[{ title: 'Rozliczenia', href: '/billing' }]}>
            <Head title="Rozliczenia" />
            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Rozliczenia i odnowienia</h1>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {tile(
                        'Przychód w tym miesiącu',
                        <NetGross value={mrrSubscriptions + extraThisMonth} size="lg" />,
                        `abonamenty ${pln(mrrSubscriptions)} + prace dodatkowe ${pln(extraThisMonth)} (netto)`,
                    )}
                    {tile(
                        'Abonamenty miesięcznie (MRR)',
                        <NetGross value={mrrSubscriptions} size="lg" />,
                        `stałe usługi; z domenami, hostingami i licencjami: ${pln(mrr)} / mies. netto`,
                    )}
                    {tile(
                        'Do zafakturowania',
                        <NetGross value={invoiceSum + extraOpen} size="lg" />,
                        `${toInvoice.length} ${toInvoice.length === 1 ? 'abonament' : 'abonamentów'} w tym miesiącu + prace dodatkowe ${pln(extraOpen)}`,
                    )}
                    {tile('Odnowienia w 60 dni', String(renewals.length), 'domeny, hostingi, licencje')}
                </div>

                <Section
                    title="Abonamenty do zafakturowania w tym miesiącu"
                    description="Po wystawieniu faktury kliknij „Rozliczono” – termin przesunie się o okres."
                >
                    {toInvoice.length === 0 ? (
                        <EmptyState title="Brak abonamentów do zafakturowania w tym miesiącu" />
                    ) : (
                        <ul className="divide-y">
                            {toInvoice.map((i) => (
                                <BillingRow key={i.id} item={i} types={types} periods={periods} showClient />
                            ))}
                        </ul>
                    )}
                </Section>

                <Section
                    title="Prace dodatkowe do zafakturowania"
                    description="Wykonane zadania z wpisaną kwotą. Po wystawieniu faktury kliknij „Zafakturowano”."
                >
                    <ExtraWorkList groups={extraWork} />
                </Section>

                <Section title="Odnowienia w ciągu 60 dni" description="Domena po terminie może trafić w obce ręce – odnawiaj z wyprzedzeniem.">
                    {renewals.length === 0 ? (
                        <EmptyState title="Brak odnowień w najbliższych 60 dniach" />
                    ) : (
                        <ul className="divide-y">
                            {renewals.map((i) => (
                                <BillingRow key={i.id} item={i} types={types} periods={periods} showClient />
                            ))}
                        </ul>
                    )}
                </Section>

                <Section title={`Wszystkie aktywne pozycje (${all.length})`} description="Dodajesz je w kliencie → zakładka „Oferty i rozliczenia”.">
                    {all.length === 0 ? (
                        <EmptyState title="Brak pozycji" />
                    ) : (
                        <ul className="divide-y">
                            {all.map((i) => (
                                <BillingRow key={i.id} item={i} types={types} periods={periods} showClient />
                            ))}
                        </ul>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
