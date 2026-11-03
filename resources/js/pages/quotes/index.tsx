import { EmptyState, NativeSelect, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Eye, FilePlus2, FileText, Send, XCircle } from 'lucide-react';

type Totals = Partial<Record<'once' | 'monthly' | 'yearly', { label: string; net: number; vat: number; gross: number }>>;
interface Row {
    id: number;
    number: string;
    title: string;
    status: 'draft' | 'sent' | 'accepted' | 'rejected';
    valid_until: string | null;
    currency: string;
    sent_at: string | null;
    viewed_at: string | null;
    decided_at: string | null;
    created_at: string;
    client: { id: number; name: string } | null;
    totals: Totals;
    expired: boolean;
}

export const QUOTE_STATUS: Record<Row['status'], { label: string; icon: typeof FileText; cls: string }> = {
    draft: { label: 'Szkic', icon: FileText, cls: 'text-muted-foreground bg-muted border-border' },
    sent: { label: 'Wysłana', icon: Send, cls: 'text-[#2a78d6] bg-[#2a78d6]/10 border-[#2a78d6]/40' },
    accepted: { label: 'Zaakceptowana', icon: CheckCircle2, cls: 'text-[#0a7d0a] bg-[#0ca30c]/10 border-[#0ca30c]/40 dark:text-[#4ccc4c]' },
    rejected: { label: 'Odrzucona', icon: XCircle, cls: 'text-[#b42f2f] bg-[#d03b3b]/10 border-[#d03b3b]/40 dark:text-[#f08080]' },
};

export function QuoteStatus({ status, expired }: { status: Row['status']; expired?: boolean }) {
    const s = QUOTE_STATUS[status];
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap', s.cls)}>
            <s.icon className="size-3" /> {expired ? 'Wygasła' : s.label}
        </span>
    );
}

export function totalsLabel(t: Totals, currency: string) {
    return [
        t.once && money(t.once.net, currency),
        t.monthly && `${money(t.monthly.net, currency)}/mies.`,
        t.yearly && `${money(t.yearly.net, currency)}/rok`,
    ]
        .filter(Boolean)
        .join(' + ');
}

export default function QuotesIndex({
    quotes,
    clients,
    templates,
    status,
    stats,
}: {
    quotes: Row[];
    clients: { id: number; name: string }[];
    templates: Record<string, string>;
    status: string | null;
    stats: { sent: number; accepted_month: number; accepted_value_month: number };
}) {
    const form = useForm({ client_id: clients[0]?.id ? String(clients[0].id) : '', template: 'website' });

    return (
        <AppLayout breadcrumbs={[{ title: 'Oferty', href: '/quotes' }]}>
            <Head title="Oferty" />
            <div className="flex flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Oferty</h1>
                    <p className="text-muted-foreground text-sm">
                        Czeka na decyzję: {stats.sent} · zaakceptowane w tym miesiącu: {stats.accepted_month} (wartość roczna{' '}
                        {money(stats.accepted_value_month, 'PLN')} netto)
                    </p>
                </div>

                <Section
                    title="Nowa oferta"
                    description="Wybierz klienta i szablon – pozycje, opisy i ceny poprawisz w następnym kroku. Nowego potencjalnego klienta dodaj najpierw w „Nowy klient”."
                >
                    {clients.length === 0 ? (
                        <EmptyState title="Najpierw dodaj klienta" />
                    ) : (
                        <form
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post('/quotes');
                            }}
                        >
                            <div className="grid gap-1.5">
                                <Label>Klient</Label>
                                <NativeSelect value={form.data.client_id} onChange={(e) => form.setData('client_id', e.target.value)}>
                                    {clients.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Szablon</Label>
                                <NativeSelect value={form.data.template} onChange={(e) => form.setData('template', e.target.value)}>
                                    {Object.entries(templates).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                    <option value="">Pusta oferta</option>
                                </NativeSelect>
                            </div>
                            <Button type="submit" disabled={form.processing}>
                                <FilePlus2 className="size-4" /> Utwórz ofertę
                            </Button>
                        </form>
                    )}
                </Section>

                <Section
                    title="Wszystkie oferty"
                    actions={
                        <NativeSelect
                            value={status ?? ''}
                            onChange={(e) => router.get('/quotes', e.target.value ? { status: e.target.value } : {}, { preserveState: true })}
                        >
                            <option value="">Wszystkie</option>
                            {Object.entries(QUOTE_STATUS).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v.label}
                                </option>
                            ))}
                        </NativeSelect>
                    }
                >
                    {quotes.length === 0 ? (
                        <EmptyState title="Brak ofert" />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="py-2 font-medium">Numer</th>
                                        <th className="py-2 font-medium">Klient / tytuł</th>
                                        <th className="py-2 font-medium">Wartość netto</th>
                                        <th className="py-2 font-medium">Status</th>
                                        <th className="py-2 font-medium">Ważna do</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {quotes.map((q) => (
                                        <tr key={q.id} className="border-b last:border-0">
                                            <td className="py-2 font-mono text-xs">
                                                <Link href={`/quotes/${q.id}`} className="hover:underline">
                                                    {q.number}
                                                </Link>
                                            </td>
                                            <td className="py-2">
                                                <Link href={`/quotes/${q.id}`} className="font-medium hover:underline">
                                                    {q.title}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">{q.client?.name}</div>
                                            </td>
                                            <td className="py-2 tabular-nums">{totalsLabel(q.totals, q.currency) || '–'}</td>
                                            <td className="py-2">
                                                <QuoteStatus status={q.status} expired={q.expired} />
                                                {q.status === 'sent' && q.viewed_at && (
                                                    <div className="text-muted-foreground mt-0.5 inline-flex items-center gap-1 text-xs">
                                                        <Eye className="size-3" /> otwarta
                                                    </div>
                                                )}
                                            </td>
                                            <td className="py-2 text-xs">{date(q.valid_until)}</td>
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
