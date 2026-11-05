import { EmptyState, NetGross, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ExternalLink, Mail, RotateCcw } from 'lucide-react';

interface Invoice {
    id: number;
    number: string | null;
    buyer_name: string | null;
    total: number;
    currency: string;
    issue_date: string | null;
    payment_to: string | null;
    view_url: string | null;
    status: 'issued' | 'sent' | 'paid';
    sent_at: string | null;
    paid_at: string | null;
    client: { id: number; name: string } | null;
    agency: { id: number; name: string } | null;
}

const STATUS = { issued: 'wystawiona', sent: 'wysłana', paid: 'opłacona' };

export default function Invoices({
    invoices,
    status,
    configured,
    unpaid,
    overdue,
}: {
    invoices: Invoice[];
    status: string | null;
    configured: boolean;
    unpaid: number;
    overdue: number;
}) {
    const today = new Date().toISOString().slice(0, 10);
    return (
        <AppLayout breadcrumbs={[{ title: 'Faktury', href: '/invoices' }]}>
            <Head title="Faktury" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Faktury</h1>
                        <p className="text-muted-foreground text-sm">
                            Niezapłacone: <NetGross value={unpaid} />
                            {overdue > 0 && <span className="font-semibold text-[#b42f2f] dark:text-[#f08080]"> · po terminie: {overdue}</span>}
                        </p>
                    </div>
                    <div className="flex rounded-md border p-0.5">
                        <Button variant={!status ? 'secondary' : 'ghost'} size="sm" className="h-8" asChild>
                            <Link href="/invoices">Wszystkie</Link>
                        </Button>
                        <Button variant={status === 'unpaid' ? 'secondary' : 'ghost'} size="sm" className="h-8" asChild>
                            <Link href="/invoices?status=unpaid">Niezapłacone</Link>
                        </Button>
                    </div>
                </div>

                {!configured && (
                    <div className="rounded-lg border px-3 py-2 text-sm">
                        Fakturownia nie jest podłączona – faktury wystawisz jednym kliknięciem po podłączeniu w{' '}
                        <Link href="/admin/settings#fakturownia" className="underline">
                            Ustawieniach agencji
                        </Link>
                        . Przycisk „Wystaw fakturę” znajdziesz w Rozliczeniach, u klienta i w agencji.
                    </div>
                )}

                <Section title={`Faktury (${invoices.length})`}>
                    {invoices.length === 0 ? (
                        <EmptyState title="Brak faktur wystawionych z panelu" />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left text-xs">
                                    <tr>
                                        <th className="py-1.5">Numer</th>
                                        <th className="py-1.5">Nabywca</th>
                                        <th className="py-1.5">Wystawiona</th>
                                        <th className="py-1.5">Termin</th>
                                        <th className="py-1.5 text-right">Kwota</th>
                                        <th className="py-1.5">Status</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {invoices.map((i) => {
                                        const late = i.status !== 'paid' && i.payment_to && i.payment_to.slice(0, 10) < today;
                                        return (
                                            <tr key={i.id} className="border-t">
                                                <td className="py-2 font-medium whitespace-nowrap">
                                                    {i.view_url ? (
                                                        <a
                                                            href={i.view_url}
                                                            target="_blank"
                                                            rel="noreferrer noopener"
                                                            className="inline-flex items-center gap-1 hover:underline"
                                                        >
                                                            {i.number ?? 'bez numeru'} <ExternalLink className="size-3" />
                                                        </a>
                                                    ) : (
                                                        (i.number ?? '–')
                                                    )}
                                                </td>
                                                <td className="py-2">
                                                    {i.client ? (
                                                        <Link href={`/clients/${i.client.id}/billing`} className="hover:underline">
                                                            {i.buyer_name ?? i.client.name}
                                                        </Link>
                                                    ) : i.agency ? (
                                                        <Link href={`/agencies/${i.agency.id}`} className="hover:underline">
                                                            {i.buyer_name ?? i.agency.name}
                                                        </Link>
                                                    ) : (
                                                        i.buyer_name
                                                    )}
                                                </td>
                                                <td className="py-2 whitespace-nowrap">{date(i.issue_date)}</td>
                                                <td
                                                    className={cn(
                                                        'py-2 whitespace-nowrap',
                                                        late && 'font-semibold text-[#b42f2f] dark:text-[#f08080]',
                                                    )}
                                                >
                                                    {date(i.payment_to)}
                                                </td>
                                                <td className="py-2 text-right whitespace-nowrap">
                                                    <NetGross value={i.total} currency={i.currency} />
                                                </td>
                                                <td className="py-2 text-xs whitespace-nowrap">
                                                    {late ? 'po terminie' : STATUS[i.status]}
                                                    {i.paid_at && ` ${date(i.paid_at)}`}
                                                </td>
                                                <td className="py-2 text-right whitespace-nowrap">
                                                    {i.status !== 'paid' && (
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-7"
                                                            title={i.sent_at ? 'Wyślij ponownie e-mailem' : 'Wyślij e-mailem'}
                                                            onClick={() => router.post(`/invoices/${i.id}/send`, {}, { preserveScroll: true })}
                                                        >
                                                            <Mail className="size-3.5" />
                                                        </Button>
                                                    )}
                                                    <Button
                                                        variant={i.status === 'paid' ? 'ghost' : 'outline'}
                                                        size="sm"
                                                        className="h-7"
                                                        title={i.status === 'paid' ? 'Cofnij „opłacona”' : 'Oznacz jako opłaconą'}
                                                        onClick={() => router.post(`/invoices/${i.id}/paid`, {}, { preserveScroll: true })}
                                                    >
                                                        {i.status === 'paid' ? (
                                                            <RotateCcw className="size-3.5" />
                                                        ) : (
                                                            <CheckCircle2 className="size-3.5" />
                                                        )}
                                                        {i.status !== 'paid' && ' Opłacona'}
                                                    </Button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
