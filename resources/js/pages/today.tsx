import { CareTaskRow, formatMinutes, localDate } from '@/components/adpilot/care-task-row';
import { type Followup, FollowupList } from '@/components/adpilot/followups';
import { NetGross, Section } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { date } from '@/lib/format';
import { type SharedData } from '@/types';
import { type CareTask } from '@/types/adpilot';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlarmClock, BarChart3, CheckCircle2, Globe, Inbox, Lock, PlusCircle, ShieldAlert, Wallet } from 'lucide-react';

interface Urgent {
    kind: 'down' | 'ssl' | 'wp' | 'ads';
    client: { id: number; name: string };
    text: string;
    url: string;
}
interface Money {
    subscriptions_due: number;
    extra_open: number;
    renewals: { id: number; name: string; client: string; client_id: number; next_date: string; days_left: number | null }[];
}

const URGENT_ICON = { down: ShieldAlert, ssl: Lock, wp: ShieldAlert, ads: BarChart3 };

function greeting() {
    const h = new Date().getHours();
    return h < 12 ? 'Dzień dobry' : h < 18 ? 'Cześć' : 'Dobry wieczór';
}

export default function Today({
    urgent,
    tasks,
    upcoming,
    requests,
    followups,
    doneToday,
    minutesToday,
    money,
}: {
    urgent: Urgent[];
    tasks: CareTask[];
    upcoming: CareTask[];
    requests: number;
    followups: Followup[];
    doneToday: number;
    minutesToday: number;
    money?: Money;
}) {
    const { auth } = usePage<SharedData>().props;
    const overdue = tasks.filter((t) => t.due_date.slice(0, 10) < localDate()).length;
    const toInvoice = money ? money.subscriptions_due + money.extra_open : 0;

    return (
        <AppLayout breadcrumbs={[{ title: 'Dziś', href: '/today' }]}>
            <Head title="Dziś" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">
                            {greeting()}, {auth.user.name.split(' ')[0]}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {new Date().toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long' })} · do zrobienia:{' '}
                            {tasks.length}
                            {overdue > 0 && ` (${overdue} po terminie)`}
                            {doneToday > 0 && ` · dziś zrobione: ${doneToday}`}
                            {minutesToday > 0 && ` (${formatMinutes(minutesToday)})`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {requests > 0 && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/requests">
                                    <Inbox className="size-4" /> Nowe zgłoszenia ({requests})
                                </Link>
                            </Button>
                        )}
                        <Button size="sm" asChild>
                            <Link href="/clients/create">
                                <PlusCircle className="size-4" /> Nowy klient
                            </Link>
                        </Button>
                    </div>
                </div>

                {urgent.length > 0 && (
                    <div className="rounded-xl border border-[#b42f2f]/40 bg-[#b42f2f]/5 p-3">
                        <div className="mb-1 text-sm font-semibold text-[#b42f2f] dark:text-[#f08080]">Pilne</div>
                        <ul className="grid gap-1 text-sm">
                            {urgent.map((u, i) => {
                                const Icon = URGENT_ICON[u.kind];
                                return (
                                    <li key={i}>
                                        <Link href={u.url} className="inline-flex items-center gap-2 hover:underline">
                                            <Icon className="size-4 shrink-0" />
                                            <strong>{u.client.name}</strong> – {u.text}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                <div className="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                    <div className="flex min-w-0 flex-col gap-4">
                        <Section
                            title="Na dziś i zaległe"
                            description="Zadania ze wszystkich klientów. ▶ włącza stoper – czas doliczy się do zadania."
                        >
                            {tasks.length === 0 ? (
                                <p className="text-muted-foreground inline-flex items-center gap-2 text-sm">
                                    <CheckCircle2 className="size-4 text-[#0a7d0a]" /> Wszystko zrobione na dziś.
                                </p>
                            ) : (
                                <ul className="grid gap-2">
                                    {tasks.map((t) => (
                                        <CareTaskRow key={t.id} task={t} showClient />
                                    ))}
                                </ul>
                            )}
                        </Section>

                        {upcoming.length > 0 && (
                            <Section title="Najbliższe 7 dni">
                                <ul className="grid gap-2">
                                    {upcoming.map((t) => (
                                        <CareTaskRow key={t.id} task={t} showClient />
                                    ))}
                                </ul>
                            </Section>
                        )}
                    </div>

                    <div className="flex min-w-0 flex-col gap-4">
                        <Section
                            title={`Do kontaktu${followups.length ? ` (${followups.length})` : ''}`}
                            description="Oferty bez odpowiedzi i audyty potencjalnych klientów."
                        >
                            <FollowupList items={followups} />
                        </Section>

                        {money && (
                            <Section
                                title="Pieniądze"
                                actions={
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link href="/billing">Rozliczenia →</Link>
                                    </Button>
                                }
                            >
                                <div className="grid gap-3 text-sm">
                                    <Link href="/billing" className="hover:bg-muted/40 flex items-center justify-between gap-3 rounded-lg border p-3">
                                        <span className="inline-flex items-center gap-2">
                                            <Wallet className="text-muted-foreground size-4" /> Do zafakturowania
                                        </span>
                                        <NetGross value={toInvoice} className="font-semibold" />
                                    </Link>
                                    {money.renewals.length > 0 && (
                                        <div className="rounded-lg border p-3">
                                            <div className="mb-1 inline-flex items-center gap-2 font-medium">
                                                <Globe className="text-muted-foreground size-4" /> Odnowienia w 14 dni
                                            </div>
                                            <ul className="grid gap-0.5">
                                                {money.renewals.map((r) => (
                                                    <li key={r.id} className="flex justify-between gap-2">
                                                        <Link href={`/clients/${r.client_id}/billing`} className="truncate hover:underline">
                                                            {r.name} <span className="text-muted-foreground">· {r.client}</span>
                                                        </Link>
                                                        <span
                                                            className={
                                                                (r.days_left ?? 0) < 0
                                                                    ? 'shrink-0 font-semibold text-[#b42f2f]'
                                                                    : 'text-muted-foreground shrink-0'
                                                            }
                                                        >
                                                            <AlarmClock className="mr-1 inline size-3" />
                                                            {date(r.next_date)}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            </Section>
                        )}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
