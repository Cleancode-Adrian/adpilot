import { CareProgress } from '@/components/adpilot/care-task-row';
import { EmptyState } from '@/components/adpilot/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type CareSummary } from '@/types/adpilot';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlarmClock,
    Archive,
    Briefcase,
    FlaskConical,
    KeyRound,
    ListChecks,
    LogIn,
    Megaphone,
    Phone,
    PlusCircle,
    Search,
    ShieldCheck,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface Row {
    id: number;
    name: string;
    website_url: string;
    admin_url: string | null;
    industry: string | null;
    has_ads: boolean;
    has_care: boolean;
    has_general: boolean;
    contact_name: string | null;
    contact_email: string | null;
    contact_phone: string | null;
    is_demo: boolean;
    archived_at: string | null;
    notes_excerpt: string | null;
    credentials_count: number;
    open_count: number;
    care: CareSummary | null;
}

type Filter = 'all' | 'general' | 'care' | 'ads';

function ClientCard({ c, isAdmin }: { c: Row; isAdmin: boolean }) {
    const left = c.care ? c.care.total - c.care.done : 0;
    return (
        <div className="bg-card flex flex-col rounded-xl border">
            <Link href={`/clients/${c.id}`} className="hover:bg-muted/40 block rounded-t-xl px-4 pt-4 pb-3">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 font-semibold">
                            <span className="truncate">{c.name}</span>
                            {c.is_demo && <FlaskConical className="text-muted-foreground size-3.5 shrink-0" aria-label="dane demo" />}
                        </div>
                        <div className="text-muted-foreground truncate text-xs">{c.website_url.replace(/^https?:\/\//, '')}</div>
                    </div>
                    <div className="flex shrink-0 gap-1">
                        {c.has_general && (
                            <span title="Obsługa ogólna" className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]">
                                <Briefcase className="size-3" /> obsługa
                            </span>
                        )}
                        {c.has_care && (
                            <span title="Opieka nad stroną" className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]">
                                <ShieldCheck className="size-3" /> opieka
                            </span>
                        )}
                        {c.has_ads && (
                            <span title="Reklamy" className="bg-muted inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px]">
                                <Megaphone className="size-3" /> reklamy
                            </span>
                        )}
                    </div>
                </div>

                <div className="mt-3 grid gap-1.5 text-xs">
                    {c.care && c.care.total > 0 && (
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-muted-foreground">Zadania w tym miesiącu</span>
                            <CareProgress done={c.care.done} total={c.care.total} />
                        </div>
                    )}
                    {c.care && c.care.overdue > 0 && (
                        <div className="inline-flex items-center gap-1 font-medium text-[#b42f2f] dark:text-[#f08080]">
                            <AlarmClock className="size-3.5" /> {c.care.overdue} po terminie
                        </div>
                    )}
                    {c.care && c.care.total > 0 && left === 0 && c.care.overdue === 0 && (
                        <div className="text-muted-foreground">Miesiąc zamknięty ✓</div>
                    )}
                    {c.has_care && (!c.care || c.care.total === 0) && (
                        <div className="text-muted-foreground">Brak planu opieki – ustaw w karcie klienta</div>
                    )}
                    {c.has_ads && c.open_count > 0 && (
                        <div className="inline-flex items-center gap-1">
                            <ListChecks className="size-3.5" /> {c.open_count} zadań reklamowych
                        </div>
                    )}
                    {c.notes_excerpt && <p className="text-muted-foreground mt-1 line-clamp-2 whitespace-pre-line">{c.notes_excerpt}</p>}
                </div>
            </Link>

            <div className="mt-auto flex flex-wrap items-center gap-1 border-t px-2 py-1.5">
                {c.admin_url && (
                    <Button variant="ghost" size="sm" asChild>
                        <a href={c.admin_url} target="_blank" rel="noreferrer noopener" title={c.admin_url}>
                            <LogIn className="size-4" /> Panel strony
                        </a>
                    </Button>
                )}
                {isAdmin && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/clients/${c.id}/credentials`}>
                            <KeyRound className="size-4" /> Dostępy{c.credentials_count ? ` (${c.credentials_count})` : ''}
                        </Link>
                    </Button>
                )}
                {c.contact_phone && (
                    <Button variant="ghost" size="sm" asChild>
                        <a href={`tel:${c.contact_phone.replace(/\s/g, '')}`} title={c.contact_name ?? undefined}>
                            <Phone className="size-4" /> {c.contact_phone}
                        </a>
                    </Button>
                )}
            </div>
        </div>
    );
}

export default function ClientsIndex({ clients, showArchived, archivedCount }: { clients: Row[]; showArchived: boolean; archivedCount: number }) {
    const { admin } = usePage<SharedData>().props;
    const [q, setQ] = useState('');
    const [filter, setFilter] = useState<Filter>('all');

    const list = useMemo(() => {
        const s = q.trim().toLowerCase();
        return clients.filter(
            (c) =>
                (filter === 'all' || (filter === 'general' ? c.has_general : filter === 'care' ? c.has_care : c.has_ads)) &&
                (!s || [c.name, c.website_url, c.contact_name, c.contact_email, c.industry].some((v) => v?.toLowerCase().includes(s))),
        );
    }, [clients, q, filter]);

    const overdue = clients.reduce((n, c) => n + (c.care?.overdue ?? 0), 0);

    return (
        <AppLayout breadcrumbs={[{ title: 'Klienci', href: '/clients' }]}>
            <Head title="Klienci" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{showArchived ? 'Klienci w archiwum' : 'Klienci'}</h1>
                        <p className="text-muted-foreground text-sm">
                            {clients.length} {clients.length === 1 ? 'klient' : 'klientów'}
                            {overdue > 0 && ` · ${overdue} zadań po terminie`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {(archivedCount > 0 || showArchived) && (
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={showArchived ? '/clients' : '/clients?archived=1'}>
                                    <Archive className="size-4" /> {showArchived ? 'Aktywni' : `Archiwum (${archivedCount})`}
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

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-56 flex-1 sm:max-w-sm">
                        <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Szukaj: nazwa, strona, osoba…" className="pl-8" />
                    </div>
                    <div className="flex rounded-md border p-0.5">
                        {(
                            [
                                ['all', 'Wszyscy'],
                                ['general', 'Obsługa'],
                                ['care', 'Opieka'],
                                ['ads', 'Reklamy'],
                            ] as const
                        ).map(([k, label]) => (
                            <Button
                                key={k}
                                variant={filter === k ? 'secondary' : 'ghost'}
                                size="sm"
                                className={cn('h-8')}
                                onClick={() => setFilter(k)}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                </div>

                {clients.length === 0 ? (
                    <EmptyState title={showArchived ? 'Archiwum jest puste' : 'Nie masz jeszcze klientów'}>
                        {!showArchived && (
                            <Link href="/clients/create" className="underline">
                                Dodaj pierwszego klienta
                            </Link>
                        )}
                    </EmptyState>
                ) : list.length === 0 ? (
                    <EmptyState title="Brak klientów dla tego wyszukiwania" />
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {list.map((c) => (
                            <ClientCard key={c.id} c={c} isAdmin={!!admin} />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
