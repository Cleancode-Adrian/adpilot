import { BillingForm, type BillingItem, BillingRow, type ExtraWork, ExtraWorkList } from '@/components/adpilot/billing';
import { InvoiceButton } from '@/components/adpilot/invoice-dialog';
import { Section, useVat } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import ClientLayout from '@/layouts/client-layout';
import { money } from '@/lib/format';
import { type ClientProps } from '@/types/adpilot';
import { Link, router } from '@inertiajs/react';
import { FilePlus2, Plus } from 'lucide-react';
import { useState } from 'react';
import { QuoteStatus, totalsLabel } from '../quotes/index';

interface QuoteRow {
    id: number;
    number: string;
    title: string;
    status: 'draft' | 'sent' | 'accepted' | 'rejected';
    currency: string;
    expired: boolean;
    totals: Record<string, { net: number }>;
}

export default function ClientBilling({
    client,
    items,
    extraWork,
    quotes,
    types,
    periods,
}: {
    client: ClientProps;
    items: BillingItem[];
    extraWork: ExtraWork[];
    quotes: QuoteRow[];
    types: Record<string, string>;
    periods: Record<string, string>;
}) {
    const [mode, setMode] = useState<'add' | number | null>(null);
    const active = items.filter((i) => i.active);
    const mrr = active.reduce((s, i) => s + i.monthly_value, 0);
    const { vat, gross, net } = useVat();

    return (
        <ClientLayout client={client} title="Oferty i rozliczenia">
            <Section
                title="Abonamenty, domeny, hostingi, licencje"
                description={`Przypomnienie przychodzi e-mailem i jako zadanie u klienta – przed fakturą lub odnowieniem. Przychód miesięczny od klienta: ${money(mrr, client.currency, 2)}${net}${vat === null ? '' : ` (${money(gross(mrr), client.currency, 2)} brutto)`}.`}
                actions={
                    <div className="flex gap-2">
                        <InvoiceButton clientId={client.id} currency={client.currency} size="default" />
                        {mode !== 'add' && (
                            <Button size="sm" onClick={() => setMode('add')}>
                                <Plus className="size-4" /> Dodaj
                            </Button>
                        )}
                    </div>
                }
            >
                {mode === 'add' && (
                    <div className="mb-3">
                        <BillingForm clientId={client.id} types={types} periods={periods} onDone={() => setMode(null)} />
                    </div>
                )}
                {items.length === 0 && mode !== 'add' ? (
                    <p className="text-muted-foreground text-sm">
                        Brak pozycji. Dodaj abonament opieki, domenę, hosting albo licencje wtyczek – panel przypomni o fakturze i odnowieniu.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {items.map((i) =>
                            mode === i.id ? (
                                <li key={i.id} className="py-2.5">
                                    <BillingForm clientId={client.id} item={i} types={types} periods={periods} onDone={() => setMode(null)} />
                                </li>
                            ) : (
                                <BillingRow key={i.id} item={i} types={types} periods={periods} onEdit={() => setMode(i.id)} />
                            ),
                        )}
                    </ul>
                )}
            </Section>

            <Section title="Prace dodatkowe do zafakturowania">
                <ExtraWorkList groups={extraWork} />
            </Section>

            <Section
                title={`Oferty (${quotes.length})`}
                actions={
                    <Button variant="outline" size="sm" onClick={() => router.post('/quotes', { client_id: client.id, template: '' })}>
                        <FilePlus2 className="size-4" /> Nowa oferta
                    </Button>
                }
            >
                {quotes.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        Brak ofert. Ofertę z szablonu utworzysz w{' '}
                        <Link href="/quotes" className="underline">
                            Ofertach
                        </Link>
                        .
                    </p>
                ) : (
                    <ul className="divide-y text-sm">
                        {quotes.map((q) => (
                            <li key={q.id} className="flex flex-wrap items-center gap-3 py-2">
                                <Link href={`/quotes/${q.id}`} className="font-mono text-xs hover:underline">
                                    {q.number}
                                </Link>
                                <Link href={`/quotes/${q.id}`} className="flex-1 font-medium hover:underline">
                                    {q.title}
                                </Link>
                                <span className="tabular-nums">{totalsLabel(q.totals, q.currency)}</span>
                                <QuoteStatus status={q.status} expired={q.expired} />
                            </li>
                        ))}
                    </ul>
                )}
            </Section>
        </ClientLayout>
    );
}
