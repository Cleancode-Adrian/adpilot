import { type BillingItem, BillingRow } from '@/components/adpilot/billing';
import { EmptyState, Section } from '@/components/adpilot/ui';
import AppLayout from '@/layouts/app-layout';
import { money } from '@/lib/format';
import { Head } from '@inertiajs/react';

export default function Billing({
    toInvoice,
    renewals,
    all,
    mrr,
    mrrSubscriptions,
    types,
    periods,
}: {
    toInvoice: BillingItem[];
    renewals: BillingItem[];
    all: BillingItem[];
    mrr: number;
    mrrSubscriptions: number;
    types: Record<string, string>;
    periods: Record<string, string>;
}) {
    const invoiceSum = toInvoice.reduce((s, i) => s + (i.amount ?? 0), 0);
    const tile = (label: string, value: string, hint: string) => (
        <div className="bg-card rounded-xl border p-4">
            <div className="text-muted-foreground text-sm">{label}</div>
            <div className="mt-1 text-2xl font-semibold tabular-nums">{value}</div>
            <div className="text-muted-foreground mt-1 text-xs">{hint}</div>
        </div>
    );

    return (
        <AppLayout breadcrumbs={[{ title: 'Rozliczenia', href: '/billing' }]}>
            <Head title="Rozliczenia" />
            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Rozliczenia i odnowienia</h1>
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {tile('Przychód miesięczny (abonamenty)', money(mrrSubscriptions, 'PLN', 2), 'MRR netto – opieka, kampanie, inne stałe usługi')}
                    {tile('Wszystkie pozycje / mies.', money(mrr, 'PLN', 2), 'z domenami, hostingami i licencjami w przeliczeniu na miesiąc')}
                    {tile(
                        'Do zafakturowania w tym miesiącu',
                        money(invoiceSum, 'PLN', 2),
                        `${toInvoice.length} ${toInvoice.length === 1 ? 'abonament' : 'abonamentów'}`,
                    )}
                    {tile('Odnowienia w 60 dni', String(renewals.length), 'domeny, hostingi, licencje')}
                </div>

                <Section
                    title="Do zafakturowania w tym miesiącu"
                    description="Po wystawieniu faktury kliknij „Rozliczono” – termin przesunie się o okres."
                >
                    {toInvoice.length === 0 ? (
                        <EmptyState title="Nic do zafakturowania w tym miesiącu" />
                    ) : (
                        <ul className="divide-y">
                            {toInvoice.map((i) => (
                                <BillingRow key={i.id} item={i} types={types} periods={periods} showClient />
                            ))}
                        </ul>
                    )}
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
