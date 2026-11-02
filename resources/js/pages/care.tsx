import { CareProgress, CareTaskRow, MonthSwitcher } from '@/components/adpilot/care-task-row';
import { EmptyState } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type CareSummary, type CareTask } from '@/types/adpilot';
import { Head, Link, router } from '@inertiajs/react';
import { AlarmClock, ChevronDown, ExternalLink } from 'lucide-react';
import { useState } from 'react';

interface Row {
    client: { id: number; name: string; website_url: string };
    tasks: CareTask[];
    summary: CareSummary;
}

function ClientCare({ row, defaultOpen }: { row: Row; defaultOpen: boolean }) {
    const [open, setOpen] = useState(defaultOpen);
    const { client, tasks, summary } = row;
    const left = summary.total - summary.done;
    const visible = open ? tasks : [];

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center gap-3 px-4 py-3">
                <button onClick={() => setOpen(!open)} className="flex min-w-0 flex-1 items-center gap-2 text-left" aria-expanded={open}>
                    <ChevronDown className={`size-4 shrink-0 transition ${open ? '' : '-rotate-90'}`} />
                    <span className="truncate font-semibold">{client.name}</span>
                </button>
                <div className="flex flex-wrap items-center gap-3 text-xs">
                    {summary.overdue > 0 && (
                        <span className="inline-flex items-center gap-1 font-medium text-[#b42f2f] dark:text-[#f08080]">
                            <AlarmClock className="size-3.5" /> {summary.overdue} po terminie
                        </span>
                    )}
                    {summary.total > 0 && <span className="text-muted-foreground">{left === 0 ? 'miesiąc zamknięty ✓' : `zostało ${left}`}</span>}
                    {summary.total > 0 && <CareProgress done={summary.done} total={summary.total} />}
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/clients/${client.id}/care?month=${summary.period}`}>
                            Otwórz <ExternalLink className="size-3.5" />
                        </Link>
                    </Button>
                </div>
            </div>
            {open && visible.length > 0 && (
                <ul className="grid gap-2 border-t p-4">
                    {visible.map((t) => (
                        <CareTaskRow key={t.id} task={t} showPeriod={t.period !== summary.period} />
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function CareOverview({
    period,
    rows,
    clientsWithoutPlan,
}: {
    period: string;
    rows: Row[];
    clientsWithoutPlan: { id: number; name: string }[];
}) {
    const go = (p: string) => router.get('/care', { month: p }, { preserveScroll: true });
    const total = rows.reduce((s, r) => s + r.summary.total, 0);
    const done = rows.reduce((s, r) => s + r.summary.done, 0);
    const overdue = rows.reduce((s, r) => s + r.summary.overdue, 0);

    return (
        <AppLayout breadcrumbs={[{ title: 'Zadania i opieka', href: '/care' }]}>
            <Head title="Zadania i opieka" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Zadania i opieka nad stronami</h1>
                        <p className="text-muted-foreground text-sm">
                            {total
                                ? `Wykonano ${done} z ${total} zadań u ${rows.length} ${rows.length === 1 ? 'klienta' : 'klientów'}`
                                : 'Comiesięczne zadania: bezpieczeństwo, aktualizacje, kopie.'}
                            {overdue > 0 && ` · ${overdue} po terminie`}
                        </p>
                    </div>
                    <MonthSwitcher period={period} onChange={go} />
                </div>

                {rows.length === 0 ? (
                    <EmptyState title="Brak zadań opieki w tym miesiącu">
                        Wejdź w klienta → zakładka <strong>Opieka</strong> i dodaj plan (np. pakiet „Bezpieczeństwo strony” albo „Aktualizacje
                        WordPress”).
                    </EmptyState>
                ) : (
                    <div className="grid gap-3">
                        {rows.map((r) => (
                            <ClientCare key={r.client.id} row={r} defaultOpen={false} />
                        ))}
                    </div>
                )}

                {clientsWithoutPlan.length > 0 && (
                    <p className="text-muted-foreground text-sm">
                        Bez planu opieki:{' '}
                        {clientsWithoutPlan.map((c, i) => (
                            <span key={c.id}>
                                {i > 0 && ', '}
                                <Link href={`/clients/${c.id}/care`} className="underline-offset-2 hover:underline">
                                    {c.name}
                                </Link>
                            </span>
                        ))}
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
